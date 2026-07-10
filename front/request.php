<?php

include("../../../inc/includes.php");

// Allow access to any user with at least one fleetbooking right.
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

Html::header(
    \GlpiPlugin\Fleetbooking\Request::getTypeName(Session::getPluralNumber()),
    '/plugins/fleetbooking/front/request.php',
    'tools',
    'GlpiPlugin\Fleetbooking\Request'
);

// Determine if the current user has only read-only access (Portaria profile).
$isReadOnly = \Session::haveRight('fleetbooking_read', READ)
    && !\Session::haveRight('fleetbooking_request', READ)
    && !\Session::haveRight('fleetbooking_approve', READ)
    && !\Session::haveRight('fleetbooking_admin', READ);

if ($isReadOnly) {
    // ---- Custom HTML listing for read-only profiles (Portaria) ----
    // The GLPI Search engine silently hides columns when the profile lacks
    // permissions on the joined tables (e.g. vehicle asset). A custom query
    // bypasses that limitation entirely.
    global $DB;

    $config   = \GlpiPlugin\Fleetbooking\Config::getForEntity($_SESSION['glpiactive_entity'] ?? 0);
    $itemtype = $config['vehicle_itemtype'] ?? '';

    $reqTable = \GlpiPlugin\Fleetbooking\Request::getTable();
    $userTable = \User::getTable();

    // Build the query with LEFT JOINs so it works even if vehicle class is unavailable.
    $query = [
        'SELECT' => [
            $reqTable . '.id',
            $reqTable . '.status',
            $reqTable . '.start_datetime',
            $reqTable . '.items_id',
            $userTable . '.name AS requester_name',
            $userTable . '.realname AS requester_realname',
            $userTable . '.firstname AS requester_firstname',
        ],
        'FROM' => $reqTable,
        'LEFT JOIN' => [
            $userTable => [
                'ON' => [
                    $reqTable  => 'requester_users_id',
                    $userTable => 'id',
                ],
            ],
        ],
        'WHERE' => [
            $reqTable . '.status' => \GlpiPlugin\Fleetbooking\Request::STATUS_APPROVED,
            $reqTable . '.start_datetime' => ['>', date('Y-m-d H:i:s', strtotime('-7 days'))],
        ],
        'ORDER' => $reqTable . '.start_datetime DESC',
    ];

    // Add vehicle name via LEFT JOIN if itemtype is available.
    $hasVehicleJoin = false;
    if (!empty($itemtype) && class_exists($itemtype)) {
        $vehicleItem  = new $itemtype();
        $vehicleTable = $vehicleItem->getTable();
        $query['SELECT'][] = $vehicleTable . '.name AS vehicle_name';
        $query['LEFT JOIN'][$vehicleTable] = [
            'ON' => [
                $reqTable     => 'items_id',
                $vehicleTable => 'id',
            ],
        ];
        $hasVehicleJoin = true;
    }

    $iterator = $DB->request($query);

    $statusLabels = \GlpiPlugin\Fleetbooking\Request::getAllStatuses();
    $baseUrl = \Plugin::getWebDir('fleetbooking') . '/front/request.readonly.php';

    echo "<div class='center' style='margin: 20px;'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<thead><tr>";
    echo "<th>" . __('Status', 'fleetbooking') . "</th>";
    echo "<th>" . __('Vehicle', 'fleetbooking') . "</th>";
    echo "<th>" . __('Requester') . "</th>";
    echo "<th>" . __('Pickup Date', 'fleetbooking') . "</th>";
    echo "</tr></thead>";
    echo "<tbody>";

    $count = 0;
    foreach ($iterator as $row) {
        $count++;
        $statusLabel = $statusLabels[$row['status']] ?? $row['status'];

        // Build requester display name (realname + firstname or fallback to login)
        $requesterName = trim(($row['requester_realname'] ?? '') . ' ' . ($row['requester_firstname'] ?? ''));
        if (empty($requesterName)) {
            $requesterName = $row['requester_name'] ?? '';
        }

        $vehicleName = $hasVehicleJoin
            ? ($row['vehicle_name'] ?? __('N/A'))
            : __('N/A');

        $detailUrl = $baseUrl . '?id=' . (int) $row['id'];

        echo "<tr class='tab_bg_1'>";
        echo "<td><a href='" . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . "'>"
            . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . "</a></td>";
        echo "<td>" . htmlspecialchars($vehicleName, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . Html::convDateTime($row['start_datetime']) . "</td>";
        echo "</tr>";
    }

    if ($count === 0) {
        echo "<tr class='tab_bg_1'><td colspan='4' class='center'>"
            . __('No approved reservations found for the last 7 days.', 'fleetbooking')
            . "</td></tr>";
    }

    echo "</tbody></table>";
    echo "<p style='margin-top: 10px; font-style: italic; color: #666;'>"
        . sprintf(__('Showing %d approved reservation(s) from the last 7 days.', 'fleetbooking'), $count)
        . "</p>";
    echo "</div>";

} else {
    // ---- Standard GLPI Search listing for full-access profiles ----
    global $DB;
    $desiredNums = [1, 5, 4, 2];

    $existingRows = iterator_to_array($DB->request([
        'SELECT' => ['num'],
        'FROM'   => 'glpi_displaypreferences',
        'WHERE'  => [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
            'users_id' => 0,
        ],
        'ORDER'  => 'rank ASC',
    ]));
    $storedNums = array_map('intval', array_column($existingRows, 'num'));

    if ($storedNums !== $desiredNums) {
        $DB->delete('glpi_displaypreferences', [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
        ]);
        foreach ($desiredNums as $rank => $num) {
            $DB->insert('glpi_displaypreferences', [
                'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
                'users_id' => 0,
                'num'      => $num,
                'rank'     => $rank + 1,
            ]);
        }
    }

    $currentUserId = (int) Session::getLoginUserID();
    if ($currentUserId > 0) {
        $DB->delete('glpi_displaypreferences', [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
            'users_id' => $currentUserId,
        ]);
    }

    // Inject default search criteria when no manual filter is applied.
    if (!isset($_GET['criteria']) || (is_array($_GET['criteria']) && count($_GET['criteria']) === 0)) {
        $_GET['criteria'] = [
            0 => [
                'link'       => 'AND',
                'field'      => 2,
                'searchtype' => 'morethan',
                'value'      => '-7DAY',
            ],
        ];
        $_GET['sort']     = [2];
        $_GET['order']    = ['DESC'];
        $_GET['itemtype'] = \GlpiPlugin\Fleetbooking\Request::class;
        $_GET['start']    = 0;
    }

    \Search::show(\GlpiPlugin\Fleetbooking\Request::class);
}

Html::footer();

