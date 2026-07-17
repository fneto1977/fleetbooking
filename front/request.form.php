<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_request", READ);

global $CFG_GLPI;

$request = new \GlpiPlugin\Fleetbooking\Request();

if (isset($_POST["add"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    Session::checkRight("fleetbooking_request", READ);

    if (empty($_POST['items_id']) || empty($_POST['start_datetime']) || empty($_POST['end_datetime']) || empty($_POST['reason'])) {
        Session::addMessageAfterRedirect(__('Please fill all required fields.', 'fleetbooking'), false, ERROR);
        Html::back();
    }

    require_once __DIR__ . '/../src/Service/RequestService.php';
    $service = new \GlpiPlugin\Fleetbooking\Service\RequestService();

    try {
        $service->createRequest($_POST, Session::getLoginUserID());
        Session::addMessageAfterRedirect(__('Fleet booking requested successfully. A ticket has been created.', 'fleetbooking'));
        Html::redirect($CFG_GLPI['root_doc'] . "/front/ticket.php");
    } catch (\Exception $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        Html::back();
    }
}

Html::header(
    __('Request Fleet Reservation', 'fleetbooking'),
    '/plugins/fleetbooking/front/request.form.php',
    "tools",
    "fleetbooking",
    "request"
);

$entities_id = $_SESSION["glpiactive_entity"];
$config = \GlpiPlugin\Fleetbooking\Config::getForEntity($entities_id);

// Read cal_items_id early so it is available throughout the file
$cal_items_id = (int) ($_GET['cal_items_id'] ?? 0);

echo "<div class='center fleetbooking-container'>";

if (empty($config['vehicle_itemtype'])) {
    echo "<div class='alert alert-warning'>" . __('Plugin is not correctly configured. Please contact the administrator to set the target Vehicle ItemType.', 'fleetbooking') . "</div>";
    Html::footer();
    exit;
}

echo "<h2>" . __('Request Fleet Reservation', 'fleetbooking') . "</h2>";

// Sprint 5 — View All Reservations button
echo "<div style='margin-bottom:14px;'>";
echo "<button type='button' id='fb_view_all_reservations' class='btn btn-secondary' style='display:inline-flex;align-items:center;gap:6px;'>";
echo "<i class='ti ti-calendar-month' aria-hidden='true'></i> ";
echo htmlspecialchars(__('View All Reservations', 'fleetbooking'), ENT_QUOTES, 'UTF-8');
echo "</button>";
echo "</div>";

$action_url = '/plugins/fleetbooking/front/request.form.php';
echo "<form method='post' action='$action_url' id='fleetbooking-form'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<input type='hidden' name='entities_id' value='$entities_id'>";
echo "<input type='hidden' name='itemtype' value='" . htmlspecialchars($config['vehicle_itemtype'], ENT_QUOTES, 'UTF-8') . "'>";

// Note: The "Fields marked with * are required" legend is rendered automatically
// by GLPI core when it detects required fields in the form. No manual output needed.
echo "<table class='tab_cadre_fixe'>";

// Vehicle card grid
echo "<tr class='tab_bg_1'><td>" . __('Select Vehicle', 'fleetbooking') . " <span class='required'>*</span></td><td>";
$item_class = $config['vehicle_itemtype'] ?? '';

if (!empty($item_class) && class_exists($item_class)) {
    $item = new $item_class();
    $vehicles = [];
    global $DB;

    $definition_id = 0;
    if (class_exists('Glpi\\Asset\\AssetDefinition')) {
        $all_defs = $DB->request([
            'FROM' => \Glpi\Asset\AssetDefinition::getTable(),
            'WHERE' => ['is_active' => 1],
        ]);
        foreach ($all_defs as $def) {
            $ad = new \Glpi\Asset\AssetDefinition();
            $ad->fields = $def;
            if ($ad->getAssetClassName() === $item_class) {
                $definition_id = (int) $def['id'];
                break;
            }
        }
    }

    $criteria = [];
    if ($item->maybeDeleted())
        $criteria['is_deleted'] = 0;
    if ($item->maybeTemplate())
        $criteria['is_template'] = 0;
    if ($item->isEntityAssign()) {
        $criteria += getEntitiesRestrictCriteria($item->getTable(), '', $entities_id, $item->maybeRecursive());
    }

    $table_fields = $DB->listFields($item->getTable());
    if ($definition_id > 0 && isset($table_fields['assets_assetdefinitions_id'])) {
        $criteria['assets_assetdefinitions_id'] = $definition_id;
    } elseif ($definition_id > 0 && isset($table_fields['assetdefinitions_id'])) {
        $criteria['assetdefinitions_id'] = $definition_id;
    }

    $rows = $item->find($criteria);
    foreach ($rows as $row) {
        $vehicles[$row['id']] = $row['name'] ?? $row['id'];
    }

    if (empty($vehicles)) {
        echo "<div class='alert alert-warning'>" . __('No active vehicles found for this entity.', 'fleetbooking') . "</div>";
    } else {
        // Hidden input updated by JS when a card is selected
        echo "<input type='hidden' name='items_id' id='fb_items_id' value='" . (int) $cal_items_id . "'>";

        echo "<div id='fb-vehicle-grid'
            role='radiogroup'
            aria-label='" . htmlspecialchars(__('Select Vehicle', 'fleetbooking'), ENT_QUOTES, 'UTF-8') . "'
            style='display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:12px; margin-top:8px;'>";

        foreach ($vehicles as $vid => $vname) {
            $isSelected  = ($cal_items_id > 0 && (int) $vid === $cal_items_id);
            $ariaChecked = $isSelected ? 'true' : 'false';

            // Inline styles guarantee card appearance regardless of CSS caching
            $baseStyle = "display:flex;flex-direction:column;align-items:center;justify-content:center;"
                . "gap:8px;padding:16px 10px;min-height:90px;text-align:center;"
                . "border-radius:8px;cursor:pointer;user-select:none;"
                . "transition:border-color 0.18s,box-shadow 0.18s,background 0.18s;";

            if ($isSelected) {
                $cardStyle = $baseStyle
                    . "border:2px solid #2563eb;background:#dbeafe;"
                    . "box-shadow:0 0 0 3px rgba(37,99,235,0.15);";
                $iconColor = 'color:#2563eb;';
            } else {
                $cardStyle = $baseStyle
                    . "border:2px solid #e2e8f0;background:#ffffff;";
                $iconColor = 'color:#64748b;';
            }

            echo "<div
                class='fb-vehicle-card" . ($isSelected ? ' fb-vehicle-card--selected' : '') . "'
                data-vehicle-id='" . (int) $vid . "'
                role='radio'
                aria-checked='" . htmlspecialchars($ariaChecked, ENT_QUOTES, 'UTF-8') . "'
                tabindex='0'
                title='" . htmlspecialchars($vname, ENT_QUOTES, 'UTF-8') . "'
                style='" . $cardStyle . "'
                onmouseover=\"if(!this.classList.contains('fb-vehicle-card--selected')){this.style.borderColor='#2563eb';this.style.background='#f1f5f9';}\"
                onmouseout=\"if(!this.classList.contains('fb-vehicle-card--selected')){this.style.borderColor='#e2e8f0';this.style.background='#ffffff';}\">
                <i class='ti ti-car fb-vehicle-card__icon' aria-hidden='true' style='font-size:2rem;" . $iconColor . "'></i>
                <span class='fb-vehicle-card__name' style='font-size:0.84rem;font-weight:500;color:#1e293b;word-break:break-word;line-height:1.3;'>" . htmlspecialchars($vname, ENT_QUOTES, 'UTF-8') . "</span>
            </div>";
        }

        echo "</div>"; // end grid
    }
} else {
    echo "<div class='alert alert-warning'>" . __('Invalid vehicle itemtype configured.', 'fleetbooking') . "</div>";
}
echo "</td></tr>";

// Date/time pickers (auto-filled by calendar click, editable for cross-week)
echo "<tr class='tab_bg_1'>";
echo "<td><label for='fb_start_datetime'>" . __('Pickup date/time', 'fleetbooking') . " <span class='required' aria-hidden='true'>*</span></label></td>";
echo "<td><input type='datetime-local' name='start_datetime' id='fb_start_datetime' class='form-control' style='width:auto; display:inline-block;' required aria-required='true' /></td>";
echo "</tr>";

echo "<tr class='tab_bg_1'>";
echo "<td><label for='fb_end_datetime'>" . __('Return date/time', 'fleetbooking') . " <span class='required' aria-hidden='true'>*</span></label></td>";
echo "<td><input type='datetime-local' name='end_datetime' id='fb_end_datetime' class='form-control' style='width:auto; display:inline-block;' required aria-required='true' /></td>";
echo "</tr>";
// Reason
echo "<tr class='tab_bg_1'>";
echo "<td><label for='fb_reason'>" . __('Reason for requesting', 'fleetbooking') . " <span class='required' aria-hidden='true'>*</span></label></td>";
echo "<td><textarea name='reason' id='fb_reason' rows='4' style='width: 90%;' required aria-required='true'></textarea></td>";
echo "</td></tr>";

echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
echo "<input type='submit' name='add' id='fb_submit_btn' class='btn btn-primary' value='" . _sx('button', 'Submit Request', 'fleetbooking') . "'>";
echo "<div id='fb_validation_msg' style='margin-top: 10px; color: red;'></div>";
echo "</td></tr>";
echo "</table>";
Html::closeForm();

// ----------------------------------------------------------------
// Weekly Hour View
// ----------------------------------------------------------------
$pageUrl = \Plugin::getWebDir('fleetbooking', true) . '/front/request.form.php';

// JS config defaults
$js_vars = [
    'ajax_url' => \Plugin::getWebDir('fleetbooking') . '/ajax',
    'itemtype' => '',
    'cal_items_id' => $cal_items_id,
    'week_start_date' => '',
    'current_week_number' => 1,
    'current_year' => (int) date('Y'),
];

if ($cal_items_id === 0) {
    echo "<div id='fleetbooking-calendar' style='margin-top:30px; width:95%; max-width:1200px; padding:20px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.1); border-radius:8px;'>";
    echo "<h3>" . __('Weekly Vehicle Availability', 'fleetbooking') . "</h3>";
    echo "<div class='alert alert-info'>" . __('Please select a vehicle from the list above to view its availability calendar.', 'fleetbooking') . "</div>";
    echo "</div>";
    echo "</div>"; // End page container
    echo Html::scriptBlock("var fleetbooking_config = " . json_encode($js_vars) . ";");
}

// Card selection JS — always injected (works for both cal_items_id=0 and cal_items_id>0)
echo Html::scriptBlock("
(function(){
    function buildUrl(vid){
        return window.location.pathname + '?cal_items_id=' + encodeURIComponent(vid);
    }
    function attachCardHandlers() {
        var grid = document.getElementById('fb-vehicle-grid');
        if (!grid) return;
        grid.querySelectorAll('.fb-vehicle-card').forEach(function(card) {
            card.style.cursor = 'pointer';
            card.addEventListener('click', function() {
                var vid = parseInt(card.getAttribute('data-vehicle-id'), 10);
                if (vid > 0) window.location.href = buildUrl(vid);
            });
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    var vid = parseInt(card.getAttribute('data-vehicle-id'), 10);
                    if (vid > 0) window.location.href = buildUrl(vid);
                }
            });
        });
    }
    // Run immediately (elements already in DOM) and also on DOMContentLoaded as fallback
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachCardHandlers);
    } else {
        attachCardHandlers();
    }
})();
");

