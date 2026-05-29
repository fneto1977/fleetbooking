<?php

namespace GlpiPlugin\Fleetbooking\Service;

use GlpiPlugin\Fleetbooking\Request;
use GlpiPlugin\Fleetbooking\Config;
use GlpiPlugin\Fleetbooking\Service\TicketService;
use GlpiPlugin\Fleetbooking\Service\RequestService;
use GlpiPlugin\Fleetbooking\Service\ReservationService;

class ApprovalService
{

    public function approve(Request $request, string $comment = '', int $userId = 0): string
    {
        return $this->processDecision($request, 'approved', $comment, $userId);
    }

    public function reject(Request $request, string $comment = '', int $userId = 0): string
    {
        if (empty(trim($comment))) {
            throw new \Exception(__('Rejection comment is mandatory.', 'fleetbooking'));
        }
        return $this->processDecision($request, 'rejected', $comment, $userId);
    }

    public function autoApprove(Request $request): string
    {
        return $this->processDecision($request, 'approved', __('Auto-approved by system (Requester is Manager)', 'fleetbooking'), $request->fields['manager_users_id']);
    }

    private function processDecision(Request $request, string $decision, string $comment, int $userId): string
    {
        global $DB;

        $reqFields = $request->fields;

        // Lock mechanism to prevent race conditions
        $DB->beginTransaction();

        try {
            // Re-read the request with FOR UPDATE lock via raw SQL because the
            // GLPI query builder (DBmysql::request) does not recognise
            // 'FOR UPDATE' as a key and silently drops it from the generated
            // query, which would leave the row unlocked and the race condition open.
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
            // Trigger GLPI lifecycle hooks (post_getFromDB, computed fields, etc.)
            // that would normally fire via getFromDB(). The row is already locked
            // by FOR UPDATE, so a subsequent read without the lock clause is safe.
            $lockedRequest->getFromDB((int) $row['id']);

            $reqFields = $lockedRequest->fields;

            if ($reqFields['status'] !== Request::STATUS_PENDING) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Decision processing attempt failed: request #%s already processed (current status: %s).', 'fleetbooking'),
                    $lockedRequest->getID(),
                    $reqFields['status']
                ));
                throw new \Exception(__('This request has already been processed.', 'fleetbooking'));
            }

            $ticketService = new TicketService();
            $reservationId = null;
            $finalStatus = $decision;

            if ($decision === Request::STATUS_APPROVED) {
                $reqService = new RequestService();
                $conflicts = $reqService->getConflicts($reqFields['itemtype'], $reqFields['items_id'], $reqFields['start_datetime'], $reqFields['end_datetime']);

                if (count($conflicts) > 0) {
                    // Persist the CONFLICT status so the audit trail is visible
                    // to managers and the requester, then commit and return early
                    // to avoid the generic catch block attempting a rollback when
                    // the transaction is already committed.
                    $tz = new \DateTimeZone($_SESSION['glpi_tz'] ?? 'UTC');
                    $decisionDate = (new \DateTime('now', $tz))->format('Y-m-d H:i:s');
                    $lockedRequest->update([
                        'id' => $lockedRequest->getID(),
                        'status' => Request::STATUS_CONFLICT,
                        'decision_users_id' => $userId,
                        'decision_date' => $decisionDate,
                        'decision_comment' => __('Date conflict detected.', 'fleetbooking'),
                    ]);
                    $DB->commit();

                    \Toolbox::logInFile('fleetbooking', sprintf(
                        __('Approval attempt failed due to conflict: request #%s, vehicle %s %s, period %s to %s, manager %s.', 'fleetbooking'),
                        $lockedRequest->getID(),
                        $reqFields['itemtype'],
                        $reqFields['items_id'],
                        $reqFields['start_datetime'],
                        $reqFields['end_datetime'],
                        $userId
                    ));
                    return Request::STATUS_CONFLICT;
                }

                $resService = new ReservationService();
                try {
                    $reservationId = $resService->createReservation($reqFields, $comment);
                } catch (\Exception $e) {
                    \Toolbox::logInFile('fleetbooking', sprintf(
                        __('Error creating native reservation: request #%s, error: %s.', 'fleetbooking'),
                        $lockedRequest->getID(),
                        str_replace(["\n", "\r", "\t"], ' ', $e->getMessage())
                    ));
                    $DB->rollBack();
                    throw new \Exception(__('Internal error creating native reservation: ', 'fleetbooking') . $e->getMessage());
                }

                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Request #%s APPROVED successfully: vehicle %s %s, period %s to %s, manager %s, reservation #%s.', 'fleetbooking'),
                    $lockedRequest->getID(),
                    $reqFields['itemtype'],
                    $reqFields['items_id'],
                    $reqFields['start_datetime'],
                    $reqFields['end_datetime'],
                    $userId,
                    $reservationId
                ));

                $ticketService->addFollowup($reqFields['tickets_id'], sprintf(__('Reservation APPROVED and created successfully. (ID: %s)<br>Comment: %s', 'fleetbooking'), $reservationId, $comment));
            } else {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Request #%s REJECTED: vehicle %s %s, period %s to %s, manager %s, comment: %s.', 'fleetbooking'),
                    $lockedRequest->getID(),
                    $reqFields['itemtype'],
                    $reqFields['items_id'],
                    $reqFields['start_datetime'],
                    $reqFields['end_datetime'],
                    $userId,
                    $comment
                ));

                $ticketService->addFollowup($reqFields['tickets_id'], sprintf(__('Reservation REJECTED.<br>Comment: %s', 'fleetbooking'), $comment));
            }

            $tz = new \DateTimeZone($_SESSION['glpi_tz'] ?? 'UTC');
            $decisionDate = (new \DateTime('now', $tz))->format('Y-m-d H:i:s');
            $updatePayload = [
                'id' => $lockedRequest->getID(),
                'status' => $finalStatus,
                'decision_users_id' => $userId,
                'decision_date' => $decisionDate,
                'decision_comment' => $comment
            ];

            if ($reservationId) {
                $updatePayload['reservations_id'] = $reservationId;
            }

            $lockedRequest->update($updatePayload);

            $config = Config::getForEntity($reqFields['entities_id']);
            if ($config['auto_close_ticket_on_decision']) {
                $ticketService->closeTicket($reqFields['tickets_id']);
            }

            $DB->commit();
            return $finalStatus;

        } catch (\Exception $e) {
            try {
                $DB->rollBack();
            } catch (\Exception $rollbackError) {
                \Toolbox::logInFile('fleetbooking', __('Rollback failed: ', 'fleetbooking') . str_replace(["\n", "\r", "\t"], ' ', $rollbackError->getMessage()));
            }
            throw $e;
        }
    }
}
