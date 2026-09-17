<?php

namespace GlpiPlugin\Fleetbooking\Hook;

use CommonDBTM;
use GlpiPlugin\Fleetbooking\Service\ReservationService;

/**
 * Class ReservationHook
 *
 * Intercepts native GLPI reservation lifecycle events to enforce the
 * FleetBooking workflow and prevent direct vehicle reservations.
 *
 * Hook Documentation (GLPI 11 Rules §11.3):
 * 1. Why it exists:
 *    Vehicles configured in FleetBooking must only be booked through the formal
 *    FleetBooking lifecycle (ticket request, manager approval, driver validation,
 *    and responsibility terms). Direct bookings through GLPI's native reservation
 *    UI (Tools > Reservations) bypass these safety controls and create unmanaged
 *    vehicle usage.
 *
 * 2. When it runs:
 *    - pre_item_add: Runs right before a Reservation is inserted into the database.
 *    - pre_item_update: Runs right before an existing Reservation is updated in the database.
 *
 * 3. Which item types it affects:
 *    - Class: \Reservation (GLPI table: glpi_reservations)
 *    - Specifically targets reservations where the linked \ReservationItem matches
 *      a vehicle itemtype configured in FleetBooking.
 *
 * 4. What side effects it may produce:
 *    - Cancels insertion or update by setting $item->input = false.
 *    - Emits a localized warning flash message via \Session::addMessageAfterRedirect().
 *    - Writes security audit log entries to the fleetbooking log.
 */
class ReservationHook
{
    /**
     * @var bool In-memory authorization flag set by FleetBooking internal services.
     */
    private static bool $bypass = false;

    /**
     * Set internal bypass state.
     *
     * @param bool $bypass
     * @return void
     */
    public static function setBypass(bool $bypass): void
    {
        self::$bypass = $bypass;
    }

    /**
     * Check if internal bypass is currently active.
     *
     * @return bool
     */
    public static function isBypass(): bool
    {
        return self::$bypass;
    }

    /**
     * Hook callback for 'pre_item_add' on Reservation items.
     *
     * @param CommonDBTM $item The \Reservation item being added.
     * @return bool False if blocked, true if allowed.
     */
    public static function preItemAddReservation(CommonDBTM $item): bool
    {
        // 1. If authorized internally by FleetBooking approval workflow, allow through
        if (self::isBypass()) {
            return true;
        }

        if (!is_array($item->input)) {
            return true;
        }

        $reservationitems_id = (int) ($item->input['reservationitems_id'] ?? 0);
        if ($reservationitems_id <= 0) {
            return true;
        }

        $service = new ReservationService();
        if ($service->isFleetVehicleReservation($reservationitems_id)) {
            // Cancel insertion in GLPI CommonDBTM
            $item->input = false;

            if (class_exists('\\Session') && method_exists('\\Session', 'addMessageAfterRedirect')) {
                \Session::addMessageAfterRedirect(
                    __("Fleet vehicles cannot be reserved directly through standard GLPI reservations. To request a vehicle, please use the 'Vehicle Reservation' card on the Home page or the 'Tools' > 'Vehicle Requests' menu.", 'fleetbooking'),
                    false,
                    defined('ERROR') ? ERROR : 3
                );
            }

            if (class_exists('\\Toolbox') && method_exists('\\Toolbox', 'logInFile')) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    '[ReservationHook] Blocked direct reservation creation for ReservationItem #%d by user #%d.',
                    $reservationitems_id,
                    (int) ($_SESSION['glpiID'] ?? 0)
                ));
            }

            return false;
        }

        return true;
    }

    /**
     * Hook callback for 'pre_item_update' on Reservation items.
     *
     * @param CommonDBTM $item The \Reservation item being updated.
     * @return bool False if blocked, true if allowed.
     */
    public static function preItemUpdateReservation(CommonDBTM $item): bool
    {
        // 1. If authorized internally by FleetBooking, allow through
        if (self::isBypass()) {
            return true;
        }

        if (!is_array($item->input)) {
            return true;
        }

        $reservationitems_id = (int) ($item->input['reservationitems_id'] ?? $item->fields['reservationitems_id'] ?? 0);
        if ($reservationitems_id <= 0) {
            return true;
        }

        $service = new ReservationService();
        if ($service->isFleetVehicleReservation($reservationitems_id)) {
            // Cancel update in GLPI CommonDBTM
            $item->input = false;

            if (class_exists('\\Session') && method_exists('\\Session', 'addMessageAfterRedirect')) {
                \Session::addMessageAfterRedirect(
                    __("Fleet vehicle reservations cannot be modified directly through standard GLPI reservations. Please manage the request through the 'Vehicle Reservation' card on the Home page or the 'Tools' > 'Vehicle Requests' menu.", 'fleetbooking'),
                    false,
                    defined('ERROR') ? ERROR : 3
                );
            }

            if (class_exists('\\Toolbox') && method_exists('\\Toolbox', 'logInFile')) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    '[ReservationHook] Blocked direct reservation modification for ReservationItem #%d by user #%d.',
                    $reservationitems_id,
                    (int) ($_SESSION['glpiID'] ?? 0)
                ));
            }

            return false;
        }

        return true;
    }
}
