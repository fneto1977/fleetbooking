<?php

namespace GlpiPlugin\Fleetbooking;

use CommonDBTM;

/**
 * Class Config
 * Plugin configuration logic
 */
class Config extends CommonDBTM
{

    static $rightname = 'fleetbooking_admin';

    /** @var bool Prevents redundant schema checks within a single request */
    private static $schemaVerified = false;

    static function getTypeName($nb = 0): string
    {
        return __('Fleet Booking Configuration', 'fleetbooking');
    }

    public static function getIcon(): string
    {
        return 'ti ti-car';
    }

    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Entity') {
            if (\Session::haveRight('fleetbooking_admin', READ)) {
                if (method_exists(__CLASS__, 'createTabEntry')) {
                    return self::createTabEntry(self::getTypeName(), 0, $item->getType(), self::getIcon());
                }
                return self::getTypeName();
            }
        }
        return '';
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === 'Entity') {
            $config = new self();
            $config->showConfigForm($item->getID());
            return true;
        }
        return false;
    }

    /**
     * Safety net: ensure the config table has the expected schema and
     * a default root-entity row.  Called explicitly during install/upgrade,
     * never from hot paths.  Executes at most once per request.
     *
     * @return void
     */
    public static function ensureSchemaIntegrity(): void
    {
        if (self::$schemaVerified) {
            return;
        }
        self::$schemaVerified = true;

        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return;
        }

        // Ensure default_tickets_entities_id exists
        if (!$DB->fieldExists(self::getTable(), 'default_tickets_entities_id')) {
            try {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD COLUMN `default_tickets_entities_id` int unsigned NOT NULL DEFAULT 0 AFTER `entities_id`");
            } catch (\Exception $e) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Schema migration (default_tickets_entities_id) failed: %s. Grant ALTER TABLE privileges to the GLPI database user or apply the column manually.', 'fleetbooking'),
                    str_replace(["\n", "\r", "\t"], ' ', $e->getMessage())
                ));
            }
        }

        // Ensure observer_groups_id exists (gatehouse/observer group for approved tickets)
        if (!$DB->fieldExists(self::getTable(), 'observer_groups_id')) {
            try {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD COLUMN `observer_groups_id` int unsigned NOT NULL DEFAULT 0 AFTER `default_tickets_entities_id`");
            } catch (\Exception $e) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Schema migration (observer_groups_id) failed: %s. Grant ALTER TABLE privileges to the GLPI database user or apply the column manually.', 'fleetbooking'),
                    str_replace(["\n", "\r", "\t"], ' ', $e->getMessage())
                ));
            }
        }

        // Ensure readonly_profile_id exists (profile that gets auto-granted vehicle asset READ)
        if (!$DB->fieldExists(self::getTable(), 'readonly_profile_id')) {
            try {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD COLUMN `readonly_profile_id` int unsigned NOT NULL DEFAULT 0 AFTER `observer_groups_id`");
            } catch (\Exception $e) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Schema migration (readonly_profile_id) failed: %s. Grant ALTER TABLE privileges to the GLPI database user or apply the column manually.', 'fleetbooking'),
                    str_replace(["\n", "\r", "\t"], ' ', $e->getMessage())
                ));
            }
        }

        // Ensure reserved_color exists (color for native GLPI reservations on calendar)
        if (!$DB->fieldExists(self::getTable(), 'reserved_color')) {
            try {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD COLUMN `reserved_color` varchar(16) NOT NULL DEFAULT '#8e44ad' AFTER `pending_color`");
            } catch (\Exception $e) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Schema migration (reserved_color) failed: %s. Grant ALTER TABLE privileges to the GLPI database user or apply the column manually.', 'fleetbooking'),
                    str_replace(["\n", "\r", "\t"], ' ', $e->getMessage())
                ));
            }
        }

        // Ensure policy_document_path exists
        if (!$DB->fieldExists(self::getTable(), 'policy_document_path')) {
            try {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD COLUMN `policy_document_path` varchar(255) DEFAULT NULL AFTER `reserved_color`");
            } catch (\Exception $e) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Schema migration (policy_document_path) failed: %s', 'fleetbooking'),
                    str_replace(["\n", "\r", "\t"], ' ', $e->getMessage())
                ));
            }
        }

        // Ensure term_template_path exists
        if (!$DB->fieldExists(self::getTable(), 'term_template_path')) {
            try {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD COLUMN `term_template_path` varchar(255) DEFAULT NULL AFTER `policy_document_path`");
            } catch (\Exception $e) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Schema migration (term_template_path) failed: %s', 'fleetbooking'),
                    str_replace(["\n", "\r", "\t"], ' ', $e->getMessage())
                ));
            }
        }

        // Ensure the root entity (0) has a configuration row
        $has_root = $DB->request([
            'COUNT' => 'c',
            'FROM' => self::getTable(),
            'WHERE' => ['entities_id' => 0]
        ])->current();
        if (!$has_root || (int) $has_root['c'] === 0) {
            // Find if any other entity configuration row exists to clone properties from
            $existing_configs = $DB->request([
                'FROM' => self::getTable(),
                'LIMIT' => 1
            ]);
            $clone_from = null;
            foreach ($existing_configs as $row) {
                $clone_from = $row;
                break;
            }

            // Use the custom asset class only when the optional plugin is active;
            // otherwise leave the field empty so the admin is forced to choose a
            // valid itemtype before the plugin can be used.
            $vehicleItemtype = 'Glpi\CustomAsset\VeiculofrotaAsset';
            if (!class_exists($vehicleItemtype)) {
                $vehicleItemtype = '';
            }
            $DB->insert(self::getTable(), [
                'entities_id' => 0,
                'default_tickets_entities_id' => 0,
                'observer_groups_id' => $clone_from ? ($clone_from['observer_groups_id'] ?? 0) : 0,
                'readonly_profile_id' => $clone_from ? ($clone_from['readonly_profile_id'] ?? 0) : 0,
                'itilcategories_id' => $clone_from ? ($clone_from['itilcategories_id'] ?? 0) : 0,
                'vehicle_itemtype' => $clone_from ? ($clone_from['vehicle_itemtype'] ?? $vehicleItemtype) : $vehicleItemtype,
                'workday_start' => $clone_from ? ($clone_from['workday_start'] ?? '07:00:00') : '07:00:00',
                'workday_end' => $clone_from ? ($clone_from['workday_end'] ?? '18:00:00') : '18:00:00',
                'auto_close_ticket_on_decision' => $clone_from ? ($clone_from['auto_close_ticket_on_decision'] ?? 1) : 1,
                'show_pending_on_calendar' => $clone_from ? ($clone_from['show_pending_on_calendar'] ?? 1) : 1,
                'approved_color' => $clone_from ? ($clone_from['approved_color'] ?? '#2ecc71') : '#2ecc71',
                'pending_color' => $clone_from ? ($clone_from['pending_color'] ?? '#f1c40f') : '#f1c40f',
                'reserved_color' => $clone_from ? ($clone_from['reserved_color'] ?? '#8e44ad') : '#8e44ad',
                'policy_document_path' => $clone_from ? ($clone_from['policy_document_path'] ?? null) : null,
                'term_template_path' => $clone_from ? ($clone_from['term_template_path'] ?? null) : null,
            ]);
        }
    }

    /**
     * Retrieve configuration for a specific entity
     */
    public static function getForEntity(int $entities_id): array
    {
        $config = new self();

        $ancestors = \getAncestorsOf('glpi_entities', $entities_id);
        if (!is_array($ancestors)) {
            $ancestors = [];
        }
        $ancestors[] = $entities_id;
        $ancestors = array_reverse($ancestors); // Current first, then parents

        foreach ($ancestors as $id) {
            if ($config->getFromDBByCrit(['entities_id' => $id])) {
                $fields = $config->fields;
                if (!isset($fields['default_tickets_entities_id'])) {
                    $fields['default_tickets_entities_id'] = 0;
                }
                if (!isset($fields['readonly_profile_id'])) {
                    $fields['readonly_profile_id'] = 0;
                }
                if (!isset($fields['observer_groups_id'])) {
                    $fields['observer_groups_id'] = 0;
                }
                if (!isset($fields['policy_document_path'])) {
                    $fields['policy_document_path'] = null;
                }
                if (!isset($fields['term_template_path'])) {
                    $fields['term_template_path'] = null;
                }
                return $fields;
            }
        }

        return [
            'entities_id' => $entities_id,
            'default_tickets_entities_id' => 0,
            'observer_groups_id' => 0,
            'readonly_profile_id' => 0,
            'itilcategories_id' => 0,
            'vehicle_itemtype' => '',
            'workday_start' => '07:00:00',
            'workday_end' => '18:00:00',
            'auto_close_ticket_on_decision' => 1,
            'show_pending_on_calendar' => 1,
            'approved_color' => '#2ecc71',
            'pending_color' => '#f1c40f',
            'reserved_color' => '#8e44ad',
            'policy_document_path' => null,
            'term_template_path' => null,
        ];
    }

    /**
     * Get the directory where custom fleet documents are stored.
     */
    public static function getDocDir(): string
    {
        $dir = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR . '/fleetbooking/docs'
            : (defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR . '/_plugins/fleetbooking/docs' : dirname(__DIR__) . '/public/docs');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Get URL for viewing the active Vehicle Usage Policy.
     */
    public static function getPolicyDocumentUrl(int $entities_id): string
    {
        return \Plugin::getWebDir('fleetbooking', true) . '/front/document.send.php?doc=policy&entities_id=' . $entities_id;
    }

    /**
     * Get URL for viewing the active Responsibility Term template/model.
     */
    public static function getTermTemplateUrl(int $entities_id): string
    {
        return \Plugin::getWebDir('fleetbooking', true) . '/front/document.send.php?doc=term_template&entities_id=' . $entities_id;
    }

    /**
     * Renders the configuration form for use inside an Entity tab.
     */
    public function showConfigForm(int $entities_id): void
    {
        global $CFG_GLPI, $DB;

        self::ensureSchemaIntegrity();

        $current = self::getForEntity($entities_id);
        $action = \Plugin::getWebDir('fleetbooking') . '/front/config.form.php';

        echo "<form method='post' action='" . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . "' enctype='multipart/form-data'>";
        echo "<input type='hidden' name='entities_id' value='" . (int) $entities_id . "'>";

        if (isset($current['id']) && (int) $current['entities_id'] === (int) $entities_id) {
            echo "<input type='hidden' name='id' value='" . (int) $current['id'] . "'>";
        }

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . self::getTypeName() . "</th></tr>";

        // Category
        echo "<tr class='tab_bg_1'><td>" . __('Default Ticket Category', 'fleetbooking') . "</td><td>";
        \ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => $current['itilcategories_id']]);
        echo "</td></tr>";

        // Target Entity
        echo "<tr class='tab_bg_1'><td>" . __('Default Entity for Tickets', 'fleetbooking') . "</td><td>";
        \Entity::dropdown(['name' => 'default_tickets_entities_id', 'value' => $current['default_tickets_entities_id'] ?? 0]);
        echo "<div style='color: #d9534f; font-weight: bold; margin-top: 5px; font-size: 0.9em;'>";
        echo "⚠️ " . __('Warning: Changing this entity will automatically migrate all active or pending booking tickets and requests to the newly selected entity.', 'fleetbooking');
        echo "</div>";
        echo "</td></tr>";

        // Observer Group (Gatehouse/Portaria)
        echo "<tr class='tab_bg_1'><td>" . __('Observer Group for Approved Tickets (Gatehouse)', 'fleetbooking') . "</td><td>";
        \Group::dropdown([
            'name'                => 'observer_groups_id',
            'value'               => $current['observer_groups_id'] ?? 0,
            'entity'              => $entities_id,
            'entity_sons'         => true,
            'display_emptychoice' => true,
        ]);
        echo "<div style='color: #555; font-size: 0.9em; margin-top: 4px;'>";
        echo __('This group will be added as Observer on every booking ticket generated by this plugin.', 'fleetbooking');
        echo "</div>";
        echo "</td></tr>";

        // Read-only Profile (Portaria) — vehicle asset READ is auto-granted on save
        echo "<tr class='tab_bg_1'><td>" . __('Read-Only Profile (Portaria)', 'fleetbooking') . "</td><td>";
        \Profile::dropdown([
            'name'                => 'readonly_profile_id',
            'value'               => $current['readonly_profile_id'] ?? 0,
            'display_emptychoice' => true,
        ]);
        echo "<div style='color: #555; font-size: 0.9em; margin-top: 4px;'>";
        echo __('The selected profile will automatically receive read-only access to the configured vehicle asset type (e.g. Veículo-frota) upon saving this configuration.', 'fleetbooking');
        echo "</div>";
        echo "</td></tr>";

        // Vehicle ItemType — query GLPI 11 Asset Definitions
        $asset_types = [];

        // Load custom assets from GLPI 11 Asset Definitions
        $order_col = $DB->fieldExists(\Glpi\Asset\AssetDefinition::getTable(), 'label') ? 'label' : 'name';
        $asset_defs = $DB->request([
            'FROM' => \Glpi\Asset\AssetDefinition::getTable(),
            'WHERE' => ['is_active' => 1],
            'ORDER' => ["$order_col ASC"],
        ]);
        foreach ($asset_defs as $def) {
            try {
                if (class_exists('Glpi\\Asset\\AssetDefinition')) {
                    $ad = new \Glpi\Asset\AssetDefinition();
                    $ad->fields = $def;
                    $classname = $ad->getAssetClassName();
                } else {
                    throw new \RuntimeException('AssetDefinition not found');
                }
            } catch (\Throwable $e) {
                // Fallback: GLPI 11 confirmed class name pattern
                $classname = 'GlpiCustomAsset' . $def['system_name'];
            }
            $label = $def['label'] ?? $def['name'] ?? $def['system_name'];
            $asset_types[$classname] = $label;
        }

        // Also include standard GLPI asset types as options
        foreach ([
            'Computer' => __('Computer', 'fleetbooking'),
            'Phone' => __('Phone', 'fleetbooking'),
            'NetworkEquipment' => __('Network Equipment', 'fleetbooking'),
            'Peripheral' => __('Peripheral', 'fleetbooking'),
            'Printer' => __('Printer', 'fleetbooking'),
        ] as $cls => $lbl) {
            $asset_types[$cls] = $lbl;
        }

        echo "<tr class='tab_bg_1'><td>" . __('Vehicle ItemType', 'fleetbooking') . "</td><td>";
        \Dropdown::showFromArray('vehicle_itemtype', $asset_types, [
            'value' => $current['vehicle_itemtype'] ?? '',
            'display_emptychoice' => true,
        ]);
        echo "</td></tr>";

        // Auto close ticket — sets status to SOLVED (5), allowing requester to confirm closure
        echo "<tr class='tab_bg_1'><td>" . __('Resolver ticket automaticamente na decisão (SOLVED)', 'fleetbooking') . "</td><td>";
        \Dropdown::showYesNo('auto_close_ticket_on_decision', $current['auto_close_ticket_on_decision']);
        echo "</td></tr>";

        // Show pending on calendar
        echo "<tr class='tab_bg_1'><td>" . __('Show pending requests on calendar?', 'fleetbooking') . "</td><td>";
        \Dropdown::showYesNo('show_pending_on_calendar', $current['show_pending_on_calendar']);
        echo "</td></tr>";

        // Colors
        echo "<tr class='tab_bg_1'><td>" . __('Approved Color', 'fleetbooking') . "</td><td>";
        echo "<input type='color' name='approved_color' value='" . htmlspecialchars($current['approved_color'], ENT_QUOTES, 'UTF-8') . "'>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Pending Color', 'fleetbooking') . "</td><td>";
        echo "<input type='color' name='pending_color' value='" . htmlspecialchars($current['pending_color'], ENT_QUOTES, 'UTF-8') . "'>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Reserved Color', 'fleetbooking') . "</td><td>";
        echo "<input type='color' name='reserved_color' value='" . htmlspecialchars($current['reserved_color'] ?? '#8e44ad', ENT_QUOTES, 'UTF-8') . "'>";
        echo "<div style='color:#555; font-size:0.9em; margin-top:4px;'>";
        echo __('Color used for native GLPI reservations of other users on the calendar.', 'fleetbooking');
        echo "</div>";
        echo "</td></tr>";

        // Workday times
        echo "<tr class='tab_bg_1'><td>" . __('Workday Start Time', 'fleetbooking') . "</td><td>";
        echo "<input type='time' name='workday_start' value='" . htmlspecialchars($current['workday_start'], ENT_QUOTES) . "' required>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Workday End Time', 'fleetbooking') . "</td><td>";
        echo "<input type='time' name='workday_end' value='" . htmlspecialchars($current['workday_end'], ENT_QUOTES) . "' required>";
        echo "</td></tr>";

        // Fleet Documents Section
        echo "<tr><th colspan='2'>" . __('Fleet Documents', 'fleetbooking') . "</th></tr>";

        // Policy Document Upload & View
        $policyUrl = self::getPolicyDocumentUrl($entities_id);
        $hasCustomPolicy = !empty($current['policy_document_path']) && file_exists($current['policy_document_path']);
        echo "<tr class='tab_bg_1'><td>" . __('Vehicle Usage Policy', 'fleetbooking') . " (PDF)</td><td>";
        echo "<div style='display:flex;align-items:center;gap:12px;flex-wrap:wrap;'>";
        echo "<input type='file' name='policy_document' accept='.pdf,application/pdf' class='form-control' style='width:auto;'>";
        echo "<a href='" . htmlspecialchars($policyUrl, ENT_QUOTES, 'UTF-8') . "' target='_blank' rel='noopener' class='btn btn-sm btn-outline-secondary' style='display:inline-flex;align-items:center;gap:4px;'>";
        echo "<i class='ti ti-file-text'></i> " . __('View current', 'fleetbooking');
        echo "</a>";
        if ($hasCustomPolicy) {
            echo "<span class='badge bg-success' style='font-size:0.8em;padding:4px 8px;'>" . __('Custom Document Active', 'fleetbooking') . "</span>";
        } else {
            echo "<span class='badge bg-secondary' style='font-size:0.8em;padding:4px 8px;'>" . __('Default Document Active', 'fleetbooking') . "</span>";
        }
        echo "</div>";
        echo "<div style='color:#555; font-size:0.85em; margin-top:4px;'>";
        echo __('Upload a custom PDF policy to replace the default for this entity.', 'fleetbooking');
        echo "</div>";
        echo "</td></tr>";

        // Responsibility Term Template Upload & View
        $termUrl = self::getTermTemplateUrl($entities_id);
        $hasCustomTerm = !empty($current['term_template_path']) && file_exists($current['term_template_path']);
        echo "<tr class='tab_bg_1'><td>" . __('Responsibility Term Template', 'fleetbooking') . " (PDF)</td><td>";
        echo "<div style='display:flex;align-items:center;gap:12px;flex-wrap:wrap;'>";
        echo "<input type='file' name='term_template_document' accept='.pdf,application/pdf' class='form-control' style='width:auto;'>";
        echo "<a href='" . htmlspecialchars($termUrl, ENT_QUOTES, 'UTF-8') . "' target='_blank' rel='noopener' class='btn btn-sm btn-outline-secondary' style='display:inline-flex;align-items:center;gap:4px;'>";
        echo "<i class='ti ti-file-text'></i> " . __('View current', 'fleetbooking');
        echo "</a>";
        if ($hasCustomTerm) {
            echo "<span class='badge bg-success' style='font-size:0.8em;padding:4px 8px;'>" . __('Custom Document Active', 'fleetbooking') . "</span>";
        } else {
            echo "<span class='badge bg-secondary' style='font-size:0.8em;padding:4px 8px;'>" . __('Default Document Active', 'fleetbooking') . "</span>";
        }
        echo "</div>";
        echo "<div style='color:#555; font-size:0.85em; margin-top:4px;'>";
        echo __('Upload a custom PDF reference for the driver responsibility term.', 'fleetbooking');
        echo "</div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
        if (isset($current['id']) && (int) $current['entities_id'] === (int) $entities_id) {
            echo "<input type='submit' name='update' class='submit' value='" . _sx('button', 'Save') . "'>";
        } else {
            echo "<input type='submit' name='add' class='submit' value='" . _sx('button', 'Add') . "'>";
        }
        echo "</td></tr>";
        echo "</table>";

        \Html::closeForm();
    }

    /**
     * Grants asset_veiculofrota READ (1) to the given profile.
     * Only upgrades from 0 (no access). Never downgrades existing permissions.
     *
     * @param int $profileId GLPI profile ID
     * @return void
     */
    public static function ensureVehicleAssetReadAccess(int $profileId): void
    {
        if ($profileId <= 0) {
            return;
        }

        global $DB;

        $rightName = 'asset_veiculofrota';

        $existing = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => \ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profileId,
                'name'        => $rightName,
            ],
        ])->current();

        $currentRights = (int) ($existing['rights'] ?? 0);

        if ($currentRights === 0) {
            $DB->updateOrInsert(
                \ProfileRight::getTable(),
                ['rights' => 1],
                ['profiles_id' => $profileId, 'name' => $rightName]
            );
        }
    }

    public function post_addItem(): void
    {
        global $DB;

        if (isset($this->fields['default_tickets_entities_id']) && (int) $this->fields['default_tickets_entities_id'] > 0) {
            $new_entities_id = $this->fields['default_tickets_entities_id'];
            $config_entities_id = $this->fields['entities_id'];
            $this->migrateActiveTickets($config_entities_id, (int) $new_entities_id);
        }

        parent::post_addItem();
    }

    public function post_updateItem($history = 1): void
    {
        global $DB;

        if (in_array('default_tickets_entities_id', $this->updates)) {
            $old_entities_id = $this->oldvalues['default_tickets_entities_id'] ?? null;
            $new_entities_id = $this->fields['default_tickets_entities_id'];
            $config_entities_id = $this->fields['entities_id'];

            if ($old_entities_id !== null && (int) $old_entities_id !== (int) $new_entities_id) {
                $this->migrateActiveTickets($config_entities_id, (int) $new_entities_id);
            }
        }

        parent::post_updateItem($history);
    }

    /**
     * Migrates active/pending tickets and requests to the new entity.
     *
     * @param int $config_entities_id
     * @param int $new_tickets_entities_id
     * @return void
     */
    private function migrateActiveTickets(int $config_entities_id, int $new_tickets_entities_id): void
    {
        global $DB;

        // Get requests from the configuration entity with an associated ticket_id
        $requests = $DB->request([
            'FROM' => \GlpiPlugin\Fleetbooking\Request::getTable(),
            'WHERE' => [
                'entities_id' => $config_entities_id,
                'tickets_id' => ['>', 0]
            ]
        ]);

        $ticket_ids = [];
        foreach ($requests as $request) {
            $ticket_ids[] = (int) $request['tickets_id'];
        }

        if (empty($ticket_ids)) {
            return;
        }

        // Filter tickets to only include active/pending ones (not solved=5 or closed=6)
        $solved_status = defined('Ticket::SOLVED') ? \Ticket::SOLVED : 5;
        $closed_status = defined('Ticket::CLOSED') ? \Ticket::CLOSED : 6;

        $active_tickets = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_tickets',
            'WHERE' => [
                'id' => $ticket_ids,
                'status' => ['NOT IN', [$solved_status, $closed_status]]
            ]
        ]);

        $active_ticket_ids = [];
        foreach ($active_tickets as $tk) {
            $active_ticket_ids[] = (int) $tk['id'];
        }

        if (empty($active_ticket_ids)) {
            return;
        }

        // Migrate tickets to the new entity
        $DB->update(
            'glpi_tickets',
            ['entities_id' => $new_tickets_entities_id],
            ['id' => $active_ticket_ids]
        );

        // Update the requests entity ID in glpi_plugin_fleetbooking_requests
        $DB->update(
            \GlpiPlugin\Fleetbooking\Request::getTable(),
            ['entities_id' => $new_tickets_entities_id],
            ['tickets_id' => $active_ticket_ids]
        );
    }
}
