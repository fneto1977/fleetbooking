<?php

namespace GlpiPlugin\Fleetbooking\Service;

class ReservationService
{

    public function createReservation(array $reqFields, string $comment): int
    {
        $resItem = new \ReservationItem();

        // Load the reservation item natively
        if (!$resItem->getFromDBByItem($reqFields['itemtype'], $reqFields['items_id'])) {
            // Try to create the reservation item mapping if it doesn't exist
            $itemId = $resItem->add([
                'itemtype' => $reqFields['itemtype'],
                'items_id' => $reqFields['items_id'],
                'entities_id' => $reqFields['entities_id'],
                'is_active' => 1
            ]);

            if (!$itemId) {
                throw new \Exception(__('Fleet item is not configured to allow reservations in GLPI.', 'fleetbooking'));
            }
            $resItem->getFromDB($itemId);
        }

        $resItemId = $resItem->getID();
        if (empty($resItemId)) {
            throw new \Exception(sprintf(__('Failed to load reservation item ID (%1$s %2$s)', 'fleetbooking'), $reqFields['itemtype'], $reqFields['items_id']));
        }

        $reservation = new \Reservation();

        // Normalize dates: explicit DateTime parsing to handle both ISO 8601 and MySQL formats.
        // Use GLPI's configured timezone (falling back to UTC) to avoid silent date shifts
        // when the server default timezone differs from the GLPI instance setting.
        $tz = new \DateTimeZone($_SESSION['glpi_tz'] ?? 'UTC');
        try {
            $startDt = (new \DateTime($reqFields['start_datetime'], $tz))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            throw new \Exception(sprintf(
                __('Invalid date format for start_datetime: %s', 'fleetbooking'),
                $reqFields['start_datetime']
            ));
        }
        try {
            $endDt = (new \DateTime($reqFields['end_datetime'], $tz))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            throw new \Exception(sprintf(
                __('Invalid date format for end_datetime: %s', 'fleetbooking'),
                $reqFields['end_datetime']
            ));
        }

        $resInput = [
            'reservationitems_id' => $resItem->getID(),
            'users_id' => $reqFields['requester_users_id'],
            'begin' => $startDt,
            'end' => $endDt,
            'entities_id' => $reqFields['entities_id'],
            'comment' => sprintf(__('Approved via Ticket #%1$s (FleetBooking Plugin). Message: %2$s', 'fleetbooking'), $reqFields['tickets_id'], $comment),
            '_disablenotif' => true,
        ];

        try {
            $resId = $reservation->add($resInput);
        } catch (\Exception $e) {
            \Toolbox::logInFile('fleetbooking', __('RESERVATION EXCEPTION: ', 'fleetbooking') . str_replace(["\n", "\r", "\t"], ' ', $e->getMessage()) . "\n" . $e->getTraceAsString());
            throw $e;
        }

        if (!$resId) {
            throw new \Exception(__('Failed to insert reservation into the system.', 'fleetbooking'));
        }

        return $resId;
    }
}
