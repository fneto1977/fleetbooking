<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

if (
    !Session::haveRight('fleetbooking_request', READ)
    && !Session::haveRight('fleetbooking_approve', READ)
    && !Session::haveRight('fleetbooking_cancel', READ)
    && !Session::haveRight('fleetbooking_admin', READ)
) {
    Session::redirectIfNotLoggedIn();
    Html::displayRightError();
    exit;
}

// Validate CSRF token to prevent cross-site request forgery
Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');

$reqId = (int) ($_POST['request_id'] ?? 0);
$ticketId = (int) ($_POST['tickets_id'] ?? 0);
$reason = trim($_POST['cancel_reason'] ?? '');

$redirectUrl = ($ticketId > 0)
    ? Ticket::getFormURLWithID($ticketId)
    : \Plugin::getWebDir('fleetbooking') . '/front/request.php';

if ($reqId <= 0) {
    Session::addMessageAfterRedirect(__('Invalid reservation request.', 'fleetbooking'), false, ERROR);
    Html::redirect($redirectUrl);
    exit;
}

if (empty($reason)) {
    Session::addMessageAfterRedirect(__('Cancellation reason is mandatory.', 'fleetbooking'), false, ERROR);
    Html::redirect($redirectUrl);
    exit;
}

$request = new \GlpiPlugin\Fleetbooking\Request();
if (!$request->getFromDB($reqId)) {
    Session::addMessageAfterRedirect(__('Reservation request not found.', 'fleetbooking'), false, ERROR);
    Html::redirect($redirectUrl);
    exit;
}

$cancelService = new \GlpiPlugin\Fleetbooking\Service\CancelService();

try {
    $cancelService->cancel($request, $reason, (int) Session::getLoginUserID());
    Session::addMessageAfterRedirect(__('Reservation CANCELLED successfully.', 'fleetbooking'));
} catch (\Exception $e) {
    $safeMessage = str_replace(["\n", "\r", "\t"], ' ', $e->getMessage());
    \Toolbox::logInFile('fleetbooking', '[cancel.form.php] Error: ' . $safeMessage);
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

Html::redirect($redirectUrl);
