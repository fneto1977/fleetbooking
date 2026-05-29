<?php

/**
 * STUB FILE — GLPI Core declarations for IDE static analysis.
 *
 * This file provides type declarations for GLPI core classes and functions
 * that are used by the FleetBooking plugin. It is NOT loaded at runtime;
 * it exists solely to help the IDE (PHP Intelephense) resolve references
 * when the GLPI core source is not available locally.
 *
 * @see .vscode/settings.json  ->  intelephense.environment.includePaths
 */

// ---------------------------------------------------------------------------
// GLPI Core Global Functions
// ---------------------------------------------------------------------------

/**
 * GLPI translation function.
 *
 * Returns the translated string for the given key and domain.
 *
 * @param string $message The message to translate.
 * @param string $domain  The text domain (plugin name).
 * @return string
 */
function __($message, $domain = '')
{
    return '';
}

/**
 * GLPI plural translation function (not used yet, but declared for completeness).
 *
 * @param string $singular Singular form.
 * @param string $plural   Plural form.
 * @param int    $count    Count.
 * @return string
 */
function _n($singular, $plural, $count)
{
    return '';
}

// ---------------------------------------------------------------------------
// GLPI Core Classes
// ---------------------------------------------------------------------------

/**
 * GLPI Toolbox — utility class for logging, sanitization, etc.
 */
class Toolbox
{
    /**
     * @param mixed $data Data to add slashes to.
     * @return mixed
     */
    public static function addslashes_deep($data)
    {
    }

    /**
     * @param mixed $data Data to strip slashes from.
     * @return mixed
     */
    public static function stripslashes_deep($data)
    {
    }

    /**
     * Log message to plugin file.
     *
     * @param string $name    Plugin name / log identifier.
     * @param string $message Log message.
     * @return void
     */
    public static function logInFile($name, $message)
    {
    }
}

/**
 * GLPI base class for DB-managed items.
 */
class CommonDBTM
{
    /** @var array */
    public $fields = [];

    /**
     * @param int $id
     * @return bool
     */
    public function getFromDB($id)
    {
        return false;
    }

    /**
     * @param array $criteria
     * @return bool
     */
    public function getFromDBByCrit(array $criteria)
    {
        return false;
    }

    /**
     * @param string $itemtype
     * @param int    $items_id
     * @return bool
     */
    public function getFromDBByItem($itemtype, $items_id)
    {
        return false;
    }

    /**
     * @return int
     */
    public function getID()
    {
        return 0;
    }

    /**
     * @param array $input
     * @return int|false
     */
    public function add(array $input)
    {
        return 0;
    }

    /**
     * @param array $input
     * @return bool
     */
    public function update(array $input)
    {
        return false;
    }

    /**
     * @param array $input
     * @param int   $force
     * @param int   $history
     * @return bool
     */
    public function delete(array $input, $force = 0, $history = 1)
    {
        return false;
    }

    /**
     * @param string|null $classname
     * @return string
     */
    public static function getTable($classname = null)
    {
        return '';
    }

    public function getName()
    {
    }
}

class CommonGLPI
{
}

// ---------------------------------------------------------------------------
// Core GLPI Ticket classes
// ---------------------------------------------------------------------------

class Ticket extends CommonDBTM
{
    const DEMAND_TYPE = 1;
    const SOLVED = 5;
    const CLOSED = 6;
}

class Ticket_Ticket extends CommonDBTM
{
}

class ITILFollowup extends CommonDBTM
{
    /** @var array */
    public $fields = [];
}

// ---------------------------------------------------------------------------
// Reservation subsystem
// ---------------------------------------------------------------------------

class ReservationItem extends CommonDBTM
{
    /** @var array */
    public $fields = [];
}

class Reservation extends CommonDBTM
{
    /** @var array */
    public $fields = [];
}

// ---------------------------------------------------------------------------
// Association tables
// ---------------------------------------------------------------------------

class Item_Ticket extends CommonDBTM
{
}

// ---------------------------------------------------------------------------
// Group / User management
// ---------------------------------------------------------------------------

class Group_User extends CommonDBTM
{
    /**
     * @param int   $users_id
     * @param array $condition
     * @return array
     */
    public static function getUserGroups($users_id, array $condition = [])
    {
        return [];
    }
}

class Group extends CommonDBTM
{
}

class User extends CommonDBTM
{
}

// ---------------------------------------------------------------------------
// Core utility classes
// ---------------------------------------------------------------------------

class Plugin
{
    public static function isPluginActive($name)
    {
    }
    public static function registerClass($class, $options = [])
    {
    }
}

class Session
{
}

class Html
{
    public static function header($title, $url = '')
    {
    }
    public static function footer()
    {
    }
}

class DbUtils
{
    public static function getEntityRestrictRequest($table, $alias = '', $entities_id = 0, $is_recursive = false)
    {
    }
}

class Entity extends CommonDBTM
{
}

class Profile extends CommonDBTM
{
}
