<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_admin", UPDATE);

$config = new \GlpiPlugin\Fleetbooking\Config();

$allowedFields = [
    'id',
    'entities_id',
    'itilcategories_id',
    'default_tickets_entities_id',
    'vehicle_itemtype',
    'workday_start',
    'workday_end',
    'auto_close_ticket_on_decision',
    'show_pending_on_calendar',
    'approved_color',
    'pending_color',
];

if (isset($_POST["update"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    Session::checkRight("fleetbooking_admin", UPDATE);
    $input = array_intersect_key($_POST, array_flip($allowedFields));
    $config->update($input);
} elseif (isset($_POST["add"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    Session::checkRight("fleetbooking_admin", CREATE);
    $addFields = array_diff($allowedFields, ['id']);
    $input = array_intersect_key($_POST, array_flip($addFields));
    $config->add($input);
}

$entities_id = (int) ($_POST['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$tab = urlencode('GlpiPlugin\Fleetbooking\Config$1');

Html::back();
