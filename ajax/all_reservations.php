<?php

/**
 * Sprint 6 — AJAX endpoint: All Active/Future Reservations
 *
 * Returns JSON array of all pending/approved reservations with end_datetime >= NOW().
 * Accessible only to users with fleetbooking_approve or fleetbooking_admin rights.
 *
 * @security  Session-based authentication + explicit right check. No CSRF needed (GET-only).
 * @output    JSON array
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

// Permission gate: must have approve OR admin right
if (
    !Session::haveRight('fleetbooking_approve', READ) &&
    !Session::haveRight('fleetbooking_admin', READ)
) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

global $DB, $CFG_GLPI;

$config = \GlpiPlugin\Fleetbooking\Config::getForEntity(
    (int) ($_SESSION['glpiactive_entity'] ?? 0)
);

try {
    $requestTable = \GlpiPlugin\Fleetbooking\Request::getTable();

    // Fetch all active/future requests (pending or approved with end_datetime >= NOW)
    $rows = $DB->request([
        'SELECT' => [
            "$requestTable.id",
            "$requestTable.items_id",
            "$requestTable.itemtype",
            "$requestTable.start_datetime",
            "$requestTable.end_datetime",
            "$requestTable.reason",
            "$requestTable.status",
            "$requestTable.tickets_id",
            "$requestTable.requester_users_id",
        ],
        'FROM'  => $requestTable,
        'WHERE' => [
            'status' => ['pending', 'approved'],
            "$requestTable.end_datetime" => ['>=', date('Y-m-d H:i:s')],
        ],
        'ORDER' => ['start_datetime ASC'],
        'LIMIT' => 200,
    ]);

    $reservations = [];

    foreach ($rows as $row) {
        // Resolve vehicle name
        $vehicleName = htmlspecialchars($row['itemtype'] ?? '', ENT_QUOTES, 'UTF-8');
        if (!empty($row['itemtype']) && class_exists($row['itemtype'])) {
            $vehicleObj = new $row['itemtype']();
            if ($vehicleObj->getFromDB((int) $row['items_id'])) {
                $vehicleName = htmlspecialchars($vehicleObj->getName(), ENT_QUOTES, 'UTF-8');
            }
        }

        // Resolve requester name
        $requesterName = htmlspecialchars(__('Unknown', 'fleetbooking'), ENT_QUOTES, 'UTF-8');
        $userObj = new User();
        if ($userObj->getFromDB((int) $row['requester_users_id'])) {
            $requesterName = htmlspecialchars($userObj->getFriendlyName(), ENT_QUOTES, 'UTF-8');
        }

        // Build ticket URL if available
        $ticketUrl = '';
        if (!empty($row['tickets_id'])) {
            $ticketUrl = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int) $row['tickets_id'];
        }

        $reservations[] = [
            'id'             => (int) $row['id'],
            'vehicle'        => $vehicleName,
            'requester'      => $requesterName,
            'reason'         => htmlspecialchars($row['reason'] ?? '', ENT_QUOTES, 'UTF-8'),
            'start_datetime' => htmlspecialchars($row['start_datetime'] ?? '', ENT_QUOTES, 'UTF-8'),
            'end_datetime'   => htmlspecialchars($row['end_datetime'] ?? '', ENT_QUOTES, 'UTF-8'),
            'status'         => htmlspecialchars($row['status'] ?? '', ENT_QUOTES, 'UTF-8'),
            'tickets_id'     => (int) ($row['tickets_id'] ?? 0),
            'ticket_url'     => $ticketUrl,
        ];
    }

    echo json_encode($reservations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Exception $e) {
    \Toolbox::logInFile('fleetbooking', '[all_reservations.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error'], JSON_UNESCAPED_UNICODE);
}
