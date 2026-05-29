<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// Validate CSRF token to prevent cross-site request forgery on
// approval / rejection actions that mutate reservation state.
Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');

$request = new \GlpiPlugin\Fleetbooking\Request();

if (!isset($_POST['fleetbooking_decision'])) {
    Html::redirect(Ticket::getFormURLWithID((int) ($_POST['tickets_id'] ?? 0)));
    exit;
}

$reqId = (int) ($_POST['request_id'] ?? 0);
$ticketId = (int) ($_POST['tickets_id'] ?? 0);
$decision = $_POST['fleetbooking_decision'] ?? '';
$comment = $_POST['decision_comment'] ?? '';

$ticketUrl = Ticket::getFormURLWithID($ticketId);

if (!in_array($decision, ['approve', 'reject'], true)) {
    Html::redirect($ticketUrl);
    exit;
}

if ($request->getFromDB($reqId)) {
    $isManager = ((int) $request->fields['manager_users_id'] === (int) Session::getLoginUserID()
        && (int) $request->fields['manager_users_id'] > 0);
    $isAdmin = Session::haveRight('fleetbooking_admin', READ);

    if (!$isManager && !$isAdmin) {
        Session::addMessageAfterRedirect(
            __('You do not have permission to decide on this reservation.', 'fleetbooking'),
            false,
            ERROR
        );
        Html::redirect($ticketUrl);
        exit;
    }

    $approvalService = new \GlpiPlugin\Fleetbooking\Service\ApprovalService();

    try {
        if ($decision === 'approve') {
            $result = $approvalService->approve($request, $comment, Session::getLoginUserID());
            if ($result === \GlpiPlugin\Fleetbooking\Request::STATUS_CONFLICT) {
                Session::addMessageAfterRedirect(
                    __('Date conflict detected. The reservation could not be approved.', 'fleetbooking'),
                    false,
                    WARNING
                );
            } else {
                Session::addMessageAfterRedirect(__('Reservation APPROVED successfully.', 'fleetbooking'));
            }
        } else {
            $approvalService->reject($request, $comment, Session::getLoginUserID());
            Session::addMessageAfterRedirect(__('Reservation REJECTED.', 'fleetbooking'));
        }
    } catch (\Exception $e) {
        $safeMessage = str_replace(["\n", "\r", "\t"], ' ', $e->getMessage());
        \Toolbox::logInFile('fleetbooking', __('Decision error: ', 'fleetbooking') . $safeMessage);
        Session::addMessageAfterRedirect(__('An internal error occurred while processing the decision.', 'fleetbooking'), false, ERROR);
    }
} else {
    Session::addMessageAfterRedirect(
        __('Reservation request not found.', 'fleetbooking'),
        false,
        ERROR
    );
}

Html::redirect($ticketUrl);
