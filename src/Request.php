<?php

namespace GlpiPlugin\Fleetbooking;

use CommonDBTM;
use Html;

/**
 * Class Request
 * Represents a fleet booking request which will orchestrate ticket and reservation.
 */
class Request extends CommonDBTM
{

    // Disables traditional entity forward to avoid unexpected behavior
    static $rightname = 'fleetbooking_request';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CONFLICT = 'conflict';

    /**
     * Get the descriptive name of the item
     */
    static function getTypeName($nb = 0)
    {
        return _n('Fleet Booking Request', 'Fleet Booking Requests', $nb, 'fleetbooking');
    }

    public static function getIcon()
    {
        return 'ti ti-car';
    }

    /**
     * Display a link to items in the GLPI menu
     */
    static function canCreate(): bool
    {
        return \Session::haveRight(self::$rightname, READ);
    }

    static function canView(): bool
    {
        return \Session::haveRight(self::$rightname, READ);
    }

    public function isEntityAssign()
    {
        return true;
    }

    public function isRecursive()
    {
        return false;
    }

    static function getMenuName()
    {
        return self::getTypeName(2);
    }

    static function getMenuContent()
    {
        $menu = [];
        $menu['title'] = self::getMenuName();
        $menu['page'] = '/plugins/fleetbooking/front/request.php';
        $menu['icon'] = 'ti ti-car';
        $menu['links']['search'] = '/plugins/fleetbooking/front/request.php';
        $menu['links']['add'] = '/plugins/fleetbooking/front/request.form.php';

        $menu['options'] = [
            'request' => [
                'title' => self::getMenuName(),
                'page'  => '/plugins/fleetbooking/front/request.php',
                'links' => [
                    'search' => '/plugins/fleetbooking/front/request.php',
                    'add'    => '/plugins/fleetbooking/front/request.form.php'
                ]
            ],
            'dashboard' => [
                'title' => __('Fleet Reservation Dashboard', 'fleetbooking'),
                'page'  => '/plugins/fleetbooking/front/dashboard.php',
                'links' => [
                    'search' => '/plugins/fleetbooking/front/dashboard.php'
                ]
            ]
        ];

        return $menu;
    }

    /**
     * Search options for GLPI lists
     */
    public function rawSearchOptions()
    {
        global $DB;
        $tab = [];

        $tab[] = [
            'id' => 'common',
            'name' => __('Characteristics')
        ];

        $tab[] = [
            'id'       => '80',
            'table'    => $this->getTable(),
            'field'    => 'entities_id',
            'name'     => __('Entity'),
            'datatype' => 'entity'
        ];

        $tab[] = [
            'id' => '1',
            'table' => $this->getTable(),
            'field' => 'status',
            'name' => __('Status', 'fleetbooking'),
            'datatype' => 'specific',
            'searchtype' => 'equals'
        ];

        $tab[] = [
            'id' => '2',
            'table' => $this->getTable(),
            'field' => 'start_datetime',
            'name' => __('Start Date', 'fleetbooking'),
            'datatype' => 'datetime'
        ];

        $tab[] = [
            'id' => '3',
            'table' => $this->getTable(),
            'field' => 'end_datetime',
            'name' => __('End Date', 'fleetbooking'),
            'datatype' => 'datetime'
        ];

        $tab[] = [
            'id'        => '4',
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'requester_users_id',
            'name'      => __('Requester'),
            'datatype'  => 'string'
        ];

        $itemtype = '';
        if (isset($_SESSION['glpiactive_entity'])) {
            $config = Config::getForEntity($_SESSION['glpiactive_entity']);
            $itemtype = $config['vehicle_itemtype'] ?? '';
        }
        if (!empty($itemtype) && class_exists($itemtype)) {
            $vehicle_item = new $itemtype();
            $vehicle_table = $vehicle_item->getTable();
            $tab[] = [
                'id'        => '5',
                'table'     => $vehicle_table,
                'field'     => 'name',
                'linkfield' => 'items_id',
                'name'      => __('Vehicle', 'fleetbooking'),
                'datatype'  => 'dropdown',
                'itemtype'  => $itemtype,
                'joinparams' => [
                    'condition' => 'AND `' . $this->getTable() . '`.`itemtype` = ' . $DB->quoteValue($itemtype)
                ]
            ];
        } else {
            $tab[] = [
                'id' => '5',
                'table' => $this->getTable(),
                'field' => 'itemtype',
                'name' => __('Vehicle', 'fleetbooking'),
                'datatype' => 'string'
            ];
        }

        $tab[] = [
            'id' => '6',
            'table' => $this->getTable(),
            'field' => 'reason',
            'name' => __('Reason for requesting', 'fleetbooking'),
            'datatype' => 'text'
        ];

        $tab[] = [
            'id'        => '7',
            'table'     => 'glpi_tickets',
            'field'     => 'name',
            'linkfield' => 'tickets_id',
            'name'      => __('Ticket'),
            'datatype'  => 'itemlink'
        ];

        return $tab;
    }

    /**
     * Name in tab for Ticket
     */
    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === 'Ticket') {
            if (\Session::haveRight('fleetbooking_approve', READ) || \Session::haveRight('fleetbooking_admin', READ)) {
                if (method_exists(__CLASS__, 'createTabEntry')) {
                    return self::createTabEntry(__('Fleet Approval', 'fleetbooking'), 0, $item->getType(), self::getIcon());
                }
                return __('Fleet Approval', 'fleetbooking');
            }
        }
        return '';
    }

    /**
     * Display the content of the tab for Ticket
     */
    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === 'Ticket') {
            // Include viewing logic or redirect to front controller logic
            include __DIR__ . '/../front/request.view.php';
            return true;
        }
        return false;
    }

    /**
     * Get all statuses. The keys are the database status strings and the values are their translations.
     *
     * @return array
     */
    public static function getAllStatuses()
    {
        return [
            self::STATUS_PENDING  => __('Pending', 'fleetbooking'),
            self::STATUS_APPROVED => __('Approved', 'fleetbooking'),
            self::STATUS_REJECTED => __('Rejected', 'fleetbooking'),
            self::STATUS_CONFLICT => __('Conflict', 'fleetbooking'),
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'status':
                $statuses = self::getAllStatuses();
                return $statuses[$values[$field]] ?? $values[$field];
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        switch ($field) {
            case 'status':
                $options['value'] = $values[$field];
                return \Dropdown::showFromArray($name, self::getAllStatuses(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
