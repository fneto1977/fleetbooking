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
            'entities_id' => $ticketEntityId,
            'name' => sprintf(__('Reservation for %s', 'fleetbooking'), $vehicleName),
            'content' => $content,
            'itilcategories_id' => $categoryId,
            'type' => Ticket::DEMAND_TYPE,
            '_users_id_requester' => $reqInput['requester_users_id'],
            '_users_id_assign' => $reqInput['manager_users_id']
        ];

        $ticketId = $ticket->add($ticketInput);

        if ($ticketId) {
            $itemTicket = new Item_Ticket();
            $itemTicket->add([
                'tickets_id' => $ticketId,
                'itemtype' => $reqInput['itemtype'],
                'items_id' => $reqInput['items_id']
            ]);
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
