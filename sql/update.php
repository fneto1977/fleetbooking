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

    // ---- Future migrations go here ----
    // if (version_compare($current_version, '1.2.0', '<')) {
    //     // Add new columns, tables, or data migrations for 1.2.0
    // }

    return true;
}
