<?php

namespace GlpiPlugin\Fleetbooking\Service;

use GlpiPlugin\Fleetbooking\Config;

class CalendarService
{

    public function getEvents(string $itemtype, int $items_id, string $start, string $end, int $current_request_id = 0): array
    {
        global $DB, $CFG_GLPI;
        $events = [];

        $currentUserId = (int) \Session::getLoginUserID();
        $isManagerOrAdmin = \Session::haveRight('fleetbooking_approve', READ) || \Session::haveRight('fleetbooking_admin', READ);

        \Toolbox::logInFile('fleetbooking', sprintf(
            '[CalendarService::getEvents] user=%d, itemtype=%s, items_id=%d, start=%s, end=%s, activeEntity=%d, isManagerOrAdmin=%s',
            $currentUserId,
            $itemtype,
            $items_id,
            $start,
            $end,
            (int) ($_SESSION['glpiactive_entity'] ?? 0),
            $isManagerOrAdmin ? 'YES' : 'NO'
        ));

        $config = Config::getForEntity($_SESSION['glpiactive_entity'] ?? 0);
        $approvedColor = $config['approved_color'] ?? '#2ecc71';
        $pendingColor = $config['pending_color'] ?? '#f1c40f';

        $target_itemtype = !empty($itemtype) ? $itemtype : ($config['vehicle_itemtype'] ?? '');

        if ($config['show_pending_on_calendar']) {
            $reqCriteria = [
                'status' => 'pending',
                'start_datetime' => ['<', $end],
                'end_datetime' => ['>', $start]
            ];
            if (!empty($target_itemtype)) {
                $reqCriteria['itemtype'] = $target_itemtype;
            }
            if (!empty($items_id)) {
                $reqCriteria['items_id'] = (int) $items_id;
            }

            $reqQuery = [
                'FROM' => 'glpi_plugin_fleetbooking_requests',
                'WHERE' => $reqCriteria
            ];

            $user = new \User();
            $pendingCount = 0;
            foreach ($DB->request($reqQuery) as $req) {
                $pendingCount++;
                $user->getFromDB($req['requester_users_id']);
                $vehicleName = $this->resolveVehicleName($req['itemtype'], $req['items_id']);

                $isMe = ((int) $req['requester_users_id'] === $currentUserId);
                $isCurrentRequest = ((int) $req['id'] === (int) $current_request_id);
                $canSeeDetails = $isManagerOrAdmin || $isMe;

                $event = [
                    'id' => 'req-' . $req['id'],
                    'start' => $req['start_datetime'],
                    'end' => $req['end_datetime'],
                ];

                if ($isCurrentRequest) {
                    $event['title'] = sprintf(__('👉 [THIS REQUEST] %1$s — %2$s', 'fleetbooking'), $vehicleName, __('PENDING', 'fleetbooking'));
                    $event['color'] = '#3498db'; // High-contrast Blue
                    $event['textColor'] = '#ffffff';
                    $event['classNames'] = ['current-request-event'];
                } elseif ($isMe) {
                    $event['title'] = sprintf(__('⭐ [My] %1$s — %2$s', 'fleetbooking'), $vehicleName, __('PENDING', 'fleetbooking'));
                    $event['color'] = '#d1ecf1';
                    $event['textColor'] = '#0c5460';
                } else {
                    $event['title'] = $canSeeDetails
                        ? sprintf('%s — %s — %s', $vehicleName, $user->getName(), __('PENDING', 'fleetbooking'))
                        : sprintf('%s — %s', $vehicleName, __('PENDING', 'fleetbooking'));
                    $event['color'] = $pendingColor;
                }

                $extendedProps = [
                    'vehicle' => $vehicleName,
                    'status' => 'pending',
                    'source' => 'request'
                ];

                if ($canSeeDetails) {
                    $extendedProps['requester'] = $user->getName();
                    if ($req['tickets_id']) {
                        $extendedProps['tickets_id'] = $req['tickets_id'];
                        $event['url'] = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $req['tickets_id'];
                    }
                }
                $event['extendedProps'] = $extendedProps;
                $events[] = $event;
            }
            \Toolbox::logInFile('fleetbooking', sprintf(
                '[CalendarService::getEvents] Pending requests found: %d',
                $pendingCount
            ));
        }

        $resCriteria = [
            'glpi_reservations.begin' => ['<', $end],
            'glpi_reservations.end' => ['>', $start]
        ];
        if (!empty($target_itemtype)) {
            $resCriteria['glpi_reservationitems.itemtype'] = $target_itemtype;
        }
        if (!empty($items_id)) {
            $resCriteria['glpi_reservationitems.items_id'] = (int) $items_id;
        }

        $resQuery = [
            'SELECT' => [
                'glpi_reservations.*',
                'glpi_users.name AS username',
                'glpi_reservationitems.itemtype',
                'glpi_reservationitems.items_id'
            ],
            'FROM' => 'glpi_reservations',
            'INNER JOIN' => [
                'glpi_reservationitems' => [
                    'ON' => [
                        'glpi_reservationitems' => 'id',
                        'glpi_reservations' => 'reservationitems_id'
                    ]
                ],
                'glpi_users' => [
                    'ON' => [
                        'glpi_users' => 'id',
                        'glpi_reservations' => 'users_id'
                    ]
                ]
            ],
            'WHERE' => $resCriteria
        ];

        $reservationCount = 0;
        foreach ($DB->request($resQuery) as $res) {
            $reservationCount++;
            $vehicleName = $this->resolveVehicleName($res['itemtype'], $res['items_id']);

            // Find corresponding request to get ticket ID
            $tQuery = [
                'FROM' => 'glpi_plugin_fleetbooking_requests',
                'WHERE' => ['reservations_id' => $res['id']],
                'LIMIT' => 1
            ];
            $tickets_id = null;
            $requesterId = (int) $res['users_id'];
            $reqId = 0;

            foreach ($DB->request($tQuery) as $reqRow) {
                $tickets_id = $reqRow['tickets_id'];
                $requesterId = (int) $reqRow['requester_users_id'];
                $reqId = (int) $reqRow['id'];
            }

            $isMe = ($requesterId === $currentUserId);
            $isCurrentRequest = ($reqId === (int) $current_request_id);
            $canSeeDetails = $isManagerOrAdmin || $isMe;

            $event = [
                'id' => 'res-' . $res['id'],
                'start' => $res['begin'],
                'end' => $res['end'],
            ];

            if ($isCurrentRequest) {
                $event['title'] = sprintf(__('👉 [ESTA RESERVA] %1$s — %2$s', 'fleetbooking'), $vehicleName, __('APPROVED', 'fleetbooking'));
                $event['color'] = '#27ae60'; // Darker high-contrast Green
                $event['textColor'] = '#ffffff';
                $event['classNames'] = ['current-request-event'];
            } elseif ($isMe) {
                $event['title'] = sprintf(__('⭐ [Minha] %1$s — %2$s', 'fleetbooking'), $vehicleName, __('APPROVED', 'fleetbooking'));
                $event['color'] = '#b8daff';
                $event['textColor'] = '#004085';
            } else {
                $event['title'] = $canSeeDetails
                    ? sprintf('%s — %s — %s', $vehicleName, $res['username'], __('APPROVED', 'fleetbooking'))
                    : sprintf('%s — %s', $vehicleName, __('APPROVED', 'fleetbooking'));
                $event['color'] = $approvedColor;
            }

            $extendedProps = [
                'vehicle' => $vehicleName,
                'status' => 'approved',
                'source' => 'reservation'
            ];

            if ($canSeeDetails) {
                $extendedProps['requester'] = $res['username'];
                if ($tickets_id) {
                    $extendedProps['tickets_id'] = $tickets_id;
                    $event['url'] = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $tickets_id;
                }
            }

            $event['extendedProps'] = $extendedProps;
            $events[] = $event;
        }

        \Toolbox::logInFile('fleetbooking', sprintf(
            '[CalendarService::getEvents] Approved reservations found: %d, Total events: %d',
            $reservationCount,
            count($events)
        ));

        return $events;
    }

    private function resolveVehicleName(string $itemtype, int $items_id): string
    {
        if (!class_exists($itemtype)) {
            return $itemtype;
        }
        $item = new $itemtype();
        return $item->getFromDB($items_id) ? $item->getName() : $itemtype;
    }

    /**
     * Hook method triggered to display events for native GLPI planning.
     */
    public static function plugin_fleetbooking_item_get_events($item): array
    {
        return [];
    }
}
