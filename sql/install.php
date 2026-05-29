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

    // Auto-assign full rights to Super-Admin profile (id=4).
    // rights = READ|UPDATE|CREATE|DELETE|PURGE = 31.
    // updateOrInsert guarantees this even when addProfileRights() already
    // created the row with rights=0.
    $superAdminId = 4;
    foreach ($all_rights as $right) {
        $DB->updateOrInsert(
            'glpi_profilerights',
            ['rights' => 31],
            ['profiles_id' => $superAdminId, 'name' => $right]
        );
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

    $system_name = 'veiculofrota';

    // Check if the Asset Definition already exists
    $existing = $DB->request([
        'FROM' => 'glpi_assets_assetdefinitions',
        'WHERE' => ['system_name' => $system_name]
    ]);

    // GLPI 11 format: capacities must be objects with "name" and "config" keys.
    $capacities = [
        ['name' => 'Glpi\\Asset\\Capacity\\HasInfocomCapacity',             'config' => []],
        ['name' => 'Glpi\\Asset\\Capacity\\HasDocumentsCapacity',           'config' => []],
        ['name' => 'Glpi\\Asset\\Capacity\\AllowedInGlobalSearchCapacity',  'config' => []],
        ['name' => 'Glpi\\Asset\\Capacity\\HasContractsCapacity',           'config' => []],
        ['name' => 'Glpi\\Asset\\Capacity\\IsReservableCapacity',           'config' => []],
    ];

    // GLPI 11 format: fields_display must be objects with "key", "order", and "field_options".
    $fieldsDisplay = [
        ['key' => 'name',                   'order' => 0,  'field_options' => []],
        ['key' => 'template_name',          'order' => 1,  'field_options' => []],
        ['key' => 'states_id',              'order' => 2,  'field_options' => []],
        ['key' => 'locations_id',           'order' => 3,  'field_options' => []],
        ['key' => 'assets_assettypes_id',   'order' => 4,  'field_options' => []],
        ['key' => 'manufacturers_id',       'order' => 5,  'field_options' => []],
        ['key' => 'groups_id_tech',         'order' => 6,  'field_options' => []],
        ['key' => 'assets_assetmodels_id',  'order' => 7,  'field_options' => []],
        ['key' => 'users_id',               'order' => 8,  'field_options' => []],
        ['key' => 'groups_id',              'order' => 9,  'field_options' => []],
        ['key' => 'comment',                'order' => 10, 'field_options' => []],
        ['key' => 'custom_placa',           'order' => 11, 'field_options' => ['full_width' => '0', 'required' => '1', 'readonly' => '', 'hidden' => '']],
        ['key' => 'custom_km_inicial',      'order' => 12, 'field_options' => ['full_width' => '0', 'required' => '0', 'readonly' => '', 'hidden' => '']],
    ];

    $label = __('Veículo-frota', 'fleetbooking');

    $assetDefId = 0;
    if ($existing_row = $existing->current()) {
        $assetDefId = $existing_row['id'];

        $DB->update('glpi_assets_assetdefinitions', [
            'label'         => $label,
            'icon'          => 'ti-car-garage',
            'is_active'     => 1,
            'capacities'    => json_encode($capacities),
            'fields_display' => json_encode($fieldsDisplay),
        ], ['id' => $assetDefId]);
    } else {
        $DB->insert('glpi_assets_assetdefinitions', [
            'system_name'   => $system_name,
            'label'         => $label,
            'icon'          => 'ti-car-garage',
            'is_active'     => 1,
            'comment'       => __('Created automatically by FleetBooking plugin', 'fleetbooking'),
            'capacities'    => json_encode($capacities),
            'profiles'      => '[]',
            'translations'  => '{}',
            'fields_display' => json_encode($fieldsDisplay),
        ]);

        $assetDefId = (int) $DB->insert_id;
    }

    if ($assetDefId > 0 && class_exists('Glpi\Asset\CustomFieldDefinition')) {
        // Remove stale custom fields from previous versions (different system_name)
        $DB->delete('glpi_assets_customfielddefinitions', [
            'assets_assetdefinitions_id' => $assetDefId,
            'OR' => [
                ['system_name' => 'initial_mileage'],
                ['system_name' => 'license_plate'],
            ],
        ]);

        // Check if custom field KM Inicial exists
        $existingKm = $DB->request([
            'FROM'  => 'glpi_assets_customfielddefinitions',
            'WHERE' => [
                'assets_assetdefinitions_id' => $assetDefId,
                'system_name'               => 'km_inicial',
            ],
        ]);

        if (count($existingKm) == 0) {
            // GLPI 11: default_value must be JSON-encoded (post_getFromDB calls json_decode on it).
            // translations must be a JSON array '[]', not an object '{}'.
            $DB->insert('glpi_assets_customfielddefinitions', [
                'assets_assetdefinitions_id' => $assetDefId,
                'system_name'   => 'km_inicial',
                'label'         => __('KM Inicial', 'fleetbooking'),
                'type'          => 'Glpi\\Asset\\CustomFieldType\\NumberType',
                'default_value' => json_encode(0),
                'field_options' => json_encode(['min' => 0, 'step' => 1]),
                'translations'  => '[]',
            ]);
        }

        // Check if custom field Placa exists
        $existingPlaca = $DB->request([
            'FROM'  => 'glpi_assets_customfielddefinitions',
            'WHERE' => [
                'assets_assetdefinitions_id' => $assetDefId,
                'system_name'               => 'placa',
            ],
        ]);

        if (count($existingPlaca) == 0) {
            $DB->insert('glpi_assets_customfielddefinitions', [
                'assets_assetdefinitions_id' => $assetDefId,
                'system_name'   => 'placa',
                'label'         => __('Placa', 'fleetbooking'),
                'type'          => 'Glpi\\Asset\\CustomFieldType\\StringType',
                'default_value' => json_encode(''),
                'field_options' => '{}',
                'translations'  => '[]',
            ]);
        }
    }

    // Auto initialize default config for entity 0 pointing to this custom asset
    $configTable = 'glpi_plugin_fleetbooking_configs';
    if ($DB->tableExists($configTable)) {
        $existingConfig = $DB->request([
            'FROM' => $configTable,
            'WHERE' => ['entities_id' => 0]
        ])->current();

        $vehicleItemtype = 'Glpi\CustomAsset\VeiculofrotaAsset';

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

