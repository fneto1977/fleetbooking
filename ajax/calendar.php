<?php
include("../../../inc/includes.php");

header('Content-Type: application/json');

if (!Session::haveRight("fleetbooking_request", READ) && !Session::haveRight("fleetbooking_approve", READ) && !Session::haveRight("fleetbooking_admin", READ)) {
    \Toolbox::logInFile('fleetbooking', '[calendar.php] Permission denied — user lacks fleetbooking_request, fleetbooking_approve, and fleetbooking_admin rights.');
    echo json_encode(['error' => __('Permission denied', 'fleetbooking')]);
    exit;
}

$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;

\Toolbox::logInFile('fleetbooking', sprintf(
    '[calendar.php] Request: user=%d, start=%s, end=%s',
    (int) Session::getLoginUserID(),
    $start ?? 'null',
    $end ?? 'null'
));

if ($start) {
    try {
        $dt = new \DateTime($start);
        $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $start = $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        $start = null;
    }
}
if ($end) {
    try {
        $dt = new \DateTime($end);
        $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $end = $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        $end = null;
    }
}
$itemtype = $_GET['itemtype'] ?? '';
$items_id = (int) ($_GET['items_id'] ?? 0);
$current_request_id = (int) ($_GET['current_request_id'] ?? 0);

\Toolbox::logInFile('fleetbooking', sprintf(
    '[calendar.php] Params: itemtype=%s, items_id=%d, current_request_id=%d',
    $itemtype,
    $items_id,
    $current_request_id
));

if (!$start || !$end) {
    \Toolbox::logInFile('fleetbooking', '[calendar.php] Missing start or end — returning empty array.');
    echo json_encode([]);
    exit;
}

// Verify user has entity access to the requested vehicle when filtering
// by specific itemtype/items_id — mirrors the defence added in
// fleetbooking/ajax/availability.php
if ($items_id > 0 && $itemtype !== '' && class_exists($itemtype)) {
    global $DB;

    // Validate itemtype against configured vehicle types to prevent
    // instantiation of arbitrary GLPI classes via user-controlled input
    $allowed_itemtypes = [];
    $type_result = $DB->request([
        'SELECT' => ['vehicle_itemtype'],
        'FROM' => 'glpi_plugin_fleetbooking_configs',
        'WHERE' => ['NOT' => ['vehicle_itemtype' => null]]
    ]);
    foreach ($type_result as $row) {
        if (!empty($row['vehicle_itemtype'])) {
            $allowed_itemtypes[$row['vehicle_itemtype']] = true;
        }
    }
    $allowed_itemtypes['Glpi\CustomAsset\VehicleFleetAsset'] = true;

    if (isset($allowed_itemtypes[$itemtype])) {
        $vehicle = new $itemtype();
        if ($vehicle->getFromDB($items_id)) {
            $vehicleEntity = $vehicle->fields['entities_id'] ?? -1;
            \Toolbox::logInFile('fleetbooking', sprintf(
                '[calendar.php] Vehicle entity=%d, user active_entity=%d, hasAccess=%s',
                $vehicleEntity,
                (int) ($_SESSION['glpiactive_entity'] ?? -1),
                \Session::haveAccessToEntity($vehicleEntity) ? 'YES' : 'NO'
            ));
            // Entity 0 is the root entity — vehicles placed there are globally
            // accessible and should not be blocked by per-entity access checks.
            if ($vehicleEntity != 0 && !\Session::haveAccessToEntity($vehicleEntity)) {
                \Toolbox::logInFile('fleetbooking', '[calendar.php] Entity access denied — returning empty array.');
                echo json_encode([]);
                exit;
            }
        }
    } else {
        \Toolbox::logInFile('fleetbooking', sprintf(
            '[calendar.php] Itemtype "%s" NOT in allowed list.',
            $itemtype
        ));
    }
}

try {
    $service = new \GlpiPlugin\Fleetbooking\Service\CalendarService();
    $events = $service->getEvents($itemtype, $items_id, $start, $end, $current_request_id);
    \Toolbox::logInFile('fleetbooking', sprintf(
        '[calendar.php] Events returned: %d',
        count($events)
    ));
    echo json_encode($events);
} catch (\Throwable $e) {
    \Toolbox::logInFile(
        'fleetbooking',
        sprintf(
            __("Calendar error: %s\nFile: %s:%d\nTrace:\n%s", 'fleetbooking'),
            $e->getMessage(),
            str_replace(GLPI_ROOT, '[GLPI_ROOT]', $e->getFile()),
            $e->getLine(),
            $e->getTraceAsString()
        )
    );
    http_response_code(500);
    echo json_encode(['error' => __('Internal server error.', 'fleetbooking')]);
}
