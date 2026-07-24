<?php

include("../../../inc/includes.php");

// Allow access to any user with at least one fleetbooking right.
if (
    !Session::haveRight('fleetbooking_read', READ)
    && !Session::haveRight('fleetbooking_request', READ)
    && !Session::haveRight('fleetbooking_approve', READ)
    && !Session::haveRight('fleetbooking_admin', READ)
) {
    Session::redirectIfNotLoggedIn();
    Html::displayRightError();
    exit;
}

Html::header(
    \GlpiPlugin\Fleetbooking\Request::getTypeName(Session::getPluralNumber()),
    '/plugins/fleetbooking/front/request.php',
    'tools',
    'GlpiPlugin\Fleetbooking\Request'
);

// Determine if the current user has only read-only access (Portaria profile).
$isReadOnly = \Session::haveRight('fleetbooking_read', READ)
    && !\Session::haveRight('fleetbooking_request', READ)
    && !\Session::haveRight('fleetbooking_approve', READ)
    && !\Session::haveRight('fleetbooking_admin', READ);

if ($isReadOnly) {
    global $DB;

    $request = new \GlpiPlugin\Fleetbooking\Request();

    $rows = $request->find(
        [
            'status' => \GlpiPlugin\Fleetbooking\Request::STATUS_APPROVED,
            'start_datetime' => ['>', date('Y-m-d H:i:s', strtotime('-7 days'))],
        ],
        'start_datetime DESC',
        0
    );

    $statusLabels = \GlpiPlugin\Fleetbooking\Request::getAllStatuses();
    $config = \GlpiPlugin\Fleetbooking\Config::getForEntity($_SESSION['glpiactive_entity'] ?? 0);
    $itemtype = $config['vehicle_itemtype'] ?? '';
    $baseUrl = \Plugin::getWebDir('fleetbooking') . '/front/request.readonly.php';

    $count = count($rows);

    echo "<div class='center' style='margin: 20px;'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<thead><tr>";
    echo "<th>" . __('Status', 'fleetbooking') . "</th>";
    echo "<th>" . __('Vehicle', 'fleetbooking') . "</th>";
    echo "<th>" . __('Requester', 'fleetbooking') . "</th>";
    echo "<th>" . __('Pickup Date', 'fleetbooking') . "</th>";
    echo "<th>" . __('Return Date', 'fleetbooking') . "</th>";
    echo "</tr></thead>";
    echo "<tbody>";

    foreach ($rows as $row) {
        $statusLabel = $statusLabels[$row['status']] ?? $row['status'];

        $requesterName = '';
        $requesterId = (int) ($row['requester_users_id'] ?? 0);
        if ($requesterId > 0) {
            $userRow = $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => \User::getTable(),
                'WHERE'  => ['id' => $requesterId],
            ])->current();
            if ($userRow) {
                $requesterName = formatUserName(
                    (int) $userRow['id'],
                    $userRow['name'] ?? '',
                    $userRow['realname'] ?? '',
                    $userRow['firstname'] ?? ''
                );
            }
        }
        if (empty($requesterName)) {
            $requesterName = __('N/A', 'fleetbooking');
        }

        $vehicleName = __('N/A', 'fleetbooking');
        if (!empty($itemtype) && class_exists($itemtype) && !empty($row['items_id'])) {
            $vehicleItem = new $itemtype();
            if ($vehicleItem->getFromDB((int) $row['items_id'])) {
                $vehicleName = $vehicleItem->fields['name'] ?? __('N/A', 'fleetbooking');
            }
        }

        $detailUrl = $baseUrl . '?id=' . (int) $row['id'];

        echo "<tr class='tab_bg_1'>";
        echo "<td><a href='" . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . "'>"
            . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . "</a></td>";
        echo "<td>" . htmlspecialchars((string) $vehicleName, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . Html::convDateTime($row['start_datetime']) . "</td>";
        echo "<td>" . Html::convDateTime($row['end_datetime']) . "</td>";
        echo "</tr>";
    }

    if ($count === 0) {
        echo "<tr class='tab_bg_1'><td colspan='5' class='center'>"
            . __('No approved reservations found for the last 7 days.', 'fleetbooking')
            . "</td></tr>";
    }

    echo "</tbody></table>";
    echo "<p style='margin-top: 10px; font-style: italic; color: #666;'>"
        . sprintf(__('Showing %d approved reservation(s) from the last 7 days.', 'fleetbooking'), $count)
        . "</p>";
    echo "</div>";

} else {
    // ---- Standard GLPI Search listing for full-access profiles ----
    global $DB;
    $desiredNums = [1, 5, 4, 2, 3]; // status, vehicle, requester, start_datetime, end_datetime

    $existingRows = iterator_to_array($DB->request([
        'SELECT' => ['num'],
        'FROM'   => 'glpi_displaypreferences',
        'WHERE'  => [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
            'users_id' => 0,
        ],
        'ORDER'  => 'rank ASC',
    ]));
    $storedNums = array_map('intval', array_column($existingRows, 'num'));

    if ($storedNums !== $desiredNums) {
        $DB->delete('glpi_displaypreferences', [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
        ]);
        foreach ($desiredNums as $rank => $num) {
            $DB->insert('glpi_displaypreferences', [
                'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
                'users_id' => 0,
                'num'      => $num,
                'rank'     => $rank + 1,
            ]);
        }
    }

    $currentUserId = (int) Session::getLoginUserID();
    if ($currentUserId > 0) {
        $DB->delete('glpi_displaypreferences', [
            'itemtype' => \GlpiPlugin\Fleetbooking\Request::class,
            'users_id' => $currentUserId,
        ]);
    }

    // Inject default search criteria when no manual filter is applied.
    if (!isset($_GET['criteria']) || (is_array($_GET['criteria']) && count($_GET['criteria']) === 0)) {
        $_GET['criteria'] = [
            0 => [
                'link'       => 'AND',
                'field'      => 2,
                'searchtype' => 'morethan',
                'value'      => '-7DAY',
            ],
        ];
        $_GET['sort']     = [2];
        $_GET['order']    = ['DESC'];
        $_GET['itemtype'] = \GlpiPlugin\Fleetbooking\Request::class;
        $_GET['start']    = 0;
    }

    // Sprint 6 — View All Reservations calendar modal (approvers/admins only)
    if (
        Session::haveRight('fleetbooking_approve', READ) ||
        Session::haveRight('fleetbooking_admin', READ)
    ) {
        $config = \GlpiPlugin\Fleetbooking\Config::getForEntity((int)($_SESSION['glpiactive_entity'] ?? 0));
        $itemtype = $config['vehicle_itemtype'] ?? '';

        $glpiLangRaw  = $_SESSION['glpilanguage'] ?? 'pt_BR';
        $fcLocaleCode = strtolower(str_replace('_', '-', substr($glpiLangRaw, 0, 5)));
        $fcLocaleFile = 'fullcalendar.' . $fcLocaleCode . '.global.min.js';
        $fcLocalePath = __DIR__ . '/../js/' . $fcLocaleFile;
        if (!file_exists($fcLocalePath)) {
            $fcLocaleFile = 'fullcalendar.pt-br.global.min.js';
            $fcLocaleCode = 'pt-br';
        }
        $fcLocaleUrl = \Plugin::getWebDir('fleetbooking', true) . '/js/' . $fcLocaleFile;
        $fcCoreUrl   = \Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.global.min.js';

        $js_vars_admin = [
            'ajax_url'      => \Plugin::getWebDir('fleetbooking') . '/ajax',
            'itemtype'      => $itemtype,
            'fc_url'        => $fcCoreUrl,
            'fc_locale_url' => $fcLocaleUrl,
        ];

        $btnLabel = htmlspecialchars(__('View All Reservations', 'fleetbooking'), ENT_QUOTES, 'UTF-8');

        echo "<div style='margin-bottom:16px;'>";
        echo "<button type='button' id='fb_admin_view_all' class='btn btn-secondary'
                      style='display:inline-flex;align-items:center;gap:6px;'>
                <i class='ti ti-calendar-month' aria-hidden='true'></i> {$btnLabel}
              </button>";
        echo "</div>";

        // Admin Reservations Modal HTML
        echo "
        <div id='fb-admin-reservations-modal'
             role='dialog'
             aria-modal='true'
             aria-labelledby='fb-admin-modal-title'
             style='display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.5); align-items:flex-start; justify-content:center; overflow-y:auto; padding:20px; font-family:sans-serif;'>
          <div class='fb-modal-dialog' style='background:#ffffff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,0.25); width:90%; max-width:1100px; padding:28px 32px; position:relative; margin:40px auto; min-height:500px;'>
            <button class='fb-modal-close' id='fb-admin-modal-close' aria-label='" . htmlspecialchars(__('Close', 'fleetbooking'), ENT_QUOTES, 'UTF-8') . "' style='position:absolute; top:16px; right:20px; background:none; border:none; font-size:2rem; cursor:pointer; color:#64748b; line-height:1; padding:0;'>&times;</button>
            <h3 class='fb-modal-title' id='fb-admin-modal-title' style='font-size:1.3rem; font-weight:600; color:#1e293b; margin-bottom:20px; margin-top:0; padding-right:40px;'>{$btnLabel}</h3>
            <div id='fb-admin-reservations-calendar' style='min-height:400px;'></div>
          </div>
        </div>";

        echo Html::scriptBlock("
(function () {
    'use strict';
    var modal       = document.getElementById('fb-admin-reservations-modal');
    var openBtn     = document.getElementById('fb_admin_view_all');
    var closeBtn    = document.getElementById('fb-admin-modal-close');
    var calDiv      = document.getElementById('fb-admin-reservations-calendar');
    var calInstance = null;
    var prevFocus   = null;

    var fb_config_admin = " . json_encode($js_vars_admin) . ";

    function loadScript(src, id, callback) {
        var script = document.getElementById(id);
        if (!script) {
            script = document.createElement('script');
            script.id = id;
            script.src = src;
            script.onload = callback;
            document.head.appendChild(script);
        } else {
            if (script.getAttribute('data-loaded') === 'true' || typeof FullCalendar !== 'undefined') {
                callback();
            } else {
                script.addEventListener('load', callback);
            }
        }
    }

    function openModal() {
        prevFocus = document.activeElement;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        closeBtn.focus();

        if (!calInstance) {
            loadScript(fb_config_admin.fc_url, 'local-fullcalendar-script', function () {
                if (typeof FullCalendar !== 'undefined' && typeof FullCalendar.globalLocales === 'undefined') {
                    FullCalendar.globalLocales = [];
                }
                loadScript(fb_config_admin.fc_locale_url, 'local-fullcalendar-locale-script', function () {
                    var script = document.getElementById('local-fullcalendar-locale-script');
                    if (script) script.setAttribute('data-loaded', 'true');
                    initCalendar();
                });
            });
        } else {
            setTimeout(function() {
                calInstance.updateSize();
            }, 50);
        }
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        if (prevFocus) prevFocus.focus();
    }

    function initCalendar() {
        var ajaxUrl = fb_config_admin.ajax_url + '/calendar.php'
            + '?modal_mode=1'
            + '&itemtype=' + encodeURIComponent(fb_config_admin.itemtype);

        calInstance = new FullCalendar.Calendar(calDiv, {
            initialView: 'dayGridMonth',
            locale: " . json_encode($fcLocaleCode) . ",
            height: 'auto',
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,listMonth'
            },
            events: function (info, successCb, failureCb) {
                \$.ajax({
                    url: ajaxUrl,
                    type: 'GET',
                    data: {
                        start: info.startStr,
                        end:   info.endStr
                    },
                    success: successCb,
                    error:   failureCb
                });
            },
            eventClick: function (info) {
                if (info.event.url) {
                    info.jsEvent.preventDefault();
                    window.open(info.event.url, '_blank', 'noopener,noreferrer');
                }
            }
        });
        calInstance.render();
        setTimeout(function () {
            if (calInstance) {
                calInstance.updateSize();
            }
        }, 100);
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    // Close on backdrop click
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });
})();
");
    }

    \Search::show(\GlpiPlugin\Fleetbooking\Request::class);
}

Html::footer();
