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
    global $DB;

    $request = new \GlpiPlugin\Fleetbooking\Request();

    $rows = $request->find(
        [
            'status' => \GlpiPlugin\Fleetbooking\Request::STATUS_APPROVED,
            'start_datetime' => ['>', date('Y-m-d H:i:s', strtotime('-7 days'))],
        ],
        'start_datetime DESC',
        0
    );

    $statusLabels = \GlpiPlugin\Fleetbooking\Request::getAllStatuses();
    $config = \GlpiPlugin\Fleetbooking\Config::getForEntity($_SESSION['glpiactive_entity'] ?? 0);
    $itemtype = $config['vehicle_itemtype'] ?? '';
    $baseUrl = \Plugin::getWebDir('fleetbooking') . '/front/request.readonly.php';

    $count = count($rows);

    echo "<div class='center' style='margin: 20px;'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<thead><tr>";
    echo "<th>" . __('Status', 'fleetbooking') . "</th>";
    echo "<th>" . __('Vehicle', 'fleetbooking') . "</th>";
    echo "<th>" . __('Requester', 'fleetbooking') . "</th>";
    echo "<th>" . __('Pickup Date', 'fleetbooking') . "</th>";
    echo "<th>" . __('Return Date', 'fleetbooking') . "</th>";
    echo "</tr></thead>";
    echo "<tbody>";

    foreach ($rows as $row) {
        $statusLabel = $statusLabels[$row['status']] ?? $row['status'];

        $requesterName = '';
        $requesterId = (int) ($row['requester_users_id'] ?? 0);
        if ($requesterId > 0) {
            $userRow = $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => \User::getTable(),
                'WHERE'  => ['id' => $requesterId],
            ])->current();
            if ($userRow) {
                $requesterName = formatUserName(
                    (int) $userRow['id'],
                    $userRow['name'] ?? '',
                    $userRow['realname'] ?? '',
                    $userRow['firstname'] ?? ''
                );
            }
        }
        if (empty($requesterName)) {
            $requesterName = __('N/A', 'fleetbooking');
        }

        $vehicleName = __('N/A', 'fleetbooking');
        if (!empty($itemtype) && class_exists($itemtype) && !empty($row['items_id'])) {
            $vehicleItem = new $itemtype();
            if ($vehicleItem->getFromDB((int) $row['items_id'])) {
                $vehicleName = $vehicleItem->fields['name'] ?? __('N/A', 'fleetbooking');
            }
        }

        $detailUrl = $baseUrl . '?id=' . (int) $row['id'];

        echo "<tr class='tab_bg_1'>";
        echo "<td><a href='" . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . "'>"
            . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . "</a></td>";
        echo "<td>" . htmlspecialchars((string) $vehicleName, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . Html::convDateTime($row['start_datetime']) . "</td>";
        echo "<td>" . Html::convDateTime($row['end_datetime']) . "</td>";
        echo "</tr>";
    }

    if ($count === 0) {
        echo "<tr class='tab_bg_1'><td colspan='5' class='center'>"
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

