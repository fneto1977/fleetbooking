<?php

/**
 * Versioned migration system for FleetBooking plugin.
 *
 * Each migration block handles upgrades from a previous version to the next.
 * The current version (without 'v' prefix) is compared against target versions.
 *
 * IMPORTANT: Always add new migrations at the bottom of the function,
 * keeping blocks in ascending version order.
 *
 * @param string $current_version The version currently installed (may include 'v' prefix).
 * @return bool True on success.
 *
 * @see https://glpi-plugins.readthedocs.io/en/latest/ for GLPI migration best practices.
 */
function plugin_fleetbooking_upgrade($current_version)
{
    global $DB;

    // Strip a single optional 'v' prefix for safe version_compare usage.
    // Using str_starts_with + substr instead of ltrim(..., 'v') because
    // ltrim's character-mask mode strips every leading 'v', which would
    // corrupt a malformed version like "v1.0.v5" into "1.0.5".
    if (str_starts_with($current_version, 'v')) {
        $current_version = substr($current_version, 1);
    }

    // ---- Migration: 1.1.0 ----
    // Ensure display preferences and schema are up to date for 1.1.0
    if (version_compare($current_version, '1.1.0', '<')) {
        // Re-run schema integrity checks to pick up any new columns
        // (e.g. default_tickets_entities_id added in 1.1.0)
        if (class_exists('GlpiPlugin\Fleetbooking\Config')) {
            \GlpiPlugin\Fleetbooking\Config::ensureSchemaIntegrity();
        }
    }

    // ---- Migration: 1.13.0 ----
    if (version_compare($current_version, '1.13.0', '<')) {
        if (class_exists('GlpiPlugin\Fleetbooking\Config')) {
            \GlpiPlugin\Fleetbooking\Config::ensureSchemaIntegrity();
        }
        if ($DB->tableExists('glpi_plugin_fleetbooking_requests')) {
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'policy_accepted_at')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `policy_accepted_at` timestamp NULL DEFAULT NULL AFTER `status`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'policy_accepted_ip')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `policy_accepted_ip` varchar(45) DEFAULT NULL AFTER `policy_accepted_at`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'driver_id_type')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `driver_id_type` varchar(16) NOT NULL DEFAULT 'cpf' AFTER `policy_accepted_ip`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'driver_cpf')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `driver_cpf` varchar(14) DEFAULT NULL AFTER `driver_id_type`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'driver_registration')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `driver_registration` varchar(32) DEFAULT NULL AFTER `driver_cpf`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'driver_cnh_number')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `driver_cnh_number` varchar(20) NOT NULL DEFAULT '' AFTER `driver_registration`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'driver_cnh_category')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `driver_cnh_category` varchar(5) NOT NULL DEFAULT '' AFTER `driver_cnh_number`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'driver_cnh_expiry')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `driver_cnh_expiry` date DEFAULT NULL AFTER `driver_cnh_category`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_requests', 'term_document_id')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_requests` ADD `term_document_id` int unsigned DEFAULT NULL AFTER `driver_cnh_expiry`");
            }
        }
        if ($DB->tableExists('glpi_plugin_fleetbooking_configs')) {
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_configs', 'policy_document_path')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD `policy_document_path` varchar(255) DEFAULT NULL AFTER `reserved_color`");
            }
            if (!$DB->fieldExists('glpi_plugin_fleetbooking_configs', 'term_template_path')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_fleetbooking_configs` ADD `term_template_path` varchar(255) DEFAULT NULL AFTER `policy_document_path`");
            }
        }
    }

    return true;
}
