<?php

if (!defined('GLPI_ROOT')) {
    die(__("Sorry. You can't access this file directly", 'fleetbooking'));
}

$request = new \GlpiPlugin\Fleetbooking\Request();




try {
    global $DB;
    $ticketId = $item->getID();
    if ($request->getFromDBByCrit(['tickets_id' => $ticketId])) {
        $reqData = $request->fields;

        $isTechnician = false;
        $ticketUserIter = $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $ticketId,
                'users_id' => Session::getLoginUserID(),
                'type' => 2 // CommonITILActor::ASSIGN
            ]
        ])->current();
        if ($ticketUserIter && (int) $ticketUserIter['c'] > 0) {
            $isTechnician = true;
        }

        $isManager = ((int) $reqData['manager_users_id'] === (int) Session::getLoginUserID()
            && (int) $reqData['manager_users_id'] > 0) || $isTechnician;
        $isAdmin = Session::haveRight('fleetbooking_admin', READ);
        $isGlpiAdmin = Session::haveRight('config', UPDATE);
        $showCalendar = ($isManager || $isAdmin || $isGlpiAdmin);
        $canDecide = ($isManager || $isAdmin) && ($reqData['status'] === 'pending');

        echo "<div class='center' style='margin: 20px;'>";
        echo "<h3>" . __('Fleet Booking Request Details', 'fleetbooking') . "</h3>";
        echo "<table class='tab_cadre_fixe'>";

        echo "<tr class='tab_bg_1'><th style='width: 30%'>" . __('Current Status', 'fleetbooking') . "</th>";
        $statusClasses = [
            'pending' => 'fb-status-pending',
            'approved' => 'fb-status-approved',
            'rejected' => 'fb-status-rejected',
            'conflict' => 'fb-status-conflict',
        ];
        $statusClass = $statusClasses[$reqData['status']] ?? '';
        $statusLabel = \GlpiPlugin\Fleetbooking\Request::getAllStatuses()[$reqData['status']] ?? $reqData['status'];
        echo "<td><strong class='" . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . "'>" . __($statusLabel, 'fleetbooking') . "</strong></td></tr>";

        $user = new User();
        $user->getFromDB($reqData['requester_users_id']);
        echo "<tr class='tab_bg_1'><th>" . __('Requester', 'fleetbooking') . "</th><td>" . htmlspecialchars($user->getName(), ENT_QUOTES, 'UTF-8') . "</td></tr>";

        echo "<tr class='tab_bg_1'><th>" . __('Start Date', 'fleetbooking') . "</th><td>" . Html::convDateTime($reqData['start_datetime']) . "</td></tr>";
        echo "<tr class='tab_bg_1'><th>" . __('End Date', 'fleetbooking') . "</th><td>" . Html::convDateTime($reqData['end_datetime']) . "</td></tr>";

        echo "<tr class='tab_bg_1'><th>" . __('Reason', 'fleetbooking') . "</th><td>" . nl2br(htmlspecialchars((string) ($reqData['reason'] ?? ''), ENT_QUOTES, 'UTF-8')) . "</td></tr>";

        if ($reqData['status'] !== 'pending') {
            echo "<tr class='tab_bg_1'><th>" . __('Decision Comment', 'fleetbooking') . "</th><td>" . nl2br(htmlspecialchars((string) ($reqData['decision_comment'] ?? ''), ENT_QUOTES, 'UTF-8')) . "</td></tr>";
            if ($reqData['reservations_id']) {
                echo "<tr class='tab_bg_1'><th>" . __('Real Reservation ID', 'fleetbooking') . "</th><td>#" . $reqData['reservations_id'] . "</td></tr>";
            }
        }

        echo "</table>";

        if ($canDecide) {
            $actionUrl = Plugin::getWebDir('fleetbooking') . '/front/approval.form.php';
            echo "<form method='post' action='" . $actionUrl . "'>";
            echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
            echo "<input type='hidden' name='request_id' value='" . (int) $reqData['id'] . "'>";
            echo "<input type='hidden' name='tickets_id' value='" . (int) $ticketId . "'>";

            echo "<table class='tab_cadre_fixe' style='margin-top: 20px;'>";
            echo "<tr><th colspan='2'>" . __('Manager Action', 'fleetbooking') . "</th></tr>";
            echo "<tr class='tab_bg_1'><td>" . __('Mandatory Comment', 'fleetbooking') . "</td>";
            echo "<td><textarea name='decision_comment' rows='3' style='width: 90%;'></textarea></td></tr>";

            echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
            echo "<button type='submit' name='fleetbooking_decision' value='approve' class='fb-btn-approve'>" . __('Approve Request', 'fleetbooking') . "</button>";
            echo "<button type='submit' name='fleetbooking_decision' value='reject' class='fb-btn-reject'>" . __('Reject Request', 'fleetbooking') . "</button>";
            echo "</td></tr>";
            echo "</table>";
            echo "</form>";
        } else if ($reqData['status'] === 'pending') {
            echo "<div class='alert alert-info'>" . __('Only the responsible manager can approve or reject this reservation.', 'fleetbooking') . "</div>";
        }

        echo "</div>"; // Close center/details wrapper div

        if ($showCalendar) {
            // Embed FullCalendar
            $config = \GlpiPlugin\Fleetbooking\Config::getForEntity($_SESSION['glpiactive_entity'] ?? 0);
            $workdayStart = $config['workday_start'] ?? '07:00:00';
            $workdayEnd = $config['workday_end'] ?? '18:00:00';
            $initialDate = date('Y-m-d', strtotime($reqData['start_datetime']));
            $eventsUrl = Plugin::getWebDir('fleetbooking', true) . '/ajax/calendar.php?current_request_id=' . (int) $reqData['id'] . '&itemtype=' . urlencode($reqData['itemtype']) . '&items_id=' . (int) $reqData['items_id'];
            $fcUrl = Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.global.min.js';
            // Convert GLPI locale format (pt_BR, en_GB) to FullCalendar format (pt-br, en-gb)
            $glpiLanguage = $_SESSION['glpilanguage'] ?? 'pt_BR';
            $fcLocale = strtolower(str_replace('_', '-', $glpiLanguage));
            // Always load the pt-br locale script (only locale file bundled), but pass the
            // user's actual locale via data-locale so FullCalendar can fall back to its
            // built-in English defaults when the loaded locale doesn't match.
            $fcLocaleUrl = Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.' . $fcLocale . '.global.min.js';
            $fcLocaleFallbackUrl = Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.pt-br.global.min.js';
            $approvalJsUrl = Plugin::getWebDir('fleetbooking', true) . '/js/approval-calendar.js';

            echo "<div style='margin-top: 30px; border-top: 1px solid #ccc; padding-top: 20px;'>";
            echo "<h4>" . __('Fleet Occupancy Calendar', 'fleetbooking') . "</h4>";
            echo "<div id='approval-calendar'"
                . " data-locale='" . htmlspecialchars($fcLocale, ENT_QUOTES, 'UTF-8') . "'"
                . " data-initial-date='" . htmlspecialchars($initialDate, ENT_QUOTES, 'UTF-8') . "'"
                . " data-slot-min-time='" . htmlspecialchars($workdayStart, ENT_QUOTES, 'UTF-8') . "'"
                . " data-slot-max-time='" . htmlspecialchars($workdayEnd, ENT_QUOTES, 'UTF-8') . "'"
                . " data-events-url='" . htmlspecialchars($eventsUrl, ENT_QUOTES, 'UTF-8') . "'"
                . " data-fc-url='" . htmlspecialchars($fcUrl, ENT_QUOTES, 'UTF-8') . "'"
                . " data-fc-locale-url='" . htmlspecialchars($fcLocaleFallbackUrl, ENT_QUOTES, 'UTF-8') . "'"
                . " style='min-height: 400px; background: white; padding: 15px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;'></div>";
            echo "</div>";
            echo "<script src='" . htmlspecialchars($approvalJsUrl, ENT_QUOTES, 'UTF-8') . "' defer></script>";
        }
    } else {
        echo "<div class='center'>" . __('No reservation request found for this ticket.', 'fleetbooking') . "</div>";
    }
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
    echo "<div class='fb-alert fb-alert-danger'>";
    echo htmlspecialchars(__('An internal error occurred.', 'fleetbooking'));
    echo "</div>";
}
