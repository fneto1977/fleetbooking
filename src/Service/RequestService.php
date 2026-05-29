<?php

namespace GlpiPlugin\Fleetbooking\Service;

use GlpiPlugin\Fleetbooking\Request;
use GlpiPlugin\Fleetbooking\Holiday;
use GlpiPlugin\Fleetbooking\Config;
use GlpiPlugin\Fleetbooking\GroupManager;
use GlpiPlugin\Fleetbooking\Service\TicketService;
use GlpiPlugin\Fleetbooking\Service\ApprovalService;

class RequestService
{

    /**
     * Validates date viability and business rules; returns ok, errors, and conflicts.
     *
     * @param string      $itemtype     The vehicle itemtype (e.g. PluginGenericobjectVehicle).
     * @param int         $items_id     The vehicle ID.
     * @param string      $start        Start datetime.
     * @param string      $end          End datetime.
     * @param int|null    $entities_id  Entity context for config lookup; falls back to
     *                                  active session entity when omitted.
     */
    public function checkAvailability(string $itemtype, int $items_id, string $start, string $end, ?int $entities_id = null): array
    {
        $result = [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'conflicts' => []
        ];

        $tz = new \DateTimeZone($_SESSION['glpi_tz'] ?? 'UTC');
        try {
            $startTime = new \DateTimeImmutable($start, $tz);
            $endTime = new \DateTimeImmutable($end, $tz);
        } catch (\Exception $e) {
            $result['ok'] = false;
            $result['errors'][] = __('Invalid date/time format.', 'fleetbooking');
            return $result;
        }

        if ($endTime <= $startTime) {
            $result['ok'] = false;
            $result['errors'][] = __('End date/time must be greater than start date/time.', 'fleetbooking');
            return $result;
        }

        $entities_id = $entities_id ?? ($_SESSION['glpiactive_entity'] ?? 0);
        $config = Config::getForEntity($entities_id);
        $workStart = $config['workday_start'];
        $workEnd = $config['workday_end'];

        $startDateStr = $startTime->format('Y-m-d');
        $endDateStr = $endTime->format('Y-m-d');

        $startDayOfWeek = (int) $startTime->format('N');
        $endDayOfWeek = (int) $endTime->format('N');
        $startTimeOfDay = $startTime->format('H:i:s');
        $endTimeOfDay = $endTime->format('H:i:s');

        if ($startDayOfWeek > 5) {
            $result['ok'] = false;
            $result['errors'][] = __('Pickup is allowed only on business days.', 'fleetbooking');
        }
        if ($endDayOfWeek > 5) {
            $result['ok'] = false;
            $result['errors'][] = __('Return is allowed only on business days.', 'fleetbooking');
        }

        if ($startTimeOfDay < $workStart || $startTimeOfDay > $workEnd) {
            $result['ok'] = false;
            $result['errors'][] = sprintf(__('Pickup is allowed only between %1$s and %2$s.', 'fleetbooking'), $workStart, $workEnd);
        }
        if ($endTimeOfDay < $workStart || $endTimeOfDay > $workEnd) {
            $result['ok'] = false;
            $result['errors'][] = sprintf(__('Return is allowed only between %1$s and %2$s.', 'fleetbooking'), $workStart, $workEnd);
        }

        $holiday = new Holiday();
        if ($holiday->getFromDBByCrit(['holiday_date' => $startDateStr])) {
            $result['ok'] = false;
            $result['errors'][] = __('Pickup is not allowed on holidays.', 'fleetbooking');
        }
        if ($holiday->getFromDBByCrit(['holiday_date' => $endDateStr])) {
            $result['ok'] = false;
            $result['errors'][] = __('Return is not allowed on holidays.', 'fleetbooking');
        }

        if ($result['ok']) {
            $conflicts = $this->getConflicts($itemtype, $items_id, $start, $end);
            if (count($conflicts) > 0) {
                $result['ok'] = false;
                $result['conflicts'] = $conflicts;
            }
        }

        return $result;
    }

