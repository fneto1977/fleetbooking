<?php

namespace GlpiPlugin\Fleetbooking;

use CommonGLPI;
use Html;
use Session;
use ProfileRight;

class Profile extends CommonGLPI
{

    public static function getTypeName($nb = 0)
    {
        return __('Fleet Booking', 'fleetbooking');
    }

    public static function getIcon()
    {
        return 'ti ti-car';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Profile') {
            if (Session::haveRight('profile', READ)) {
                if (method_exists(__CLASS__, 'createTabEntry')) {
                    return self::createTabEntry(self::getTypeName(), 0, self::getIcon());
                }
                return self::getTypeName();
            }
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() == 'Profile') {
            $profile = new self();
            $profile->showForm($item->getID());
            return true;
        }
        return false;
    }

    /**
     * Hook to load plugin rights into session
     */
    public static function initProfile()
    {
        global $DB;
        if (!isset($_SESSION['glpiactiveprofile']['id'])) {
            return;
        }
        $profile_id = $_SESSION['glpiactiveprofile']['id'];
        $iterator = $DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM' => 'glpi_profilerights',
            'WHERE' => [
                'profiles_id' => $profile_id,
                'OR' => [
                    ['name' => ['LIKE', 'fleetbooking_%']],
                    ['name' => 'asset_veiculofrota']
                ]
            ]
        ]);
        foreach ($iterator as $data) {
            $_SESSION['glpiactiveprofile'][$data['name']] = $data['rights'];
        }
        $_SESSION['glpiactiveprofile']['fleetbooking_rights_loaded'] = true;
    }

    public function showForm($profiles_id)
    {
        global $DB;

        $rights = [
            'fleetbooking_read' => __('Read', 'fleetbooking'),
            'fleetbooking_request' => __('Create request', 'fleetbooking'),
            'fleetbooking_approve' => __('Approve request', 'fleetbooking'),
            'fleetbooking_admin' => __('Admin', 'fleetbooking'),
        ];

        $action = \Plugin::getWebDir('fleetbooking') . '/front/profile.form.php';

        echo "<form method='post' action='$action'>";
        echo "<div class='center'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . __('Fleet Booking Rights', 'fleetbooking') . "</th></tr>";

        // Load current rights
        $current_rights = ProfileRight::getProfileRights($profiles_id, array_keys($rights));

        foreach ($rights as $right => $label) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>$label</td>";
            echo "<td>";
            $value = ($current_rights[$right] ?? 0) ? 1 : 0;
            // Map the boolean switch
            Html::showCheckbox([
                'name' => '_rights[' . $right . ']',
                'checked' => $value
            ]);
            echo "</td>";
            echo "</tr>";
        }

        echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
        echo "<input type='hidden' name='profiles_id' value='" . (int) $profiles_id . "'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo "<input type='submit' name='update' class='submit' value='" . _sx('button', 'Save') . "'>";
        echo "</td></tr>";
        echo "</table>";
        echo "</div>";
        Html::closeForm();
    }
}
