<?php

/**
 * Install hook — fresh install only.
 * Sets default profile rights and creates initial DB schema.
 *
 * @return boolean
 */
function plugin_fleetbooking_install()
{
    global $DB;

    // Execute install.php if it exists
    if (file_exists(__DIR__ . '/sql/install.php')) {
        include_once __DIR__ . '/sql/install.php';
        if (function_exists('plugin_fleetbooking_install_db')) {
            // Fresh install: apply default profile rights.
            plugin_fleetbooking_install_db(false);
        }
    }

    return true;
}

/**
 * Upgrade hook — preserves all existing profile rights.
 * Runs schema migrations and registers any new rights without overwriting
 * permissions already configured by the administrator.
 *
 * @param string $old_version
 * @return boolean
 */
function plugin_fleetbooking_upgrade($old_version)
{
    global $DB;

    if (file_exists(__DIR__ . '/sql/install.php')) {
        include_once __DIR__ . '/sql/install.php';
        if (function_exists('plugin_fleetbooking_install_db')) {
            // Upgrade: preserve existing rights; only insert new ones.
            plugin_fleetbooking_install_db(true);
        }
    }

    // Enforce version bump in DB to avoid GLPI looping the update state.
    $DB->updateOrInsert(
        \Plugin::getTable(),
        ['version' => PLUGIN_FLEETBOOKING_VERSION, 'state' => 1],
        ['directory' => 'fleetbooking']
    );

    return true;
}

/**
 * Uninstall hook
 *
 * @return boolean
 */
function plugin_fleetbooking_uninstall()
{
    global $DB;

    $tables = [
        'glpi_plugin_fleetbooking_requests',
        'glpi_plugin_fleetbooking_groupmanagers',
        'glpi_plugin_fleetbooking_holidays',
        'glpi_plugin_fleetbooking_configs'
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->dropTable($table);
        }
    }

    // Remove rights
    $DB->delete(\ProfileRight::getTable(), ['name' => ['LIKE', 'fleetbooking%']]);

    // Clean up custom asset definitions created by plugin (any system_name variant)
    $assetSystemNames = ['veiculofrota', 'VehicleFleet'];
    foreach ($assetSystemNames as $sysName) {
        $assetDef = $DB->request([
            'SELECT' => ['id'],
            'FROM' => \Glpi\Asset\AssetDefinition::getTable(),
            'WHERE' => ['system_name' => $sysName]
        ])->current();

        if ($assetDef) {
            $assetDefId = (int) $assetDef['id'];
            $DB->delete(\Glpi\Asset\CustomFieldDefinition::getTable(), [
                'assets_assetdefinitions_id' => $assetDefId
            ]);
            $DB->delete(\Glpi\Asset\AssetDefinition::getTable(), ['id' => $assetDefId]);
        }
    }

    return true;
}