if ($cal_items_id === 0) {
    Html::footer();
    exit;
}

// Week navigation params
$week_start_date_param = $_GET['week_start_date'] ?? null;
$current_week_number_param = $_GET['current_week_number'] ?? null;
$current_year_param = $_GET['current_year'] ?? date('Y');

if ($week_start_date_param) {
    $monday = new DateTime($week_start_date_param);
} else {
    $todayStr = date('Y-m-d');
    $today = new DateTime($todayStr);
    $weekDayOfToday = (int) $today->format('N');
    $monday = (clone $today)->modify('-' . ($weekDayOfToday - 1) . ' days');
}

$buildWeekUrl = function ($year, $weekNumber, $vid, $weekStartDateStr = null) use ($pageUrl) {
    $url = $pageUrl . '?cal_items_id=' . $vid . '&current_year=' . $year . '&current_week_number=' . $weekNumber;
    if ($weekStartDateStr) {
        $url .= '&week_start_date=' . urlencode($weekStartDateStr);
    }
    return $url;
};

// Booked hours
$bookedHours = [];
if ($cal_items_id > 0 && !empty($config['vehicle_itemtype'])) {
    foreach ($DB->request([
        'FROM' => \GlpiPlugin\Fleetbooking\Request::getTable(),
        'WHERE' => [
            'itemtype' => $config['vehicle_itemtype'],
            'items_id' => $cal_items_id,
            'status' => ['pending', 'approved'],
        ],
    ]) as $row) {
        if (empty($row['start_datetime']) || empty($row['end_datetime']))
            continue;
        $s = new DateTime($row['start_datetime']);
        $e = new DateTime($row['end_datetime']);
        while ($s < $e) {
            $bookedHours[$s->format('Y-m-d H')] = [
                'status' => $row['status'],
                'requester_users_id' => (int) $row['requester_users_id']
            ];
            $s->modify('+1 hour');
        }
    }
}

