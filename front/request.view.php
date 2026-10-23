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
            'FROM' => \Ticket_User::getTable(),
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
            && (int) $reqData['manager_users_id'] > 0);
        $isAdmin = Session::haveRight('fleetbooking_admin', READ);
        $hasApproveRight = Session::haveRight('fleetbooking_approve', READ);
        $hasCancelRight = Session::haveRight('fleetbooking_cancel', READ);
        $isGlpiAdmin = Session::haveRight('config', UPDATE);
        $showCalendar = ($isManager || $isAdmin || $isGlpiAdmin || $hasCancelRight || $isTechnician);
        $canDecide = (($isManager && $hasApproveRight) || $isAdmin) && ($reqData['status'] === 'pending');

        $cancelService = new \GlpiPlugin\Fleetbooking\Service\CancelService();
        $canCancel = $cancelService->canCancel($request, (int) Session::getLoginUserID());

        echo "<div class='center' style='margin: 20px;'>";
        echo "<h3>" . __('Fleet Booking Request Details', 'fleetbooking') . "</h3>";
        echo "<table class='tab_cadre_fixe'>";

        echo "<tr class='tab_bg_1'><th style='width: 30%'>" . __('Current Status', 'fleetbooking') . "</th>";
        $statusClasses = [
            'pending'   => 'fb-status-pending',
            'approved'  => 'fb-status-approved',
            'rejected'  => 'fb-status-rejected',
            'conflict'  => 'fb-status-conflict',
            'cancelled' => 'fb-status-cancelled',
        ];
        $statusClass = $statusClasses[$reqData['status']] ?? '';
        $statusLabel = \GlpiPlugin\Fleetbooking\Request::getAllStatuses()[$reqData['status']] ?? $reqData['status'];
        echo "<td><strong class='" . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . "</strong></td></tr>";

        $user = new User();
        $user->getFromDB($reqData['requester_users_id']);
        echo "<tr class='tab_bg_1'><th>" . __('Requester', 'fleetbooking') . "</th><td>" . htmlspecialchars($user->getName(), ENT_QUOTES, 'UTF-8') . "</td></tr>";

        echo "<tr class='tab_bg_1'><th>" . __('Start Date', 'fleetbooking') . "</th><td>" . Html::convDateTime($reqData['start_datetime']) . "</td></tr>";
        echo "<tr class='tab_bg_1'><th>" . __('End Date', 'fleetbooking') . "</th><td>" . Html::convDateTime($reqData['end_datetime']) . "</td></tr>";

        // Driver Information
        $idTypeLabel = ($reqData['driver_id_type'] ?? 'cpf') === 'registration' ? __('Employee ID / Registration', 'fleetbooking') : __('CPF', 'fleetbooking');
        $idValue = ($reqData['driver_id_type'] ?? 'cpf') === 'registration' ? ($reqData['driver_registration'] ?? '-') : ($reqData['driver_cpf'] ?? '-');
        echo "<tr class='tab_bg_1'><th>" . __('Driver Identification', 'fleetbooking') . "</th><td><strong>$idTypeLabel:</strong> " . htmlspecialchars($idValue, ENT_QUOTES, 'UTF-8') . "</td></tr>";

        $cnhInfo = htmlspecialchars($reqData['driver_cnh_number'] ?? '-', ENT_QUOTES, 'UTF-8');
        if (!empty($reqData['driver_cnh_category'])) {
            $cnhInfo .= ' (Cat: ' . htmlspecialchars($reqData['driver_cnh_category'], ENT_QUOTES, 'UTF-8') . ')';
        }
        echo "<tr class='tab_bg_1'><th>" . __('CNH Details', 'fleetbooking') . "</th><td>" . $cnhInfo . "</td></tr>";

        $cnhExpiryFormatted = !empty($reqData['driver_cnh_expiry']) ? Html::convDate($reqData['driver_cnh_expiry']) : '-';
        $isCnhExpired = false;
        if (!empty($reqData['driver_cnh_expiry']) && !empty($reqData['end_datetime'])) {
            $isCnhExpired = (substr($reqData['driver_cnh_expiry'], 0, 10) < substr($reqData['end_datetime'], 0, 10));
        }
        $cnhStatusBadge = $isCnhExpired
            ? "<span class='badge bg-danger' style='margin-left:8px;'>" . __('Expired on return date ⚠️', 'fleetbooking') . "</span>"
            : "<span class='badge bg-success' style='margin-left:8px;'>" . __('Valid ✅', 'fleetbooking') . "</span>";
        echo "<tr class='tab_bg_1'><th>" . __('CNH Expiry Date', 'fleetbooking') . "</th><td>" . $cnhExpiryFormatted . " " . $cnhStatusBadge . "</td></tr>";

        // Policy Acceptance Status
        $policyAcceptedInfo = !empty($reqData['policy_accepted_at'])
            ? sprintf(__('Accepted on %s (IP: %s)', 'fleetbooking'), Html::convDateTime($reqData['policy_accepted_at']), htmlspecialchars($reqData['policy_accepted_ip'] ?? '-', ENT_QUOTES, 'UTF-8'))
            : __('Not recorded', 'fleetbooking');
        echo "<tr class='tab_bg_1'><th>" . __('Vehicle Usage Policy', 'fleetbooking') . "</th><td>" . $policyAcceptedInfo . "</td></tr>";

        echo "<tr class='tab_bg_1'><th>" . __('Reason', 'fleetbooking') . "</th><td>" . nl2br(htmlspecialchars((string) ($reqData['reason'] ?? ''), ENT_QUOTES, 'UTF-8')) . "</td></tr>";

        if ($reqData['status'] !== 'pending') {
            $commentHeader = ($reqData['status'] === 'cancelled')
                ? __('Cancellation Reason', 'fleetbooking')
                : __('Decision Comment', 'fleetbooking');
            echo "<tr class='tab_bg_1'><th>" . $commentHeader . "</th><td>" . nl2br(htmlspecialchars((string) ($reqData['decision_comment'] ?? ''), ENT_QUOTES, 'UTF-8')) . "</td></tr>";
            if ($reqData['reservations_id']) {
                echo "<tr class='tab_bg_1'><th>" . __('Real Reservation ID', 'fleetbooking') . "</th><td>#" . $reqData['reservations_id'] . "</td></tr>";
            }
            if (!empty($reqData['term_document_id'])) {
                $docUrl = $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $reqData['term_document_id'];
                echo "<tr class='tab_bg_1'><th>" . __('Responsibility Term', 'fleetbooking') . "</th><td>";
                echo "<a href='" . htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8') . "' target='_blank' class='btn btn-sm btn-primary' style='display:inline-flex;align-items:center;gap:6px;'>";
                echo "<i class='ti ti-file-text'></i> " . __('Download Responsibility Term (PDF)', 'fleetbooking');
                echo "</a>";
                echo "</td></tr>";
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
        } else if ($reqData['status'] === 'pending' && !$canCancel) {
            echo "<div class='alert alert-info'>" . __('Only the responsible manager can approve or reject this reservation.', 'fleetbooking') . "</div>";
        }

        // Cancellation UI for Requester, Manager, Technician (fleetbooking_cancel), or Admin
        if ($canCancel) {
            $cancelUrl = Plugin::getWebDir('fleetbooking') . '/front/cancel.form.php';
            $confirmPrompt = __('Are you sure you want to cancel this reservation? This action cannot be undone and will release the vehicle.', 'fleetbooking');
            echo "<div style='margin-top: 25px; padding: 15px 20px; background: #fff5f5; border: 1px solid #fed7d7; border-radius: 6px; text-align: left;'>";
            echo "<h4 style='color: #c53030; margin-top: 0; margin-bottom: 8px;'><i class='ti ti-ban' style='vertical-align: middle; margin-right: 6px;'></i>" . __('Cancel Reservation', 'fleetbooking') . "</h4>";
            echo "<p style='color: #4a5568; font-size: 13px; margin-bottom: 12px;'>" . __('Cancelling will release the vehicle in the calendar, remove the schedule, and conclude this ticket.', 'fleetbooking') . "</p>";
            echo "<form method='post' action='" . htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') . "' onsubmit=\"return confirm('" . addslashes($confirmPrompt) . "');\">";
            echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
            echo "<input type='hidden' name='request_id' value='" . (int) $reqData['id'] . "'>";
            echo "<input type='hidden' name='tickets_id' value='" . (int) $ticketId . "'>";
            echo "<div style='margin-bottom: 12px;'>";
            echo "<label style='display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #2d3748;'>" . __('Cancellation Reason (mandatory):', 'fleetbooking') . "</label>";
            echo "<textarea name='cancel_reason' rows='3' required style='width: 100%; border: 1px solid #cbd5e0; border-radius: 4px; padding: 8px; box-sizing: border-box;' placeholder='" . htmlspecialchars(__('Please explain why this reservation is being cancelled...', 'fleetbooking'), ENT_QUOTES, 'UTF-8') . "'></textarea>";
            echo "</div>";
            echo "<div style='text-align: right;'>";
            echo "<button type='submit' class='fb-btn-cancel'>";
            echo "<i class='ti ti-x'></i> " . __('Confirm Cancellation', 'fleetbooking');
            echo "</button>";
            echo "</div>";
            echo "</form>";
            echo "</div>";
        } elseif ($reqData['status'] === 'cancelled') {
            echo "<div class='alert alert-secondary' style='margin-top: 20px; background: #edf2f7; border-color: #cbd5e0; color: #4a5568;'>";
            echo "<i class='ti ti-info-circle' style='margin-right: 6px;'></i>" . __('This reservation has been cancelled.', 'fleetbooking');
            echo "</div>";
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
