<?php

namespace GlpiPlugin\Fleetbooking\Hook;

use CommonDBTM;
use Ticket;
use GlpiPlugin\Fleetbooking\Request;
use GlpiPlugin\Fleetbooking\Service\CancelService;
use GlpiPlugin\Fleetbooking\Service\ApprovalService;
use Toolbox;
use Session;
use User;

/**
 * Class TicketHook
 *
 * Synchronizes GLPI native Ticket lifecycle events with FleetBooking requests.
 * Specifically, when a Ticket is closed, solved, deleted, or purged directly in GLPI
 * (e.g. by an administrator or technician), the linked fleet reservation is automatically
 * cancelled and any active vehicle slot is cleanly released in the calendar.
 */
class TicketHook
{
    /**
     * Hook callback for 'item_update' on Ticket items.
     *
     * @param CommonDBTM $item The \Ticket item being updated.
     * @return void
     */
    public static function itemUpdateTicket(CommonDBTM $item): void
    {
        // Guard 1: Ignore updates originated within FleetBooking cancellation or approval flows
        if (CancelService::isInCancellationProcess() || ApprovalService::isInApprovalProcess()) {
            return;
        }

        if (!$item instanceof Ticket && (!method_exists($item, 'getType') || $item->getType() !== 'Ticket')) {
            return;
        }

        $ticketId = (int) $item->getID();
        if ($ticketId <= 0) {
            return;
        }

        // Guard 2: Only trigger if status actually changed to SOLVED/CLOSED or ticket was moved to trash
        $status = (int) ($item->fields['status'] ?? 0);
        $isDeleted = !empty($item->fields['is_deleted']);

        $statusChanged = isset($item->oldvalues['status'])
            && in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true)
            && !in_array((int) $item->oldvalues['status'], [Ticket::SOLVED, Ticket::CLOSED], true);

        $trashChanged = isset($item->oldvalues['is_deleted']) && $isDeleted;

        if (!$statusChanged && !$trashChanged) {
            return;
        }

        $request = new Request();
        if (!$request->getFromDBByCrit(['tickets_id' => $ticketId])) {
            return;
        }

        $currentStatus = $request->fields['status'] ?? '';
        if (!in_array($currentStatus, [Request::STATUS_PENDING, Request::STATUS_APPROVED], true)) {
            return;
        }

        $actorId = (int) (Session::getLoginUserID() ?: ($item->fields['users_id_lastupdater'] ?? 0));
        $actorUser = new User();
        $actorName = ($actorId > 0 && $actorUser->getFromDB($actorId))
            ? $actorUser->getName()
            : __('GLPI Administrator', 'fleetbooking');

        $reason = sprintf(
            __('Cancelled automatically because Ticket #%1$d was solved/closed or moved to trash in GLPI by %2$s.', 'fleetbooking'),
            $ticketId,
            $actorName
        );

        Toolbox::logInFile('fleetbooking', sprintf(
            '[TicketHook] Ticket #%d changed (status=%d, deleted=%d). Auto-cancelling fleet request #%s by user %s (%d).',
            $ticketId,
            $status,
            $isDeleted ? 1 : 0,
            $request->getID(),
            $actorName,
            $actorId
        ));

        $cancelService = new CancelService();
        try {
            $cancelService->cancel($request, $reason, $actorId, true);
        } catch (\Exception $e) {
            Toolbox::logInFile('fleetbooking', sprintf(
                '[TicketHook] Error during auto-cancellation of request #%s from ticket #%d: %s',
                $request->getID(),
                $ticketId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Hook callback for 'pre_item_purge' on Ticket items.
     *
     * @param CommonDBTM $item The \Ticket item being purged.
     * @return void
     */
    public static function preItemPurgeTicket(CommonDBTM $item): void
    {
        if (CancelService::isInCancellationProcess() || ApprovalService::isInApprovalProcess()) {
            return;
        }

        $ticketId = (int) $item->getID();
        if ($ticketId <= 0) {
            return;
        }

        $request = new Request();
        if (!$request->getFromDBByCrit(['tickets_id' => $ticketId])) {
            return;
        }

        $currentStatus = $request->fields['status'] ?? '';
        if (!in_array($currentStatus, [Request::STATUS_PENDING, Request::STATUS_APPROVED], true)) {
            return;
        }

        $actorId = (int) (Session::getLoginUserID() ?: 0);
        $reason = sprintf(
            __('Cancelled automatically because Ticket #%1$d was permanently purged from GLPI.', 'fleetbooking'),
            $ticketId
        );

        Toolbox::logInFile('fleetbooking', sprintf(
            '[TicketHook] Ticket #%d is being purged. Auto-cancelling fleet request #%s.',
            $ticketId,
            $request->getID()
        ));

        $cancelService = new CancelService();
        try {
            $cancelService->cancel($request, $reason, $actorId, true);
        } catch (\Exception $e) {
            Toolbox::logInFile('fleetbooking', sprintf(
                '[TicketHook] Error auto-cancelling request #%s from purged ticket #%d: %s',
                $request->getID(),
                $ticketId,
                $e->getMessage()
            ));
        }
    }
}
