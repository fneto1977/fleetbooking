<?php
include("../../../inc/includes.php");

header('Content-Type: application/json');

if (!Session::haveRight("fleetbooking_request", READ) && !Session::haveRight("fleetbooking_admin", READ)) {
    echo json_encode(['ok' => false, 'errors' => [__('Permission denied', 'fleetbooking')]]);
    exit;
}

// CSRF validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::validateCSRF($_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '');
}

// Get input from POST or GET request (safe from cookie parameter injection)
$input = $_POST + $_GET;
$start = isset($input['start']) ? (string) $input['start'] : null;
$end = isset($input['end']) ? (string) $input['end'] : null;
$itemtype = (string) ($input['itemtype'] ?? '');
$items_id = (int) ($input['items_id'] ?? 0);

// Fail fast: validate required parameters before any DB access
if (!$start || !$end || !$itemtype || !$items_id) {
    echo json_encode(['ok' => false, 'errors' => [__('Missing parameters', 'fleetbooking')]]);
    exit;
}

// Validate itemtype against configured vehicle types to prevent
// instantiation of arbitrary GLPI classes via user-controlled input
global $DB;
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
// Fallback: always allow the default asset type
$allowed_itemtypes['Glpi\CustomAsset\VeiculofrotaAsset'] = true;

if (!isset($allowed_itemtypes[$itemtype])) {
    echo json_encode(['ok' => false, 'errors' => [__('Invalid vehicle type', 'fleetbooking')]]);
    exit;
}

// Verify user has entity access to the requested vehicle
if (class_exists($itemtype)) {
    $vehicle = new $itemtype();
    if ($vehicle->getFromDB($items_id)) {
        $vehicleEntity = $vehicle->fields['entities_id'] ?? -1;
        // Entity 0 is the root entity — vehicles assigned there are globally accessible.
        if ($vehicleEntity != 0 && !\Session::haveAccessToEntity($vehicleEntity)) {
            echo json_encode(['ok' => false, 'errors' => [__('Permission denied', 'fleetbooking')]]);
            exit;
        }
    }
}



try {
    $service = new \GlpiPlugin\Fleetbooking\Service\RequestService();
    $result = $service->checkAvailability($itemtype, $items_id, $start, $end);
    echo json_encode($result);
} catch (\Throwable $e) {
    \Toolbox::logInFile(
        'fleetbooking',
        sprintf(
            __("Internal error: %s\nFile: %s:%d\nTrace:\n%s", 'fleetbooking'),
            $e->getMessage(),
            str_replace(GLPI_ROOT, '[GLPI_ROOT]', $e->getFile()),
            $e->getLine(),
            $e->getTraceAsString()
        )
    );
    http_response_code(500);
    echo json_encode(['error' => __('Internal server error.', 'fleetbooking')]);
}

