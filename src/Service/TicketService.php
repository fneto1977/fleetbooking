<?php

namespace GlpiPlugin\Fleetbooking\Service;

use GlpiPlugin\Fleetbooking\Config;
use Ticket;
use Item_Ticket;
use ITILFollowup;

class TicketService
{

    public function createTicketForRequest(int|string $requestId, array $reqInput): int|false
    {
        $config = Config::getForEntity($reqInput['entities_id']);
        $categoryId = $config['itilcategories_id'] ?? 0;
        $ticketEntityId = (int) $reqInput['entities_id'];

        // Extract manager list (multi-manager support).
        // manager_users_ids is the full list; manager_users_id is the primary (BC).
        $managerIds = array_map('intval', $reqInput['manager_users_ids'] ?? [$reqInput['manager_users_id']]);
        $managerIds = array_filter($managerIds, fn(int $id) => $id > 0);
        $managerIds = array_values($managerIds);

        if (empty($managerIds)) {
            return false;
        }

        $ticket = new Ticket();

        $vehicleName = $reqInput['itemtype'];
        if (class_exists($reqInput['itemtype'])) {
            $item = new $reqInput['itemtype']();
            if ($item->getFromDB($reqInput['items_id'])) {
                $vehicleName = $item->getName();
            }
        }

        $content = sprintf(
            __('Vehicle Reservation Request: %1$s<br>Period: %2$s to %3$s<br>Reason: %4$s', 'fleetbooking'),
            $vehicleName,
            $reqInput['start_datetime'],
            $reqInput['end_datetime'],
            $reqInput['reason']
        );

        $ticketInput = [
            'entities_id'        => $ticketEntityId,
            'name'               => sprintf(__('Reservation for %s', 'fleetbooking'), $vehicleName),
            'content'            => $content,
            'itilcategories_id'  => $categoryId,
            'type'               => Ticket::DEMAND_TYPE,
            '_users_id_requester' => $reqInput['requester_users_id'],
            '_users_id_assign'   => $managerIds[0], // primary manager via ticket creation
        ];

        $ticketId = $ticket->add($ticketInput);

        if ($ticketId && count($managerIds) > 1) {
            // Add remaining managers as additional assignees
            $ticketUser = new \Ticket_User();
            foreach (array_slice($managerIds, 1) as $extraManagerId) {
                $ticketUser->add([
                    'tickets_id' => $ticketId,
                    'users_id'   => $extraManagerId,
                    'type'       => \CommonITILActor::ASSIGN,
                ]);
            }
        }

        if ($ticketId) {
            $itemTicket = new \Item_Ticket();
            $itemTicket->add([
                'tickets_id' => $ticketId,
                'itemtype'   => $reqInput['itemtype'],
                'items_id'   => $reqInput['items_id'],
            ]);

            // Add the configured observer group (gatehouse / portaria) to the ticket
            $observerGroupId = (int) ($config['observer_groups_id'] ?? 0);
            if ($observerGroupId > 0) {
                $groupTicket = new \Group_Ticket();
                $groupTicket->add([
                    'tickets_id' => $ticketId,
                    'groups_id'  => $observerGroupId,
                    'type'       => \CommonITILActor::OBSERVER,
                ]);
            }
        }

        return $ticketId;
    }

    public function addFollowup(int $ticketId, string $content, int $isPrivate = 0): void
    {
        $fup = new ITILFollowup();
        $fup->add([
            'items_id' => $ticketId,
            'itemtype' => 'Ticket',
            'content' => $content,
            'is_private' => $isPrivate
        ]);
    }

    public function closeTicket(int $ticketId): void
    {
        $ticket = new Ticket();
        if ($ticket->getFromDB($ticketId)) {
            // Guard: do not reopen a ticket that is already CLOSED by
            // setting it back to SOLVED (e.g. duplicate decision callback).
            if ((int) $ticket->fields['status'] === Ticket::CLOSED) {
                return;
            }
            $ticket->update([
                'id' => $ticketId,
                'status' => Ticket::SOLVED
            ]);
        }
    }
}
