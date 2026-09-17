<?php

namespace GlpiPlugin\Fleetbooking\Service;

use GlpiPlugin\Fleetbooking\Config;
use GlpiPlugin\Fleetbooking\Hook\ReservationHook;

class ReservationService
{
    /** @var array<string, bool>|null Cache of configured vehicle itemtypes */
    private static ?array $cachedVehicleTypes = null;

    /**
     * Reset cached vehicle types (useful for tests).
     *
     * @return void
     */
    public static function resetCache(): void
    {
        self::$cachedVehicleTypes = null;
    }

    /**
     * Checks whether a given ReservationItem corresponds to a vehicle managed by FleetBooking.
     *
     * @param int $reservationitems_id ID of the glpi_reservationitems record.
     * @return bool True if the item is a fleet vehicle, false otherwise.
     */
    public function isFleetVehicleReservation(int $reservationitems_id): bool
    {
        if ($reservationitems_id <= 0) {
            return false;
        }

        $resItem = new \ReservationItem();
        if (!$resItem->getFromDB($reservationitems_id)) {
            return false;
        }

        $itemtype = $resItem->fields['itemtype'] ?? '';
        if (empty($itemtype)) {
            return false;
        }

        // 1. Check default custom asset vehicle type
        if ($itemtype === 'Glpi\\CustomAsset\\VeiculofrotaAsset') {
            return true;
        }

        // 2. Check entity-specific configured vehicle type
        $entitiesId = (int) ($resItem->fields['entities_id'] ?? 0);
        $config = Config::getForEntity($entitiesId);
        if (!empty($config['vehicle_itemtype']) && $config['vehicle_itemtype'] === $itemtype) {
            return true;
        }

        // 3. Check all configured vehicle types across all entities (cached per-request)
        if (self::$cachedVehicleTypes === null) {
            self::$cachedVehicleTypes = [];
            global $DB;
            if ($DB && $DB->tableExists(Config::getTable())) {
                $iterator = $DB->request([
                    'SELECT'   => ['vehicle_itemtype'],
                    'DISTINCT' => true,
                    'FROM'     => Config::getTable(),
                    'WHERE'    => ['NOT' => ['vehicle_itemtype' => null]],
                ]);
                foreach ($iterator as $row) {
                    if (!empty($row['vehicle_itemtype'])) {
                        self::$cachedVehicleTypes[$row['vehicle_itemtype']] = true;
                    }
                }
            }
        }

        return isset(self::$cachedVehicleTypes[$itemtype]);
    }

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
            '_from_fleetbooking' => true,
        ];

        ReservationHook::setBypass(true);
        try {
            $resId = $reservation->add($resInput);
        } catch (\Exception $e) {
            \Toolbox::logInFile('fleetbooking', __('RESERVATION EXCEPTION: ', 'fleetbooking') . str_replace(["\n", "\r", "\t"], ' ', $e->getMessage()) . "\n" . $e->getTraceAsString());
            throw $e;
        } finally {
            ReservationHook::setBypass(false);
        }

        if (!$resId) {
            throw new \Exception(__('Failed to insert reservation into the system.', 'fleetbooking'));
        }

        return $resId;
    }
}

