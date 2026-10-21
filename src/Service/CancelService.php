<?php

namespace GlpiPlugin\Fleetbooking\Service;

use GlpiPlugin\Fleetbooking\Request;
use GlpiPlugin\Fleetbooking\Hook\ReservationHook;
use GlpiPlugin\Fleetbooking\Service\TicketService;
use GlpiPlugin\Fleetbooking\Service\ReservationService;
use User;
use Session;
use Toolbox;
use Log;

class CancelService
{
    /**
     * Flag to prevent re-entrant cancellation loops between TicketHook and CancelService.
     */
    private static bool $inCancellationProcess = false;

    /**
     * Check if cancellation process is active.
     */
    public static function isInCancellationProcess(): bool
    {
        return self::$inCancellationProcess;
    }

    /**
     * Verify whether a given user has permission and temporal validity to cancel a request.
     *
     * Rules:
     * 1. Status must be 'pending' or 'approved'.
     * 2. Time limit: return date (end_datetime) must not be in the past (end_datetime >= NOW).
     * 3. Identity & Rights:
     *    - Requester can cancel their own request.
     *    - Manager of the request can cancel requests from their team or their own.
     *    - Any user with 'fleetbooking_cancel' right (e.g. Technicians).
     *    - Any user with 'fleetbooking_admin' right.
     *
     * @param Request $request
     * @param int $userId
     * @return bool
     */
    public function canCancel(Request $request, int $userId): bool
    {
        $status = $request->fields['status'] ?? '';
        if (!in_array($status, [Request::STATUS_PENDING, Request::STATUS_APPROVED], true)) {
            return false;
        }

        // Temporal rule: can cancel as long as the reservation has not ended
        $endDatetime = $request->fields['end_datetime'] ?? null;
        if (!empty($endDatetime)) {
            $tz = new \DateTimeZone($_SESSION['glpi_tz'] ?? 'UTC');
            try {
                $now = new \DateTime('now', $tz);
                $end = new \DateTime($endDatetime, $tz);
                if ($end < $now) {
                    return false; // Reservation has already finished
                }
            } catch (\Exception $e) {
                // Fallback to string comparison if DateTime parsing fails
                if ($endDatetime < date('Y-m-d H:i:s')) {
                    return false;
                }
            }
        }

        // Global admin privilege
        if (Session::haveRight('fleetbooking_admin', READ)) {
            return true;
        }

        // Dedicated cancellation right (e.g. Technician profile)
        if (Session::haveRight('fleetbooking_cancel', READ)) {
            return true;
        }

        // Requester himself
        $requesterId = (int) ($request->fields['requester_users_id'] ?? 0);
        if ($requesterId > 0 && $requesterId === $userId) {
            return true;
        }

        // Direct designated manager of this request
        $managerId = (int) ($request->fields['manager_users_id'] ?? 0);
        if ($managerId > 0 && $managerId === $userId) {
            return true;
        }

        // Manager of the requester group
        $groupId = (int) ($request->fields['requester_groups_id'] ?? 0);
        if ($groupId > 0 && $userId > 0) {
            global $DB;
            $isGroupMgr = $DB->request([
                'COUNT' => 'c',
                'FROM'  => \Group_User::getTable(),
                'WHERE' => [
                    'groups_id'  => $groupId,
                    'users_id'   => $userId,
                    'is_manager' => 1
                ]
            ])->current()['c'] > 0;

            if ($isGroupMgr) {
                return true;
            }
        }

        return false;
    }

