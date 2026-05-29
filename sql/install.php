<?php

function plugin_fleetbooking_install_db()
{
    global $DB;

    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_fleetbooking_requests` (
        `id`                   int unsigned      NOT NULL AUTO_INCREMENT,
        `entities_id`          int unsigned      NOT NULL DEFAULT 0,
        `requester_users_id`   int unsigned      NOT NULL DEFAULT 0,
        `requester_groups_id`  int unsigned      NOT NULL DEFAULT 0,
        `manager_users_id`     int unsigned      DEFAULT NULL,
        `itemtype`             varchar(255)      NOT NULL DEFAULT '',
        `items_id`             int unsigned      NOT NULL DEFAULT 0,
        `start_datetime`       timestamp         NULL DEFAULT NULL,
        `end_datetime`         timestamp         NULL DEFAULT NULL,
        `reason`               text              DEFAULT NULL,
        `payload_json`         longtext          DEFAULT NULL,
        `status`               varchar(32)       NOT NULL DEFAULT 'pending',
        `tickets_id`           int unsigned      DEFAULT NULL,
        `reservations_id`      int unsigned      DEFAULT NULL,
        `decision_users_id`    int unsigned      DEFAULT NULL,
        `decision_comment`     text              DEFAULT NULL,
        `decision_date`        timestamp         NULL DEFAULT NULL,
        `date_creation`        timestamp         NULL DEFAULT CURRENT_TIMESTAMP,
        `date_mod`             timestamp         NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_entities`    (`entities_id`),
        KEY `idx_requester`   (`requester_users_id`),
        KEY `idx_manager`     (`manager_users_id`),
        KEY `idx_status`      (`status`),
        KEY `idx_ticket`      (`tickets_id`)
    ) $charset");

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_fleetbooking_groupmanagers` (
        `id`                int unsigned  NOT NULL AUTO_INCREMENT,
        `groups_id`         int unsigned  NOT NULL DEFAULT 0,
        `managers_users_id` int unsigned  NOT NULL DEFAULT 0,
        `date_creation`     timestamp     NULL DEFAULT CURRENT_TIMESTAMP,
        `date_mod`          timestamp     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_group` (`groups_id`),
        KEY `idx_manager`   (`managers_users_id`)
    ) $charset");

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_fleetbooking_holidays` (
        `id`            int unsigned  NOT NULL AUTO_INCREMENT,
        `entities_id`   int unsigned  NOT NULL DEFAULT 0,
        `holiday_date`  date          NOT NULL,
        `description`   varchar(255)  DEFAULT NULL,
        `date_creation` timestamp     NULL DEFAULT CURRENT_TIMESTAMP,
        `date_mod`      timestamp     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_holiday` (`entities_id`, `holiday_date`)
    ) $charset");

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_fleetbooking_configs` (
        `id`                            int unsigned  NOT NULL AUTO_INCREMENT,
        `entities_id`                   int unsigned  NOT NULL DEFAULT 0,
        `default_tickets_entities_id`   int unsigned  NOT NULL DEFAULT 0,
        `itilcategories_id`             int unsigned  DEFAULT NULL,
        `vehicle_itemtype`              varchar(255)  DEFAULT NULL,
        `workday_start`                 time          NOT NULL DEFAULT '07:00:00',
        `workday_end`                   time          NOT NULL DEFAULT '18:00:00',
        `auto_close_ticket_on_decision` tinyint(1)    NOT NULL DEFAULT 1,
        `show_pending_on_calendar`      tinyint(1)    NOT NULL DEFAULT 1,
        `approved_color`                varchar(16)   NOT NULL DEFAULT '#2ecc71',
        `pending_color`                 varchar(16)   NOT NULL DEFAULT '#f1c40f',
        `date_creation`                 timestamp     NULL DEFAULT CURRENT_TIMESTAMP,
        `date_mod`                      timestamp     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_entity` (`entities_id`)
    ) $charset");

    // Dynamic schema update for existing tables
    if ($DB->tableExists('glpi_plugin_fleetbooking_configs')) {
        if (!$DB->fieldExists('glpi_plugin_fleetbooking_configs', 'default_tickets_entities_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD `default_tickets_entities_id` int unsigned NOT NULL DEFAULT 0 AFTER `entities_id`");
        }
    }

    // Check each profile right individually to prevent Duplicate Entry DB Exception during upgrade
    $all_rights = ['fleetbooking_read', 'fleetbooking_request', 'fleetbooking_approve', 'fleetbooking_admin'];
    foreach ($all_rights as $right) {
        $exists = $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_profilerights',
            'WHERE' => ['name' => $right]
        ])->current()['c'] > 0;
        if (!$exists) {
            ProfileRight::addProfileRights([$right]);
        }
    }

    plugin_fleetbooking_create_vehicle_asset();

    return true;
}

/**
 * Automates the creation of the custom asset type "Vehicle-Fleet" and its custom fields.
 *
 * @return void
 */