$workStart = (int) ($config['workday_start'] ?? 7);
$workEnd   = (int) ($config['workday_end'] ?? 19);
$hours     = range($workStart, $workEnd - 1); // dynamic work hours from config

// Dynamic calendar colors from config (Sprint 3)
$calApprovedColor = $config['approved_color'] ?? '#2ecc71';
$calPendingColor  = $config['pending_color']  ?? '#f1c40f';
$calReservedColor = $config['reserved_color'] ?? '#8e44ad';
$current_year = (int) $monday->format('Y');
$current_week_number = (int) $monday->format('W');
$week_end_date = (clone $monday)->modify('+6 days')->format('d/m/Y');
$todayStr = date('Y-m-d');

$prev_week_monday = (clone $monday)->modify('-7 days')->format('Y-m-d');
$next_week_monday = (clone $monday)->modify('+7 days')->format('Y-m-d');
$isCurrentWeek = ($current_week_number_param === null || ((int) $current_week_number == (int) $current_week_number_param && (int) $current_year == (int) $current_year_param));
$weekLabel = $isCurrentWeek
    ? '<strong>' . __('This Week', 'fleetbooking') . '</strong>'
    : '<strong>' . htmlspecialchars($monday->format('d/m'), ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($week_end_date, ENT_QUOTES, 'UTF-8') . '</strong>';

echo "<div id='fleetbooking-calendar' style='margin-top:30px; width:95%; max-width:1200px; padding:20px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.1); border-radius:8px;'>";
echo "<h3 style='margin-bottom:10px;'>" . __('Weekly Vehicle Availability', 'fleetbooking') . "</h3>";

// Navigation buttons — centered above grid
echo "<div style='display:flex;align-items:center;justify-content:center;gap:24px;margin-bottom:16px;padding:10px 15px;background:#f8f9fa;border-radius:6px;'>";
echo "<a href='" . htmlspecialchars($buildWeekUrl($current_year, $current_week_number - 1, $cal_items_id, $prev_week_monday), ENT_QUOTES, 'UTF-8') . "' class='btn btn-ghost btn-sm'>&larr; " . __('Previous Week', 'fleetbooking') . "</a>";
echo "<span style='text-align:center;font-size:0.95em;'>$weekLabel</span>";
echo "<a href='" . htmlspecialchars($buildWeekUrl($current_year, $current_week_number + 1, $cal_items_id, $next_week_monday), ENT_QUOTES, 'UTF-8') . "' class='btn btn-ghost btn-sm'>" . __('Next Week', 'fleetbooking') . " &rarr;</a>";
echo "</div>";

// Selection info panel
echo "<div id='fb_selection_info' style='margin-bottom:12px;padding:10px 14px;background:#e8f4fd;border-left:4px solid #0d6efd;border-radius:4px;display:none;'>";
echo "<strong>" . __('Selection Info', 'fleetbooking') . ":</strong>";
echo "<span id='fb_selection_details' style='margin-left:10px;'></span>";
echo "</div>";

// Calendar grid
echo "<div class='fb-calendar-scroll-wrapper'>";
echo "<table class='tab_cadre fb-weekly-calendar' style='width:100%;border-collapse:collapse;font-size:0.84em;min-width:900px;'><thead><tr>";
echo "<th style='padding:8px 3px;background:#f0f0f0;border:1px solid #ccc;width:50px;text-align:center;'>&nbsp;</th>";

// Day abbreviations for the weekly calendar header.
// Prefer IntlDateFormatter (ICU-based, locale-aware) when the intl
// extension is loaded; fall back to gettext with pt_BR source keys.
if (extension_loaded('intl')) {
    $locale = substr($_SESSION['glpilanguage'] ?? 'pt_BR', 0, 5);
    $dayFmt = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEEEE');
    $dayNames = [];
    for ($wd = 0; $wd < 7; $wd++) {
        $dayNames[] = $dayFmt->format((clone $monday)->modify("+$wd days")->getTimestamp());
    }
} else {
    $dayNames = [__('Mon', 'fleetbooking'), __('Tue', 'fleetbooking'), __('Wed', 'fleetbooking'), __('Thu', 'fleetbooking'), __('Fri', 'fleetbooking'), __('Sat', 'fleetbooking'), __('Sun', 'fleetbooking')];
}
for ($wd = 0; $wd < 7; $wd++) {
    $d = (clone $monday)->modify("+$wd days");
    $dateStr = $d->format('Y-m-d');
    $isToday2 = ($dateStr === $todayStr);
    $bg = $isToday2 ? '#e8f0fe' : '#f5f5f5';
    $fw = $isToday2 ? 'font-weight:700;' : '';
    $dayName = htmlspecialchars($dayNames[$wd], ENT_QUOTES, 'UTF-8');
    $dayDate = htmlspecialchars($d->format('d/m'), ENT_QUOTES, 'UTF-8');
    echo "<th style='padding:8px 3px;text-align:center;background:$bg;border:1px solid #ccc;$fw;position:relative;'>";
    echo $dayName . "<br><small>" . $dayDate . "</small>";
    if ($isToday2) {
        echo "<div style='position:absolute;top:0;left:0;width:100%;height:3px;background:#0d6efd;'></div>";
    }
    echo "</th>";
}
echo "</tr></thead><tbody>";

foreach ($hours as $h) {
    echo "<tr>";
    echo "<td style='text-align:center;padding:6px 3px;border:1px solid #ddd;background:#fafafa;font-weight:600;font-size:0.85em;'>" . sprintf('%02d', $h) . "h</td>";
    for ($wd = 0; $wd < 7; $wd++) {
        $d = (clone $monday)->modify("+$wd days");
        $dateKey = $d->format('Y-m-d') . ' ' . sprintf('%02d', $h);
        $dateStr2 = $d->format('Y-m-d');
        $isPast2 = ($dateStr2 < $todayStr) || ($dateStr2 === $todayStr && $h < (int) date('H'));

        $hData     = $bookedHours[$dateKey] ?? null;
        $hStatus    = $hData['status'] ?? '';
        $hRequester = $hData['requester_users_id'] ?? 0;
        $isMyBooking = ($hStatus !== '' && $hRequester === (int) Session::getLoginUserID());

        $cellContent = '&nbsp;';
        $cellBg = '';
        if ($isMyBooking) {
            $cellContent = '⭐';
            $cur = 'default';
            $cls = '';
            if ($hStatus === 'approved') {
                $cellBg  = $calApprovedColor;   // ← uses config color
                $tooltip = __('My Reservation (Approved)', 'fleetbooking');
            } else {
                $cellBg  = $calPendingColor;    // ← uses config color
                $tooltip = __('My Reservation (Pending)', 'fleetbooking');
            }
        } elseif ($hStatus === 'approved') {
            $cellBg  = $calReservedColor;       // ← reserved color for others
            $cur     = 'default';
            $cls     = '';
            $tooltip = __('Booked', 'fleetbooking');
        } elseif ($hStatus === 'pending') {
            $cellBg  = $calPendingColor;        // ← uses config pending color
            $cur     = 'default';
            $cls     = '';
            $tooltip = __('Pending', 'fleetbooking');
        } elseif ($isPast2) {
            $cellBg  = '#f0f0f0';
            $cur     = 'default';
            $cls     = '';
            $tooltip = __('Past hours, not selectable', 'fleetbooking');
        } else {
            $cellBg  = '#d4edda';
            $cur     = 'pointer';
            $startDt = $dateStr2 . ' ' . sprintf('%02d', $h) . ':00:00';
            $endDt   = $dateStr2 . ' ' . sprintf('%02d', ($h + 1)) . ':00:00';
            $cls     = "class='fb-hour-slot fb-available' data-start='" . htmlspecialchars($startDt, ENT_QUOTES, 'UTF-8') . "' data-end='" . htmlspecialchars($endDt, ENT_QUOTES, 'UTF-8') . "'";
            $tooltip = $dateStr2 . ' - ' . sprintf('%02d:00', $h);
        }

        echo "<td $cls style='text-align:center;padding:4px 2px;background:$cellBg;border:1px solid #ddd;cursor:$cur;' title='" . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . "'>$cellContent</td>";
    }
    echo "</tr>";
}
echo "</tbody></table></div>";

// Legend — below the grid (dynamic colors from config)
echo "<div style='margin-top:14px;font-size:0.84em;display:flex;gap:18px;flex-wrap:wrap;align-items:center;padding:10px 14px;background:#f8f9fa;border-radius:6px;'>";
echo "<span style='display:inline-flex;align-items:center;gap:6px;'><span style='display:inline-block;width:18px;height:14px;background:#d4edda;border-radius:3px;'></span>" . __('Available - Click to select', 'fleetbooking') . "</span>";
echo "<span style='display:inline-flex;align-items:center;gap:6px;'><span style='display:inline-block;width:18px;height:14px;background:" . htmlspecialchars($calPendingColor, ENT_QUOTES, 'UTF-8') . ";border-radius:3px;'></span>" . __('Pending', 'fleetbooking') . "</span>";
echo "<span style='display:inline-flex;align-items:center;gap:6px;'><span style='display:inline-block;width:18px;height:14px;background:" . htmlspecialchars($calReservedColor, ENT_QUOTES, 'UTF-8') . ";border-radius:3px;'></span>" . __('Booked', 'fleetbooking') . "</span>";
echo "<span style='display:inline-flex;align-items:center;gap:6px;'><span style='display:inline-block;width:18px;height:14px;background:" . htmlspecialchars($calApprovedColor, ENT_QUOTES, 'UTF-8') . ";border-radius:3px;text-align:center;font-size:10px;line-height:14px;'>⭐</span>" . __('My Reservation (Approved)', 'fleetbooking') . "</span>";
echo "<span style='display:inline-flex;align-items:center;gap:6px;'><span style='display:inline-block;width:18px;height:14px;background:" . htmlspecialchars($calPendingColor, ENT_QUOTES, 'UTF-8') . ";border-radius:3px;text-align:center;font-size:10px;line-height:14px;'>⭐</span>" . __('My Reservation (Pending)', 'fleetbooking') . "</span>";
echo "<span style='display:inline-flex;align-items:center;gap:6px;'><span style='display:inline-block;width:18px;height:14px;background:#f0f0f0;border-radius:3px;'></span>" . __('Past hours', 'fleetbooking') . "</span>";
echo "</div>";

echo "<input type='hidden' name='fb_selected_start' id='fb_selected_start' value='' />";
echo "<input type='hidden' name='fb_selected_end' id='fb_selected_end' value='' />";

echo "</div>"; // End calendar box
echo "</div>"; // End page container

// JS config
$js_vars = [
    'ajax_url'           => \Plugin::getWebDir('fleetbooking') . '/ajax',
    'itemtype'           => $config['vehicle_itemtype'],
    'cal_items_id'       => $cal_items_id,
    'week_start_date'    => $monday->format('Y-m-d'),
    'current_week_number'=> (int) $monday->format('W'),
    'current_year'       => (int) $monday->format('Y'),
    'fc_url'             => \Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.global.min.js',
    'fc_locale_url'      => \Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.pt-br.global.min.js',
    'i18n' => [
        'validating'       => __('Validating availability...', 'fleetbooking'),
        'available'        => __('Period available.', 'fleetbooking'),
        'conflict'         => __('This period conflicts with an existing booking (pending or approved). Please choose a different date.', 'fleetbooking'),
        'validation_error' => __('Could not validate availability.', 'fleetbooking'),
        'csrf_missing'     => __('Security token missing. Please refresh the page.', 'fleetbooking'),
        'event_details'    => __('Event details', 'fleetbooking'),
        'close'            => __('Close', 'fleetbooking'),
        'start_label'      => __('Start', 'fleetbooking'),
        'end_label'        => __('End', 'fleetbooking'),
    ],
];
echo Html::scriptBlock("var fleetbooking_config = " . json_encode($js_vars) . ";");

// AJAX availability validation on manual datetime-local field input
echo Html::scriptBlock("
(function () {
    var validationTimer = null;
    var lastValidationOk = true;

    function validateDateRange() {
        var start = document.getElementById('fb_start_datetime');
        var end   = document.getElementById('fb_end_datetime');
        var msg   = document.getElementById('fb_validation_msg');
        var btn   = document.getElementById('fb_submit_btn');
        var itemsId = document.querySelector('[name=\"items_id\"]');

        if (!start || !end || !start.value || !end.value) return;

        var startVal = start.value.replace('T', ' ') + ':00';
        var endVal   = end.value.replace('T', ' ')   + ':00';
        var vid      = itemsId ? itemsId.value : fleetbooking_config.cal_items_id;

        if (!vid || vid === '0') return;

        msg.style.color = '#555';
        msg.textContent = fleetbooking_config.i18n.validating;
        btn.disabled    = true;

        \$.ajax({
            url: fleetbooking_config.ajax_url + '/availability.php',
            type: 'GET',
            data: {
                itemtype: fleetbooking_config.itemtype,
                items_id: vid,
                start: startVal,
                end: endVal
            },
            success: function (data) {
                if (data.ok) {
                    msg.style.color = 'green';
                    msg.textContent = fleetbooking_config.i18n.available;
                    btn.disabled    = false;
                    lastValidationOk = true;
                } else {
                    var hasConflict = data.conflicts && data.conflicts.length > 0;
                    msg.style.color = 'red';
                    msg.textContent = hasConflict
                        ? fleetbooking_config.i18n.conflict
                        : (data.errors ? data.errors.join(' ') : fleetbooking_config.i18n.validation_error);
                    btn.disabled    = true;
                    lastValidationOk = false;
                }
            },
            error: function () {
                msg.style.color = 'orange';
                msg.textContent = fleetbooking_config.i18n.validation_error;
                btn.disabled    = false; // let server-side catch it
                lastValidationOk = true;
            }
        });
    }

    function scheduleValidation() {
        clearTimeout(validationTimer);
        validationTimer = setTimeout(validateDateRange, 600);
    }

    \$(document).ready(function () {
        \$('#fb_start_datetime, #fb_end_datetime').on('change input', scheduleValidation);
    });
})();
");

// Vehicle re-selection JS — here $cal_items_id is correctly set

// Sprint 5 — All Reservations Modal HTML
echo "
<div id='fb-all-reservations-modal'
     role='dialog'
     aria-modal='true'
     aria-labelledby='fb-modal-title'
     style='display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.5); align-items:flex-start; justify-content:center; overflow-y:auto; padding:20px; font-family:sans-serif;'>
  <div class='fb-modal-dialog' style='background:#ffffff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,0.25); width:90%; max-width:1100px; padding:28px 32px; position:relative; margin:40px auto; min-height:500px;'>
    <button class='fb-modal-close' id='fb-modal-close' aria-label='" . htmlspecialchars(__('Close', 'fleetbooking'), ENT_QUOTES, 'UTF-8') . "' style='position:absolute; top:16px; right:20px; background:none; border:none; font-size:2rem; cursor:pointer; color:#64748b; line-height:1; padding:0;'>&times;</button>
    <h3 class='fb-modal-title' id='fb-modal-title' style='font-size:1.3rem; font-weight:600; color:#1e293b; margin-bottom:20px; margin-top:0; padding-right:40px;'>" . htmlspecialchars(__('All Reservations Calendar', 'fleetbooking'), ENT_QUOTES, 'UTF-8') . "</h3>
    <div id='fb-all-reservations-calendar' style='min-height:400px;'></div>
  </div>
</div>";

// Sprint 5 — Modal JS + Lazy FullCalendar init
echo Html::scriptBlock("
(function () {
    'use strict';
    var modal       = document.getElementById('fb-all-reservations-modal');
    var openBtn     = document.getElementById('fb_view_all_reservations');
    var closeBtn    = document.getElementById('fb-modal-close');
    var calDiv      = document.getElementById('fb-all-reservations-calendar');
    var calInstance = null;
    var prevFocus   = null;

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
            loadScript(fleetbooking_config.fc_url, 'local-fullcalendar-script', function () {
                if (typeof FullCalendar !== 'undefined' && typeof FullCalendar.globalLocales === 'undefined') {
                    FullCalendar.globalLocales = [];
                }
                loadScript(fleetbooking_config.fc_locale_url, 'local-fullcalendar-locale-script', function () {
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
        var ajaxUrl = fleetbooking_config.ajax_url + '/calendar.php'
            + '?modal_mode=1'
            + '&itemtype=' + encodeURIComponent(fleetbooking_config.itemtype);

        calInstance = new FullCalendar.Calendar(calDiv, {
            initialView: 'dayGridMonth',
            locale: 'pt-br',
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
                // Show details only for own events
                if (info.event.extendedProps && info.event.extendedProps.tickets_id) {
                    info.jsEvent.preventDefault();
                    window.open(info.event.url, '_blank', 'noopener,noreferrer');
                }
            }
        });
        calInstance.render();
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

Html::footer();