    /**
     * Executes the cancellation workflow in a database transaction with table lock.
     *
     * @param Request $request
     * @param string $reason Mandatory justification text.
     * @param int $userId ID of user performing the cancellation.
     * @param bool $fromTicketHook True if triggered automatically by GLPI Ticket update/purge hook.
     * @return string Final status ('cancelled').
     * @throws \Exception
     */
    public function cancel(Request $request, string $reason, int $userId, bool $fromTicketHook = false): string
    {
        global $DB;

        if (empty(trim($reason))) {
            throw new \Exception(__('Cancellation reason is mandatory.', 'fleetbooking'));
        }

        if (!$fromTicketHook && !$this->canCancel($request, $userId)) {
            throw new \Exception(__('You do not have permission to cancel this reservation or the reservation period has already ended.', 'fleetbooking'));
        }

        self::$inCancellationProcess = true;
        $DB->beginTransaction();

        try {
            // Re-read row with FOR UPDATE lock to avoid race conditions
            $lockResult = $DB->doQuery(sprintf(
                "SELECT * FROM `glpi_plugin_fleetbooking_requests` WHERE `id` = %d FOR UPDATE",
                (int) $request->getID()
            ));
            $row = null;
            foreach ($lockResult as $r) {
                $row = $r;
                break;
            }
            if (!$row) {
                $DB->rollBack();
                throw new \Exception(__('Request not found.', 'fleetbooking'));
            }

            $lockedRequest = new Request();
            $lockedRequest->fields = $row;
            $lockedRequest->getFromDB((int) $row['id']);
            $reqFields = $lockedRequest->fields;

            // Only pending or approved requests can be cancelled
            if (!in_array($reqFields['status'], [Request::STATUS_PENDING, Request::STATUS_APPROVED], true)) {
                $DB->rollBack();
                Toolbox::logInFile('fleetbooking', sprintf(
                    '[CancelService] Cancellation attempt rejected: request #%s is already %s.',
                    $lockedRequest->getID(),
                    $reqFields['status']
                ));
                throw new \Exception(__('This request cannot be cancelled because it is not pending or approved.', 'fleetbooking'));
            }

            // 1. Purge native GLPI Reservation if it exists
            $reservationId = (int) ($reqFields['reservations_id'] ?? 0);
            if ($reservationId > 0) {
                $nativeRes = new \Reservation();
                if ($nativeRes->getFromDB($reservationId)) {
                    ReservationHook::setBypass(true);
                    try {
                        // Purge permanently from glpi_reservations to free vehicle slot in calendar
                        $nativeRes->delete(['id' => $reservationId], 1);
                        Toolbox::logInFile('fleetbooking', sprintf(
                            '[CancelService] Native reservation #%d purged successfully for fleet request #%s.',
                            $reservationId,
                            $lockedRequest->getID()
                        ));
                    } catch (\Exception $e) {
                        Toolbox::logInFile('fleetbooking', sprintf(
                            '[CancelService] Error purging native reservation #%d: %s',
                            $reservationId,
                            $e->getMessage()
                        ));
                        throw $e;
                    } finally {
                        ReservationHook::setBypass(false);
                    }
                }
            }

            // 2. Update Request status to cancelled
            $tz = new \DateTimeZone($_SESSION['glpi_tz'] ?? 'UTC');
            $decisionDate = (new \DateTime('now', $tz))->format('Y-m-d H:i:s');
            $actorUser = new User();
            $actorName = ($userId > 0 && $actorUser->getFromDB($userId))
                ? $actorUser->getName()
                : __('System', 'fleetbooking');

            $lockedRequest->update([
                'id'                => $lockedRequest->getID(),
                'status'            => Request::STATUS_CANCELLED,
                'decision_users_id' => $userId,
                'decision_date'     => $decisionDate,
                'decision_comment'  => $reason,
                'reservations_id'   => null,
            ]);

            // 3. GLPI Audit Trail & Plugin Logs
            if (class_exists('Log') && method_exists('Log', 'history')) {
                Log::history(
                    $lockedRequest->getID(),
                    Request::class,
                    [0, '', ''],
                    0,
                    Log::HISTORY_LOG_SIMPLE_MESSAGE,
                    sprintf(__('Reservation cancelled by %1$s. Reason: %2$s', 'fleetbooking'), $actorName, $reason)
                );
            }

            Toolbox::logInFile('fleetbooking', sprintf(
                '[CancelService] Request #%s CANCELLED by user %s (%d). Vehicle %s #%s. Reason: %s',
                $lockedRequest->getID(),
                $actorName,
                $userId,
                $reqFields['itemtype'],
                $reqFields['items_id'],
                $reason
            ));

            // 4. Update linked Ticket (Followup and Solution/Closure)
            $ticketId = (int) ($reqFields['tickets_id'] ?? 0);
            if ($ticketId > 0 && !$fromTicketHook) {
                $ticketService = new TicketService();
                $ticketService->addFollowup(
                    $ticketId,
                    sprintf(
                        __('Reservation CANCELLED.<br>Cancelled by: %1$s<br>Reason: %2$s', 'fleetbooking'),
                        htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8'),
                        nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'))
                    ),
                    0 // Public followup
                );

                $ticketService->closeTicket($ticketId);
            }

            $DB->commit();
            return Request::STATUS_CANCELLED;

        } catch (\Exception $e) {
            try {
                $DB->rollBack();
            } catch (\Exception $rollbackError) {
                Toolbox::logInFile('fleetbooking', '[CancelService] Rollback failed: ' . $rollbackError->getMessage());
            }
            throw $e;
        } finally {
            self::$inCancellationProcess = false;
        }
    }
}
