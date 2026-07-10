<?php

include("../../../inc/includes.php");

// Requires at least one fleetbooking right — fleetbooking_read is enough.
if (
    !Session::haveRight('fleetbooking_read', READ)
    && !Session::haveRight('fleetbooking_request', READ)
    && !Session::haveRight('fleetbooking_approve', READ)
    && !Session::haveRight('fleetbooking_admin', READ)
) {
    Session::redirectIfNotLoggedIn();
    Html::displayRightError();
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::displayNotFoundError();
    exit;
}

$request = new \GlpiPlugin\Fleetbooking\Request();
if (!$request->getFromDB($id)) {
    Html::displayNotFoundError();
    exit;
}

$reqData = $request->fields;

Html::header(
    __('Fleet Booking Request Details', 'fleetbooking'),
    '/plugins/fleetbooking/front/request.readonly.php',
    'tools',
    'GlpiPlugin\\Fleetbooking\\Request'
);

// Resolve vehicle name
$config      = \GlpiPlugin\Fleetbooking\Config::getForEntity($reqData['entities_id'] ?? 0);
$itemtype    = $reqData['itemtype'] ?? '';
$vehicleName = '';
if (!empty($itemtype) && class_exists($itemtype)) {
    $vehicle = new $itemtype();
    if ($vehicle->getFromDB((int) $reqData['items_id'])) {
        $vehicleName = $vehicle->getName();
    }
}

// Resolve requester name
$user = new User();
$user->getFromDB((int) $reqData['requester_users_id']);
$requesterName = $user->getName();

// Status labels and CSS classes
$statusLabels = \GlpiPlugin\Fleetbooking\Request::getAllStatuses();
$statusLabel  = $statusLabels[$reqData['status']] ?? $reqData['status'];
$statusCssMap = [
    'pending'  => 'fb-status-pending',
    'approved' => 'fb-status-approved',
    'rejected' => 'fb-status-rejected',
    'conflict' => 'fb-status-conflict',
];
$statusClass = $statusCssMap[$reqData['status']] ?? '';

echo "<div class='center fleetbooking-container' style='max-width: 800px; margin: 30px auto;'>";
echo "<h2>" . __('Fleet Booking Request Details', 'fleetbooking') . "</h2>";

echo "<table class='tab_cadre_fixe'>";

// Status
echo "<tr class='tab_bg_1'>";
echo "<th style='width: 30%'>" . __('Status', 'fleetbooking') . "</th>";
echo "<td><strong class='" . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . "'>"
    . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . "</strong></td>";
echo "</tr>";

// Vehicle
echo "<tr class='tab_bg_1'>";
echo "<th>" . __('Vehicle', 'fleetbooking') . "</th>";
echo "<td>" . htmlspecialchars($vehicleName ?: ('ID: ' . $reqData['items_id']), ENT_QUOTES, 'UTF-8') . "</td>";
echo "</tr>";

// Requester
echo "<tr class='tab_bg_1'>";
echo "<th>" . __('Requester', 'fleetbooking') . "</th>";
echo "<td>" . htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') . "</td>";
echo "</tr>";

// Start date (pickup)
echo "<tr class='tab_bg_1'>";
echo "<th>" . __('Pickup Date', 'fleetbooking') . "</th>";
echo "<td>" . Html::convDateTime($reqData['start_datetime']) . "</td>";
echo "</tr>";

// End date (return)
echo "<tr class='tab_bg_1'>";
echo "<th>" . __('Return Date', 'fleetbooking') . "</th>";
echo "<td>" . Html::convDateTime($reqData['end_datetime']) . "</td>";
echo "</tr>";

// Reason
echo "<tr class='tab_bg_1'>";
echo "<th>" . __('Reason for requesting', 'fleetbooking') . "</th>";
echo "<td>" . nl2br(htmlspecialchars((string) ($reqData['reason'] ?? ''), ENT_QUOTES, 'UTF-8')) . "</td>";
echo "</tr>";

// Decision comment (only when decided)
if (!empty($reqData['decision_comment']) && $reqData['status'] !== 'pending') {
    echo "<tr class='tab_bg_1'>";
    echo "<th>" . __('Decision Comment', 'fleetbooking') . "</th>";
    echo "<td>" . nl2br(htmlspecialchars((string) $reqData['decision_comment'], ENT_QUOTES, 'UTF-8')) . "</td>";
    echo "</tr>";
}

echo "</table>";

echo "<p style='margin-top: 20px;'>";
echo "<a class='vsubmit' href='" . htmlspecialchars(
    Plugin::getWebDir('fleetbooking') . '/front/request.php',
    ENT_QUOTES,
    'UTF-8'
) . "'>&larr; " . __('Back to list', 'fleetbooking') . "</a>";
echo "</p>";

echo "</div>";

Html::footer();