function plugin_fleetbooking_create_vehicle_asset()
{
    global $DB;

    if (!class_exists('Glpi\Asset\AssetDefinition')) {
        return;
    }

    if (!$DB->tableExists('glpi_assets_assetdefinitions')) {
        return;
    }

    $system_name = 'VehicleFleet';

    // Check if the Asset Definition already exists
    $existing = $DB->request([
        'FROM' => 'glpi_assets_assetdefinitions',
        'WHERE' => ['system_name' => $system_name]
    ]);

    $assetDefId = 0;
    if ($existing_row = $existing->current()) {
        $assetDefId = $existing_row['id'];
    } else {
        // Create the Asset Definition
        $assetDef = new \Glpi\Asset\AssetDefinition();
        $fieldsDisplay = [
            'left' => [
                'name',
                'states_id',
                'assettypes_id',
                'groups_id_tech',
                'users_id',
                'comment',
                'custom_initial_mileage'
            ],
            'right' => [
                'is_template',
                'locations_id',
                'manufacturers_id',
                'models_id',
                'groups_id',
                'custom_license_plate'
            ]
        ];

        $input = [
            'system_name' => $system_name,
            'icon' => 'ti-car',
            'is_active' => 1,
            'comment' => __('Created automatically by FleetBooking plugin', 'fleetbooking'),
            'capacities' => json_encode([
                'Glpi\Asset\Capacity\HasInfocomCapacity',
                'Glpi\Asset\Capacity\HasDocumentsCapacity',
                'Glpi\Asset\Capacity\AllowedInGlobalSearchCapacity',
                'Glpi\Asset\Capacity\HasContractsCapacity',
                'Glpi\Asset\Capacity\IsReservableCapacity',
            ]),
            'fields_display' => json_encode($fieldsDisplay),
        ];

        if ($DB->fieldExists('glpi_assets_assetdefinitions', 'label')) {
            $input['label'] = __('Vehicle-Fleet', 'fleetbooking');
        } else {
            $input['name'] = __('Vehicle-Fleet', 'fleetbooking');
        }

        $assetDefId = $assetDef->add($input);
    }

    if ($assetDefId > 0 && class_exists('Glpi\Asset\CustomFieldDefinition')) {
        // Check if custom field Initial Mileage exists
        $existingKm = $DB->request([
            'FROM' => 'glpi_assets_customfielddefinitions',
            'WHERE' => [
                'assets_assetdefinitions_id' => $assetDefId,
                'OR' => [
                    ['system_name' => 'initial_mileage'],
                    ['name' => 'initial_mileage']
                ]
            ]
        ]);

        if (count($existingKm) == 0) {
            $fieldKm = new \Glpi\Asset\CustomFieldDefinition();
            $fieldInput = [
                'assets_assetdefinitions_id' => $assetDefId,
                'type' => 'Glpi\Asset\CustomFieldType\NumberType',
                'default_value' => '0',
                'field_options' => json_encode(['min' => 0, 'step' => 1]),
            ];

            if ($DB->fieldExists('glpi_assets_customfielddefinitions', 'system_name')) {
                $fieldInput['system_name'] = 'initial_mileage';
            } else {
                $fieldInput['name'] = 'initial_mileage';
            }

            if ($DB->fieldExists('glpi_assets_customfielddefinitions', 'label')) {
                $fieldInput['label'] = __('Initial Mileage', 'fleetbooking');
            } else if ($DB->fieldExists('glpi_assets_customfielddefinitions', 'name') && !isset($fieldInput['name'])) {
                $fieldInput['name'] = __('Initial Mileage', 'fleetbooking');
            }

            $fieldKm->add($fieldInput);
        }

        // Check if custom field License Plate exists
        $existingPlaca = $DB->request([
            'FROM' => 'glpi_assets_customfielddefinitions',
            'WHERE' => [
                'assets_assetdefinitions_id' => $assetDefId,
                'OR' => [
                    ['system_name' => 'license_plate'],
                    ['name' => 'license_plate']
                ]
            ]
        ]);

        if (count($existingPlaca) == 0) {
            $fieldPlaca = new \Glpi\Asset\CustomFieldDefinition();
            $fieldInput = [
                'assets_assetdefinitions_id' => $assetDefId,
                'type' => 'Glpi\Asset\CustomFieldType\StringType',
                'default_value' => '',
                'field_options' => json_encode([]),
            ];

            if ($DB->fieldExists('glpi_assets_customfielddefinitions', 'system_name')) {
                $fieldInput['system_name'] = 'license_plate';
            } else {
                $fieldInput['name'] = 'license_plate';
            }

            if ($DB->fieldExists('glpi_assets_customfielddefinitions', 'label')) {
                $fieldInput['label'] = __('License Plate', 'fleetbooking');
            } else if ($DB->fieldExists('glpi_assets_customfielddefinitions', 'name') && !isset($fieldInput['name'])) {
                $fieldInput['name'] = __('License Plate', 'fleetbooking');
            }

            $fieldPlaca->add($fieldInput);
        }
    }

    // Auto initialize default config for entity 0 pointing to this custom asset
    $configTable = 'glpi_plugin_fleetbooking_configs';
    if ($DB->tableExists($configTable)) {
        $existingConfig = $DB->request([
            'FROM' => $configTable,
            'WHERE' => ['entities_id' => 0]
        ])->current();

        $vehicleItemtype = 'Glpi\CustomAsset\VehicleFleetAsset';

        if ($existingConfig) {
            if (empty($existingConfig['vehicle_itemtype'])) {
                $DB->update($configTable, [
                    'vehicle_itemtype' => $vehicleItemtype
                ], ['id' => $existingConfig['id']]);
            }
        } else {
            $DB->insert($configTable, [
                'entities_id' => 0,
                'default_tickets_entities_id' => 0,
                'itilcategories_id' => 0,
                'vehicle_itemtype' => $vehicleItemtype,
                'workday_start' => '07:00:00',
                'workday_end' => '18:00:00',
                'auto_close_ticket_on_decision' => 1,
                'show_pending_on_calendar' => 1,
                'approved_color' => '#2ecc71',
                'pending_color' => '#f1c40f',
            ]);
        }
    }
}