    /**
     * Returns array of conflicts for the requested period (checks approved Requests and native Reservations).
     */
    public function getConflicts(string $itemtype, int $items_id, string $start, string $end): array
    {
        global $DB;
        $conflicts = [];

        // Use glpi_reservations as the FROM table (instead of
        // glpi_reservationitems) to prevent GLPI's DB layer from
        // auto-injecting entities_id on the wrong table.
        // glpi_reservations has no entities_id column, so the
        // iterator will not attempt entity filtering at all.
        // The same pattern is used in CalendarService::getEvents().
        $resQuery = [
            'FROM' => 'glpi_reservations',
            'INNER JOIN' => [
                'glpi_reservationitems' => [
                    'ON' => [
                        'glpi_reservations' => 'reservationitems_id',
                        'glpi_reservationitems' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_reservationitems.itemtype' => $itemtype,
                'glpi_reservationitems.items_id' => $items_id,
                'glpi_reservations.begin' => ['<', $end],
                'glpi_reservations.end' => ['>', $start]
            ]
        ];

        $iterator = $DB->request($resQuery);
        foreach ($iterator as $data) {
            $conflicts[] = [
                'source' => 'reservation',
                'id' => $data['id'],
                'start' => $data['begin'],
                'end' => $data['end'],
            ];
        }

        return $conflicts;
    }

    /**
     * Persists the booking request and dispatches ticket creation.
     */
    public function createRequest(array $input, int $requesterId): int
    {
        $sanitizedInput = [
            'itemtype' => (string) ($input['itemtype'] ?? ''),
            'items_id' => (int) ($input['items_id'] ?? 0),
            'start_datetime' => (string) ($input['start_datetime'] ?? ''),
            'end_datetime' => (string) ($input['end_datetime'] ?? ''),
            'reason' => \Toolbox::addslashes_deep((string) ($input['reason'] ?? '')),
            'entities_id' => (int) ($input['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0)),
        ];

        $entity_id = $sanitizedInput['entities_id'];
        $result = $this->checkAvailability(
            $sanitizedInput['itemtype'],
            $sanitizedInput['items_id'],
            $sanitizedInput['start_datetime'],
            $sanitizedInput['end_datetime'],
            $entity_id
        );

        if (!$result['ok']) {
            \Toolbox::logInFile('fleetbooking', sprintf(
                __('Request creation attempt failed: user %s, vehicle %s %s, period %s to %s. Reason: %s', 'fleetbooking'),
                $requesterId,
                $sanitizedInput['itemtype'],
                $sanitizedInput['items_id'],
                $sanitizedInput['start_datetime'],
                $sanitizedInput['end_datetime'],
                implode(', ', $result['errors'])
            ));
            throw new \Exception(__('Period unavailable or violates rules.', 'fleetbooking'));
        }

        $config = Config::getForEntity($entity_id);
        $target_entity_id = (int) ($config['default_tickets_entities_id'] ?? $entity_id);
        if ($target_entity_id <= 0) {
            $target_entity_id = $entity_id;
        }

        $group = $this->resolveManagerGroup($requesterId, $entity_id);
        $group_id = $group['id'];

        $manager_id = 0;

        // Native GLPI 11 Group Manager lookup
        global $DB;
        $manager_iter = $DB->request([
            'SELECT' => ['users_id'],
            'FROM' => 'glpi_groups_users',
            'WHERE' => [
                'groups_id' => $group_id,
                'is_manager' => 1
            ],
            'LIMIT' => 1
        ]);

        foreach ($manager_iter as $manager_row) {
            $manager_id = (int) $manager_row['users_id'];
        }

        if (!$manager_id) {
            \Toolbox::logInFile('fleetbooking', sprintf(
                __('Request creation attempt failed: group %s has no manager configured.', 'fleetbooking'),
                $group_id
            ));
            throw new \Exception(__('Group without manager configured. Please request a GLPI administrator to configure a Manager for your user group.', 'fleetbooking'));
        }

        $request = new Request();
        $reqInput = [
            'entities_id' => $target_entity_id,
            'requester_users_id' => $requesterId,
            'requester_groups_id' => $group_id,
            'manager_users_id' => $manager_id,
            'itemtype' => $sanitizedInput['itemtype'],
            'items_id' => $sanitizedInput['items_id'],
            'start_datetime' => $sanitizedInput['start_datetime'],
            'end_datetime' => $sanitizedInput['end_datetime'],
            'reason' => $sanitizedInput['reason'],
            'status' => Request::STATUS_PENDING
        ];

        $reqId = $request->add($reqInput);

        if (!$reqId) {
            \Toolbox::logInFile('fleetbooking', sprintf(
                __('Error saving request to database: user %s, vehicle %s %s, period %s to %s.', 'fleetbooking'),
                $requesterId,
                $sanitizedInput['itemtype'],
                $sanitizedInput['items_id'],
                $sanitizedInput['start_datetime'],
                $sanitizedInput['end_datetime']
            ));
            throw new \Exception(__('Error saving request to database.', 'fleetbooking'));
        }

        \Toolbox::logInFile('fleetbooking', sprintf(
            __('Request #%s created successfully: user %s, vehicle %s %s, period %s to %s, manager %s.', 'fleetbooking'),
            $reqId,
            $requesterId,
            $sanitizedInput['itemtype'],
            $sanitizedInput['items_id'],
            $sanitizedInput['start_datetime'],
            $sanitizedInput['end_datetime'],
            $manager_id
        ));

        $ticketService = new TicketService();
        $ticketId = $ticketService->createTicketForRequest($reqId, $reqInput);

        // createTicketForRequest returns int|false; a falsy result means the
        // ticket could not be created and we must clean up the orphaned
        // request row instead of silently writing a broken tickets_id.
        if (!$ticketId) {
            \Toolbox::logInFile('fleetbooking', sprintf(
                __('Failed to create ticket for request #%s. Removing orphaned request.', 'fleetbooking'),
                $reqId
            ));
            $request->delete(['id' => $reqId], 1);
            throw new \Exception(__('Error creating follow-up ticket.', 'fleetbooking'));
        }

        $request->update(['id' => $reqId, 'tickets_id' => $ticketId]);

        if ($requesterId == $manager_id) {
            $request->getFromDB($reqId);
            $approvalService = new ApprovalService();
            $result = $approvalService->autoApprove($request);
            if ($result === Request::STATUS_CONFLICT) {
                \Toolbox::logInFile('fleetbooking', sprintf(
                    __('Auto-approval of request #%s set status to CONFLICT (date conflict).', 'fleetbooking'),
                    $reqId
                ));
            }
        }

        return $reqId;
    }

    /**
     * Resolve the best group for manager assignment.
     *
     * Priority:
     * 1. Groups with managers configured in FleetBooking GroupManager (entity-scoped)
     * 2. Groups where the user is a delegate/manager in GLPI
     * 3. Fallback: first group the user belongs to
     *
     * @param int $requesterId The user ID requesting the vehicle.
     * @param int $entitiesId  The entity ID for the request, scoping the GroupManager lookup.
     * @return array The resolved group data.
     * @throws \Exception If the user belongs to no groups.
     */
    private function resolveManagerGroup(int $requesterId, int $entitiesId): array
    {
        global $DB;

        $groups = \Group_User::getUserGroups($requesterId);
        if (empty($groups)) {
            throw new \Exception(__('User does not belong to any group.', 'fleetbooking'));
        }

        // Priority 1: groups with managers configured in FleetBooking GroupManager,
        // scoped to the request entity to avoid cross-entity matches.
        // Batch query to avoid N+1: one query for all user groups at once.
        $groupIds = array_column($groups, 'id');
        $gm = new GroupManager();
        $gmResult = $DB->request([
            'SELECT' => ['groups_id'],
            'FROM' => $gm->getTable(),
            'WHERE' => [
                'groups_id' => $groupIds,
            ],
            'LIMIT' => 1,
        ]);
        $gmRow = null;
        foreach ($gmResult as $row) {
            $gmRow = $row;
            break;
        }
        if ($gmRow !== null) {
            foreach ($groups as $group) {
                if ((int) $group['id'] === (int) $gmRow['groups_id']) {
                    return $group;
                }
            }
        }

        // Priority 2: groups where the user is a delegate/manager in GLPI
        $delegates = \Group_User::getUserGroups($requesterId, ['glpi_groups_users.is_manager' => 1]);
        $delegateIds = array_column($delegates, 'id');
        foreach ($groups as $group) {
            if (in_array($group['id'], $delegateIds, true)) {
                return $group;
            }
        }

        // Fallback: first group
        return $groups[0];
    }
}
