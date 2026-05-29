<?php

namespace GlpiPlugin\Fleetbooking;

use CommonDBTM;

/**
 * Class GroupManager
 * Mapping groups to their designated managers for fleet booking requests.
 */
class GroupManager extends CommonDBTM
{

    static $rightname = 'fleetbooking_admin';

    static function getTypeName($nb = 0): string
    {
        return _n('Fleet Group Manager', 'Fleet Group Managers', $nb, 'fleetbooking');
    }

    public function rawSearchOptions(): array
    {
        $tab = [];
        $tab[] = [
            'id' => 'common',
            'name' => __('Characteristics')
        ];
        $tab[] = [
            'id' => '1',
            'table' => 'glpi_groups',
            'field' => 'name',
            'name' => __('Group'),
            'datatype' => 'itemlink'
        ];
        $tab[] = [
            'id' => '2',
            'table' => 'glpi_users',
            'field' => 'name',
            'name' => __('Manager', 'fleetbooking'),
            'datatype' => 'itemlink'
        ];
        return $tab;
    }

    public function showForm($id, array $options = [])
    {
        $this->initForm($id, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'><td>" . __('Group') . "</td><td>";
        \Group::dropdown(['name' => 'groups_id', 'value' => $this->fields['groups_id']]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Manager', 'fleetbooking') . "</td><td>";
        \User::dropdown(['name' => 'managers_users_id', 'value' => $this->fields['managers_users_id']]);
        echo "</td></tr>";

        $this->showFormButtons($options);
    }

    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0): string
    {
        // Can be attached to Group item if desired
        return '';
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        return false;
    }
}
