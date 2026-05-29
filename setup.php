<?php

define('PLUGIN_FLEETBOOKING_VERSION', '1.0.9');

define('PLUGIN_FLEETBOOKING_MIN_GLPI_VERSION', '11.0.0');

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_fleetbooking()
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $PLUGIN_HOOKS['csrf_compliant']['fleetbooking'] = true;
    $PLUGIN_HOOKS['config_page']['fleetbooking'] = 'front/config.php';

    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    if (Plugin::isPluginActive('fleetbooking')) {
        Plugin::registerClass('GlpiPlugin\Fleetbooking\Request', ['addtabon' => ['Ticket']]);
        Plugin::registerClass('GlpiPlugin\Fleetbooking\GroupManager');
        Plugin::registerClass('GlpiPlugin\Fleetbooking\Holiday');
        Plugin::registerClass('GlpiPlugin\Fleetbooking\Config', ['addtabon' => ['Entity']]);
        Plugin::registerClass('GlpiPlugin\Fleetbooking\Profile', ['addtabon' => ['Profile']]);

        $PLUGIN_HOOKS['add_css']['fleetbooking'] = 'css/fleetbooking.css';
        $PLUGIN_HOOKS['add_javascript']['fleetbooking'] = ['js/fleetbooking.js'];

        $PLUGIN_HOOKS['item_get_events']['fleetbooking'] = ['GlpiPlugin\Fleetbooking\Service\CalendarService', 'plugin_fleetbooking_item_get_events'];

        $PLUGIN_HOOKS['menu_toadd']['fleetbooking'] = ['tools' => 'GlpiPlugin\Fleetbooking\Request'];

        // Session right injection hook
        $PLUGIN_HOOKS['change_profile']['fleetbooking'] = ['GlpiPlugin\Fleetbooking\Profile', 'initProfile'];

        // Auto-load rights into current session if active but not loaded yet (prevents logout/login requirement)
        if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile']['fleetbooking_rights_loaded'])) {
            \GlpiPlugin\Fleetbooking\Profile::initProfile();
        }

        // Add to Self-Service Homepage
        $PLUGIN_HOOKS['helpdesk_menu_entry']['fleetbooking'] = '/plugins/fleetbooking/front/request.form.php';
        $PLUGIN_HOOKS['helpdesk_menu_entry_icon']['fleetbooking'] = 'ti ti-car';
    }
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array
 */
function plugin_version_fleetbooking()
{
    return [
        'name' => 'Fleet Booking',
        'version' => PLUGIN_FLEETBOOKING_VERSION,
        'author' => 'Francisco Neto, Getsmart',
        'license' => 'GPLv3+',
        'homepage' => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_FLEETBOOKING_MIN_GLPI_VERSION,
            ]
        ]
    ];
}

/**
 * Check pre-requisites before install
 * REQUIRED
 *
 * @return boolean
 */
function plugin_fleetbooking_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_FLEETBOOKING_MIN_GLPI_VERSION, '<')) {
        return false;
    }
    return true;
}

/**
 * Check configuration process
 * REQUIRED
 *
 * @param boolean $for_update
 *
 * @return boolean
 */
function plugin_fleetbooking_check_config($for_update = false)
{
    return true;
}
