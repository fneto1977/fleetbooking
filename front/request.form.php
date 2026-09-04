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

    if (empty($_POST['policy_accepted'])) {
        Session::addMessageAfterRedirect(__('You must accept the Vehicle Usage Policy before submitting.', 'fleetbooking'), false, ERROR);
        Html::back();
    }

    require_once __DIR__ . '/../src/Service/DriverValidationService.php';
    $driverValidator = new \GlpiPlugin\Fleetbooking\Service\DriverValidationService();
    $driverValidation = $driverValidator->validate($_POST);
    if (!$driverValidation['ok']) {
        foreach ($driverValidation['errors'] as $err) {
            Session::addMessageAfterRedirect($err, false, ERROR);
        }
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

/**
 * Render the inner weekly vehicle availability calendar HTML.
 */
function fbRenderWeeklyCalendarContent(int $cal_items_id, array $config, $DB, string $pageUrl): void
{
    if ($cal_items_id === 0) {
        echo "<div id='fb-calendar-content'>";
        echo "<div class='alert alert-info'>" . __('Please select a vehicle from the list above to view its availability calendar.', 'fleetbooking') . "</div>";
        echo "</div>";
        return;
    }

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
    $hours     = range($workStart, $workEnd - 1);

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

    $prevUrl = $buildWeekUrl($current_year, $current_week_number - 1, $cal_items_id, $prev_week_monday);
    $nextUrl = $buildWeekUrl($current_year, $current_week_number + 1, $cal_items_id, $next_week_monday);

    echo "<div id='fb-calendar-content'>";
    // Navigation buttons — centered above grid with data-url for AJAX navigation
    echo "<div style='display:flex;align-items:center;justify-content:center;gap:24px;margin-bottom:16px;padding:10px 15px;background:#f8f9fa;border-radius:6px;'>";
    echo "<a href='" . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . "' data-url='" . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . "' class='btn btn-ghost btn-sm fb-week-nav-btn'>&larr; " . __('Previous Week', 'fleetbooking') . "</a>";
    echo "<span style='text-align:center;font-size:0.95em;'>$weekLabel</span>";
    echo "<a href='" . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . "' data-url='" . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . "' class='btn btn-ghost btn-sm fb-week-nav-btn'>" . __('Next Week', 'fleetbooking') . " &rarr;</a>";
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
                    $cellBg  = $calApprovedColor;
                    $tooltip = __('My Reservation (Approved)', 'fleetbooking');
                } else {
                    $cellBg  = $calPendingColor;
                    $tooltip = __('My Reservation (Pending)', 'fleetbooking');
                }
            } elseif ($hStatus === 'approved') {
                $cellBg  = $calReservedColor;
                $cur     = 'default';
                $cls     = '';
                $tooltip = __('Booked', 'fleetbooking');
            } elseif ($hStatus === 'pending') {
                $cellBg  = $calPendingColor;
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

    // Legend
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

    echo "</div>"; // End fb-calendar-content
}

// Handle AJAX Weekly Calendar request (No full page reload needed)
if (!empty($_GET['ajax_calendar'])) {
    header('Content-Type: text/html; charset=utf-8');
    global $DB, $CFG_GLPI;
    $entities_id = (int) ($_SESSION["glpiactive_entity"] ?? 0);
    $config = \GlpiPlugin\Fleetbooking\Config::getForEntity($entities_id);
    $cal_items_id = (int) ($_GET['cal_items_id'] ?? 0);
    $pageUrl = \Plugin::getWebDir('fleetbooking', true) . '/front/request.form.php';
    fbRenderWeeklyCalendarContent($cal_items_id, $config, $DB, $pageUrl);
    exit;
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

// Driver Information Section
echo "<tr><th colspan='2' style='background:#f1f5f9;color:#334155;font-size:0.95em;padding:10px 12px;font-weight:600;border-top:1px solid #cbd5e1;'>" . __('Driver Information', 'fleetbooking') . "</th></tr>";

// Identification (CPF vs Matrícula toggle)
echo "<tr class='tab_bg_1'>";
echo "<td><label>" . __('Identification', 'fleetbooking') . " <span class='required' aria-hidden='true'>*</span></label></td>";
echo "<td>";
echo "<div style='display:flex;align-items:center;gap:18px;margin-bottom:8px;'>";
echo "<label style='display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;'>";
echo "<input type='radio' name='driver_id_type' value='cpf' id='fb_id_type_cpf' checked onchange='fbToggleIdType()'> " . __('CPF', 'fleetbooking');
echo "</label>";
echo "<label style='display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;'>";
echo "<input type='radio' name='driver_id_type' value='registration' id='fb_id_type_reg' onchange='fbToggleIdType()'> " . __('Employee ID / Registration', 'fleetbooking');
echo "</label>";
echo "</div>";
echo "<div id='fb_cpf_field_wrapper'>";
echo "<input type='text' name='driver_cpf' id='fb_driver_cpf' class='form-control' placeholder='000.000.000-00' maxlength='14' style='width:220px;' />";
echo "<div id='fb_cpf_msg' style='font-size:0.85em;margin-top:4px;'></div>";
echo "</div>";
echo "<div id='fb_reg_field_wrapper' style='display:none;'>";
echo "<input type='text' name='driver_registration' id='fb_driver_registration' class='form-control' placeholder='0000' maxlength='4' inputmode='numeric' pattern='[0-9]{3,4}' style='width:220px;' />";
echo "<div id='fb_reg_msg' style='font-size:0.85em;margin-top:4px;'></div>";
echo "</div>";
echo "<script>
function fbToggleIdType() {
    var isCpf = document.getElementById('fb_id_type_cpf') ? document.getElementById('fb_id_type_cpf').checked : true;
    var cpfWrap = document.getElementById('fb_cpf_field_wrapper');
    var regWrap = document.getElementById('fb_reg_field_wrapper');
    var cpfInput = document.getElementById('fb_driver_cpf');
    var regInput = document.getElementById('fb_driver_registration');
    var cpfMsg = document.getElementById('fb_cpf_msg');
    var regMsg = document.getElementById('fb_reg_msg');
    if (isCpf) {
        if (cpfWrap) cpfWrap.style.display = 'block';
        if (regWrap) regWrap.style.display = 'none';
        if (cpfInput) { cpfInput.required = true; }
        if (regInput) {
            regInput.required = false;
            regInput.value = '';
            regInput.style.borderColor = '';
            if (typeof regInput.setCustomValidity === 'function') regInput.setCustomValidity('');
        }
        if (regMsg) regMsg.innerHTML = '';
    } else {
        if (cpfWrap) cpfWrap.style.display = 'none';
        if (regWrap) regWrap.style.display = 'block';
        if (cpfInput) {
            cpfInput.required = false;
            cpfInput.value = '';
            cpfInput.style.borderColor = '';
            if (typeof cpfInput.setCustomValidity === 'function') cpfInput.setCustomValidity('');
        }
        if (cpfMsg) cpfMsg.innerHTML = '';
        if (regInput) { regInput.required = true; regInput.focus(); }
    }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fbToggleIdType);
} else {
    fbToggleIdType();
}
</script>";
echo "</td></tr>";

// CNH Number
echo "<tr class='tab_bg_1'>";
echo "<td><label for='fb_driver_cnh_number'>" . __('CNH Number', 'fleetbooking') . " <span class='required' aria-hidden='true'>*</span></label></td>";
echo "<td><input type='text' name='driver_cnh_number' id='fb_driver_cnh_number' class='form-control' required aria-required='true' maxlength='9' inputmode='numeric' pattern='[0-9]{1,9}' placeholder='000000000' style='width:220px;' /></td>";
echo "</tr>";

// CNH Category (minimum B)
echo "<tr class='tab_bg_1'>";
echo "<td><label for='fb_driver_cnh_category'>" . __('CNH Category', 'fleetbooking') . " <span class='required' aria-hidden='true'>*</span></label></td>";
echo "<td>";
echo "<select name='driver_cnh_category' id='fb_driver_cnh_category' class='form-select' required aria-required='true' style='width:160px;display:inline-block;'>";
$cnhCategories = ['B' => 'B', 'AB' => 'AB', 'C' => 'C', 'AC' => 'AC', 'D' => 'D', 'AD' => 'AD', 'E' => 'E', 'AE' => 'AE'];
foreach ($cnhCategories as $catVal => $catLabel) {
    echo "<option value='$catVal'>$catLabel</option>";
}
echo "</select>";
echo "<span style='color:#64748b;font-size:0.85em;margin-left:10px;'>" . __('(Minimum category B required)', 'fleetbooking') . "</span>";
echo "</td></tr>";

// CNH Expiry Date
echo "<tr class='tab_bg_1'>";
echo "<td><label for='fb_driver_cnh_expiry'>" . __('CNH Expiry Date', 'fleetbooking') . " <span class='required' aria-hidden='true'>*</span></label></td>";
echo "<td>";
echo "<input type='date' name='driver_cnh_expiry' id='fb_driver_cnh_expiry' class='form-control' required aria-required='true' style='width:auto;display:inline-block;' />";
echo "<div id='fb_cnh_expiry_msg' style='font-size:0.85em;margin-top:4px;'></div>";
echo "</td></tr>";

// Policy Acceptance Checkbox
$policyDocUrl = \GlpiPlugin\Fleetbooking\Config::getPolicyDocumentUrl($entities_id);
echo "<tr class='tab_bg_1'><td colspan='2'>";
echo "<div style='padding:12px 16px;background:#fff8e6;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:6px;margin:8px 0;'>";
echo "<label style='display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin:0;'>";
echo "<input type='checkbox' name='policy_accepted' id='fb_policy_accepted' value='1' required aria-required='true' style='margin-top:4px;min-width:18px;min-height:18px;cursor:pointer;' />";
echo "<span style='font-size:0.92em;color:#1e293b;line-height:1.4;'>" . sprintf(
    __('I have read and agree to the %sVehicle Usage Policy%s. Reading and agreement are mandatory before submitting this reservation request.', 'fleetbooking'),
    "<a href='" . htmlspecialchars($policyDocUrl, ENT_QUOTES, 'UTF-8') . "' target='_blank' rel='noopener noreferrer' style='color:#2563eb;text-decoration:underline;font-weight:600;display:inline-flex;align-items:center;gap:3px;'>",
    " <i class='ti ti-external-link' style='font-size:0.85em;'></i></a>"
) . " <span class='required' style='color:#dc2626;font-weight:bold;'>*</span></span>";
echo "</label></div></td></tr>";

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

// Determine FullCalendar locale from GLPI session language (Rule §16 — no hardcoded locale).
// Maps GLPI language code (e.g. 'pt_BR', 'en_GB') to FullCalendar locale file.
$glpiLangRaw  = $_SESSION['glpilanguage'] ?? 'pt_BR';
$fcLocaleCode = strtolower(str_replace('_', '-', substr($glpiLangRaw, 0, 5)));
$fcLocaleFile = 'fullcalendar.' . $fcLocaleCode . '.global.min.js';
$fcLocalePath = __DIR__ . '/../js/' . $fcLocaleFile;
if (!file_exists($fcLocalePath)) {
    // Fallback to pt-br if the locale file is not bundled.
    $fcLocaleFile = 'fullcalendar.pt-br.global.min.js';
    $fcLocaleCode = 'pt-br';
}
$fcLocaleUrl = \Plugin::getWebDir('fleetbooking', true) . '/js/' . $fcLocaleFile;
$fcCoreUrl   = \Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.global.min.js';

// Week navigation params for initial calendar rendering
$week_start_date_param = $_GET['week_start_date'] ?? null;
$todayStr = date('Y-m-d');
$today = new DateTime($todayStr);
$weekDayOfToday = (int) $today->format('N');
$curMonday = (clone $today)->modify('-' . ($weekDayOfToday - 1) . ' days');

if ($week_start_date_param) {
    try {
        $calMonday = new DateTime($week_start_date_param);
    } catch (\Exception $e) {
        $calMonday = $curMonday;
    }
} else {
    $calMonday = $curMonday;
}

// Unified JS config
$js_vars = [
    'ajax_url'            => \Plugin::getWebDir('fleetbooking') . '/ajax',
    'page_url'            => $pageUrl,
    'itemtype'            => $config['vehicle_itemtype'] ?? '',
    'cal_items_id'        => $cal_items_id,
    'week_start_date'     => $calMonday->format('Y-m-d'),
    'current_week_number' => (int) $calMonday->format('W'),
    'current_year'        => (int) $calMonday->format('Y'),
    'fc_url'              => $fcCoreUrl,
    'fc_locale_url'       => $fcLocaleUrl,
    'i18n' => [
        'validating'          => __('Validating availability...', 'fleetbooking'),
        'available'           => __('Period available.', 'fleetbooking'),
        'conflict'            => __('This period conflicts with an existing booking (pending or approved). Please choose a different date.', 'fleetbooking'),
        'validation_error'    => __('Could not validate availability.', 'fleetbooking'),
        'csrf_missing'        => __('Security token missing. Please refresh the page.', 'fleetbooking'),
        'event_details'       => __('Event details', 'fleetbooking'),
        'close'               => __('Close', 'fleetbooking'),
        'start_label'         => __('Start', 'fleetbooking'),
        'end_label'           => __('End', 'fleetbooking'),
        'cnh_expired_warning' => __('Warning: CNH will be expired on the vehicle return date.', 'fleetbooking'),
        'cnh_valid'           => __('CNH is valid for the period.', 'fleetbooking'),
        'cnh_invalid_number'  => __('CNH number must contain only numbers (up to 9 digits).', 'fleetbooking'),
        'reg_invalid_format'  => __('Employee ID / Registration must be numeric and contain 3 to 4 digits (e.g. 0000).', 'fleetbooking'),
        'cpf_invalid'         => __('Invalid CPF number.', 'fleetbooking'),
        'cpf_valid'           => __('CPF is valid.', 'fleetbooking'),
        'cpf_mandatory'       => __('CPF is mandatory when CPF identification is selected.', 'fleetbooking'),
        'reg_valid'           => __('Employee ID / Registration is valid.', 'fleetbooking'),
        'reg_mandatory'       => __('Employee ID / Registration is mandatory.', 'fleetbooking'),
        'select_vehicle'      => __('Please select a vehicle.', 'fleetbooking'),
    ],
];

// Render weekly availability calendar
echo "<div id='fleetbooking-calendar' style='margin-top:30px; width:95%; max-width:1200px; padding:20px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.1); border-radius:8px;'>";
echo "<h3 style='margin-bottom:10px;'>" . __('Weekly Vehicle Availability', 'fleetbooking') . "</h3>";
fbRenderWeeklyCalendarContent($cal_items_id, $config, $DB, $pageUrl);
echo "</div>"; // End calendar box
echo "</div>"; // End page container

// The modal HTML is rendered unconditionally so the
// 'View All Reservations' button works even without a pre-selected vehicle.
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

echo Html::scriptBlock("var fleetbooking_config = " . json_encode($js_vars) . ";");

// --- Modal JS + Lazy FullCalendar init ---
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
                // Show details only for own events
                if (info.event.extendedProps && info.event.extendedProps.tickets_id) {
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

echo Html::scriptBlock("
(function () {
    'use strict';

    var DRAFT_KEY = 'fb_request_draft';
    var validationTimer = null;
    var lastValidationOk = true;

    function buildVehicleUrl(vid) {
        var base = (fleetbooking_config && fleetbooking_config.page_url) ? fleetbooking_config.page_url : window.location.pathname;
        return base + '?cal_items_id=' + encodeURIComponent(vid);
    }

    // ----------------------------------------------------
    // Form Draft Persistence (sessionStorage)
    // ----------------------------------------------------
    function saveFormDraft() {
        try {
            var isReg = \$('#fb_id_type_reg').is(':checked');
            var draft = {
                id_type: isReg ? 'registration' : 'cpf',
                driver_cpf: \$('#fb_driver_cpf').val() || '',
                driver_registration: \$('#fb_driver_registration').val() || '',
                driver_cnh_number: \$('#fb_driver_cnh_number').val() || '',
                driver_cnh_category: \$('#fb_driver_cnh_category').val() || 'B',
                driver_cnh_expiry: \$('#fb_driver_cnh_expiry').val() || '',
                reason: \$('#fb_reason').val() || '',
                start_datetime: \$('#fb_start_datetime').val() || '',
                end_datetime: \$('#fb_end_datetime').val() || '',
                policy_accepted: \$('#fb_policy_accepted').is(':checked') ? 1 : 0,
                items_id: \$('#fb_items_id').val() || ''
            };
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
        } catch (e) {}
    }

    function restoreFormDraft() {
        try {
            var raw = sessionStorage.getItem(DRAFT_KEY);
            if (!raw) return;
            var draft = JSON.parse(raw);
            if (!draft) return;

            if (draft.reason && !$('#fb_reason').val()) \$('#fb_reason').val(draft.reason);
            if (draft.driver_cnh_number && !$('#fb_driver_cnh_number').val()) \$('#fb_driver_cnh_number').val(draft.driver_cnh_number);
            if (draft.driver_cnh_category) \$('#fb_driver_cnh_category').val(draft.driver_cnh_category);
            if (draft.driver_cnh_expiry && !$('#fb_driver_cnh_expiry').val()) \$('#fb_driver_cnh_expiry').val(draft.driver_cnh_expiry);

            if (draft.id_type === 'registration') {
                \$('#fb_id_type_reg').prop('checked', true);
                if (typeof handleIdTypeToggle === 'function') handleIdTypeToggle();
                if (draft.driver_registration && !$('#fb_driver_registration').val()) {
                    \$('#fb_driver_registration').val(draft.driver_registration);
                }
                if (typeof validateRegUI === 'function') validateRegUI(false);
            } else {
                \$('#fb_id_type_cpf').prop('checked', true);
                if (typeof handleIdTypeToggle === 'function') handleIdTypeToggle();
                if (draft.driver_cpf && !$('#fb_driver_cpf').val()) {
                    \$('#fb_driver_cpf').val(draft.driver_cpf);
                }
                if (typeof validateCpfUI === 'function') validateCpfUI(false);
            }

            if (draft.start_datetime && !$('#fb_start_datetime').val()) \$('#fb_start_datetime').val(draft.start_datetime);
            if (draft.end_datetime && !$('#fb_end_datetime').val()) \$('#fb_end_datetime').val(draft.end_datetime);
            if (draft.policy_accepted && !$('#fb_policy_accepted').is(':checked')) {
                \$('#fb_policy_accepted').prop('checked', true);
            }

            // Restore vehicle if not selected in URL
            var curVid = parseInt(\$('#fb_items_id').val() || '0', 10);
            if (!curVid && draft.items_id) {
                var dVid = parseInt(draft.items_id, 10);
                if (dVid > 0) {
                    var \$targetCard = \$('.fb-vehicle-card[data-vehicle-id=\"' + dVid + '\"]');
                    if (\$targetCard.length) {
                        selectVehicle(dVid, \$targetCard[0]);
                    }
                }
            }

            checkCnhExpiry();
            reapplySelection();
        } catch (e) {}
    }

    // ----------------------------------------------------
    // Vehicle Selection (AJAX - no page reload)
    // ----------------------------------------------------
    function selectVehicle(vid, cardEl) {
        if (!vid) return;
        \$('#fb_items_id').val(vid);
        if (fleetbooking_config) fleetbooking_config.cal_items_id = vid;
        saveFormDraft();

        // Update card visual state
        \$('.fb-vehicle-card').removeClass('fb-vehicle-card--selected')
            .attr('aria-checked', 'false')
            .css({
                'border-color': '#e2e8f0',
                'background': '#ffffff',
                'box-shadow': 'none'
            });
        \$('.fb-vehicle-card i').css('color', '#64748b');

        if (cardEl) {
            \$(cardEl).addClass('fb-vehicle-card--selected')
                .attr('aria-checked', 'true')
                .css({
                    'border-color': '#2563eb',
                    'background': '#dbeafe',
                    'box-shadow': '0 0 0 3px rgba(37,99,235,0.15)'
                });
            \$(cardEl).find('i').css('color', '#2563eb');
        }

        var newUrl = buildVehicleUrl(vid);
        if (window.history && window.history.pushState) {
            window.history.pushState(null, '', newUrl);
        }

        // Fetch vehicle availability calendar via AJAX
        var \$calContent = \$('#fb-calendar-content');
        if (\$calContent.length) {
            \$calContent.css('opacity', '0.4').css('pointer-events', 'none');
            \$.ajax({
                url: newUrl + '&ajax_calendar=1',
                type: 'GET',
                dataType: 'html',
                success: function (html) {
                    \$calContent.replaceWith(html);
                    reapplySelection();
                },
                error: function () {
                    \$calContent.css('opacity', '1').css('pointer-events', 'auto');
                }
            });
        }

        scheduleValidation();
    }

    // ----------------------------------------------------
    // Weekly Calendar AJAX Navigation (No full page reload)
    // ----------------------------------------------------
    function reapplySelection() {
        var startVal = \$('#fb_start_datetime').val();
        var endVal   = \$('#fb_end_datetime').val();
        if (!startVal || !endVal) return;

        var sDt = startVal.replace('T', ' ') + (startVal.length === 16 ? ':00' : '');
        var eDt = endVal.replace('T', ' ') + (endVal.length === 16 ? ':00' : '');

        \$('.fb-hour-slot').removeClass('fb-selected fb-selected-start fb-selected-end');
        \$('.fb-hour-slot.fb-available').each(function () {
            var s = \$(this).attr('data-start');
            var e = \$(this).attr('data-end');
            if (s === sDt) \$(this).addClass('fb-selected-start');
            if (e === eDt) \$(this).addClass('fb-selected-end');
            if (s >= sDt && e <= eDt) \$(this).addClass('fb-selected');
        });

        // Update info panel
        var s = new Date(sDt.replace(' ', 'T'));
        var e = new Date(eDt.replace(' ', 'T'));
        if (!isNaN(s.getTime()) && !isNaN(e.getTime())) {
            var h = Math.round((e - s) / 3600000);
            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            function fmtDisp(d) {
                return d.getFullYear() + '/' + pad(d.getMonth() + 1) + '/' + pad(d.getDate())
                    + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            }
            var i18n = (fleetbooking_config && fleetbooking_config.i18n) ? fleetbooking_config.i18n : {};
            var startLbl = i18n.start_label || 'Start';
            var endLbl = i18n.end_label || 'End';
            \$('#fb_selection_details').text(startLbl + ': ' + fmtDisp(s) + '  |  ' + endLbl + ': ' + fmtDisp(e) + '  |  ' + h + 'h');
            \$('#fb_selection_info').show();
        }
    }

    \$(document).on('click', '.fb-week-nav-btn', function (e) {
        e.preventDefault();
        var url = \$(this).attr('data-url') || \$(this).attr('href');
        if (!url) return;

        var \$calContent = \$('#fb-calendar-content');
        if (!\$calContent.length) return;
        \$calContent.css('opacity', '0.4').css('pointer-events', 'none');

        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        \$.ajax({
            url: url + sep + 'ajax_calendar=1',
            type: 'GET',
            dataType: 'html',
            success: function (html) {
                \$calContent.replaceWith(html);
                reapplySelection();
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', url);
                }
            },
            error: function () {
                \$calContent.css('opacity', '1').css('pointer-events', 'auto');
            }
        });
    });

    // ----------------------------------------------------
    // Card Click & Keyboard Attachment
    // ----------------------------------------------------
    \$(document).on('click', '.fb-vehicle-card', function () {
        var vid = parseInt(\$(this).attr('data-vehicle-id'), 10);
        if (vid > 0) {
            selectVehicle(vid, this);
        }
    });

    \$(document).on('keydown', '.fb-vehicle-card', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            var vid = parseInt(\$(this).attr('data-vehicle-id'), 10);
            if (vid > 0) {
                selectVehicle(vid, this);
            }
        }
    });

    // ----------------------------------------------------
    // Realtime Availability Check
    // ----------------------------------------------------
    function validateDateRange() {
        var start = document.getElementById('fb_start_datetime');
        var end   = document.getElementById('fb_end_datetime');
        var msg   = document.getElementById('fb_validation_msg');
        var btn   = document.getElementById('fb_submit_btn');
        var itemsId = document.querySelector('[name=\"items_id\"]');

        if (!start || !end || !start.value || !end.value) return;

        var startVal = start.value.replace('T', ' ') + ':00';
        var endVal   = end.value.replace('T', ' ')   + ':00';
        var vid      = itemsId ? itemsId.value : (fleetbooking_config ? fleetbooking_config.cal_items_id : 0);

        if (!vid || vid === '0') return;

        msg.style.color = '#555';
        msg.textContent = (fleetbooking_config && fleetbooking_config.i18n) ? fleetbooking_config.i18n.validating : 'Validating...';
        btn.disabled    = true;

        \$.ajax({
            url: (fleetbooking_config ? fleetbooking_config.ajax_url : '/plugins/fleetbooking/ajax') + '/availability.php',
            type: 'GET',
            data: {
                itemtype: (fleetbooking_config ? fleetbooking_config.itemtype : ''),
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
                btn.disabled    = false;
                lastValidationOk = true;
            }
        });
    }

    function scheduleValidation() {
        clearTimeout(validationTimer);
        validationTimer = setTimeout(validateDateRange, 600);
    }

    // ----------------------------------------------------
    // Identification Toggle & Validations
    // ----------------------------------------------------
    function isValidCpf(cpf) {
        if (!cpf) return false;
        var clean = ('' + cpf).replace(/\D/g, '');
        if (clean.length !== 11) return false;
        if (/^(\d)\1{10}$/.test(clean)) return false;

        var sum = 0;
        for (var i = 0; i < 9; i++) {
            sum += parseInt(clean.charAt(i), 10) * (10 - i);
        }
        var remainder = sum % 11;
        var d1 = remainder < 2 ? 0 : 11 - remainder;
        if (parseInt(clean.charAt(9), 10) !== d1) return false;

        sum = 0;
        for (var j = 0; j < 10; j++) {
            sum += parseInt(clean.charAt(j), 10) * (11 - j);
        }
        remainder = sum % 11;
        var d2 = remainder < 2 ? 0 : 11 - remainder;
        return parseInt(clean.charAt(10), 10) === d2;
    }

    function isValidReg(reg) {
        if (!reg) return false;
        var clean = ('' + reg).replace(/\D/g, '');
        return /^[0-9]{3,4}$/.test(clean);
    }

    function validateCpfUI(showEmptyError) {
        var input = document.getElementById('fb_driver_cpf');
        var \$msg = \$('#fb_cpf_msg');
        var rawVal = \$('#fb_driver_cpf').val() || '';
        var clean = rawVal.replace(/\D/g, '');
        var i18n = (fleetbooking_config && fleetbooking_config.i18n) ? fleetbooking_config.i18n : {};

        if (clean.length === 0) {
            if (showEmptyError) {
                var emptyMsg = i18n.cpf_mandatory || 'O CPF é obrigatório quando a identificação por CPF está selecionada.';
                \$msg.html('<span style=\"color:#dc2626;font-weight:500;\">⚠️ ' + emptyMsg + '</span>');
                \$('#fb_driver_cpf').css('border-color', '#dc2626');
                if (input) input.setCustomValidity(emptyMsg);
                return false;
            } else {
                \$msg.empty();
                \$('#fb_driver_cpf').css('border-color', '');
                if (input) input.setCustomValidity('');
                return null;
            }
        }

        if (clean.length === 11) {
            if (isValidCpf(clean)) {
                var okMsg = i18n.cpf_valid || 'CPF válido.';
                \$msg.html('<span style=\"color:#16a34a;font-weight:500;\">✅ ' + okMsg + '</span>');
                \$('#fb_driver_cpf').css('border-color', '#16a34a');
                if (input) input.setCustomValidity('');
                return true;
            } else {
                var errMsg = i18n.cpf_invalid || 'Número de CPF inválido.';
                \$msg.html('<span style=\"color:#dc2626;font-weight:500;\">⚠️ ' + errMsg + '</span>');
                \$('#fb_driver_cpf').css('border-color', '#dc2626');
                if (input) input.setCustomValidity(errMsg);
                return false;
            }
        } else {
            if (showEmptyError) {
                var errMsg = i18n.cpf_invalid || 'Número de CPF inválido.';
                \$msg.html('<span style=\"color:#dc2626;font-weight:500;\">⚠️ ' + errMsg + '</span>');
                \$('#fb_driver_cpf').css('border-color', '#dc2626');
                if (input) input.setCustomValidity(errMsg);
                return false;
            } else {
                \$msg.empty();
                \$('#fb_driver_cpf').css('border-color', '');
                if (input) input.setCustomValidity('');
                return false;
            }
        }
    }

    function validateRegUI(showEmptyError) {
        var input = document.getElementById('fb_driver_registration');
        var \$msg = \$('#fb_reg_msg');
        var rawVal = \$('#fb_driver_registration').val() || '';
        var clean = rawVal.replace(/\D/g, '');
        var i18n = (fleetbooking_config && fleetbooking_config.i18n) ? fleetbooking_config.i18n : {};

        if (clean.length === 0) {
            if (showEmptyError) {
                var emptyMsg = i18n.reg_mandatory || 'A matrícula do funcionário é obrigatória.';
                \$msg.html('<span style=\"color:#dc2626;font-weight:500;\">⚠️ ' + emptyMsg + '</span>');
                \$('#fb_driver_registration').css('border-color', '#dc2626');
                if (input) input.setCustomValidity(emptyMsg);
                return false;
            } else {
                \$msg.empty();
                \$('#fb_driver_registration').css('border-color', '');
                if (input) input.setCustomValidity('');
                return null;
            }
        }

        if (isValidReg(clean)) {
            var okMsg = i18n.reg_valid || 'Matrícula válida.';
            \$msg.html('<span style=\"color:#16a34a;font-weight:500;\">✅ ' + okMsg + '</span>');
            \$('#fb_driver_registration').css('border-color', '#16a34a');
            if (input) input.setCustomValidity('');
            return true;
        } else {
            if (showEmptyError) {
                var errMsg = i18n.reg_invalid_format || 'A matrícula do funcionário deve conter de 3 a 4 dígitos numéricos (ex: 0000).';
                \$msg.html('<span style=\"color:#dc2626;font-weight:500;\">⚠️ ' + errMsg + '</span>');
                \$('#fb_driver_registration').css('border-color', '#dc2626');
                if (input) input.setCustomValidity(errMsg);
                return false;
            } else {
                \$msg.empty();
                \$('#fb_driver_registration').css('border-color', '');
                if (input) input.setCustomValidity('');
                return false;
            }
        }
    }

    function handleIdTypeToggle() {
        var isCpf = \$('#fb_id_type_cpf').is(':checked');
        if (isCpf) {
            \$('#fb_cpf_field_wrapper').show();
            \$('#fb_reg_field_wrapper').hide();
            \$('#fb_driver_cpf').prop('required', true);
            \$('#fb_driver_registration').prop('required', false).val('').css('border-color', '');
            \$('#fb_reg_msg').empty();
            var regInput = document.getElementById('fb_driver_registration');
            if (regInput) regInput.setCustomValidity('');
        } else {
            \$('#fb_cpf_field_wrapper').hide();
            \$('#fb_reg_field_wrapper').show();
            \$('#fb_driver_cpf').prop('required', false).val('').css('border-color', '');
            \$('#fb_cpf_msg').empty();
            var cpfInput = document.getElementById('fb_driver_cpf');
            if (cpfInput) cpfInput.setCustomValidity('');
            \$('#fb_driver_registration').prop('required', true);
        }
    }

    // ----------------------------------------------------
    // Realtime CNH Expiry vs Vehicle Return Date validation
    // ----------------------------------------------------
    function checkCnhExpiry() {
        var cnhVal = \$('#fb_driver_cnh_expiry').val();
        var retVal = \$('#fb_end_datetime').val();
        var \$msg = \$('#fb_cnh_expiry_msg');
        var cnhInput = document.getElementById('fb_driver_cnh_expiry');

        if (!cnhVal || !retVal) {
            \$msg.empty();
            \$('#fb_driver_cnh_expiry').css('border-color', '');
            if (cnhInput) cnhInput.setCustomValidity('');
            return true;
        }

        var cnhDate = cnhVal.substring(0, 10);
        var retDate = retVal.substring(0, 10);

        if (cnhDate < retDate) {
            var warnText = (fleetbooking_config && fleetbooking_config.i18n && fleetbooking_config.i18n.cnh_expired_warning)
                ? fleetbooking_config.i18n.cnh_expired_warning
                : 'Atenção: CNH estará vencida na data de devolução do veículo.';
            \$msg.html('<span style=\"color:#dc2626;font-weight:500;\">⚠️ ' + warnText + '</span>');
            \$('#fb_driver_cnh_expiry').css('border-color', '#dc2626');
            if (cnhInput) {
                cnhInput.setCustomValidity(warnText);
            }
            return false;
        } else {
            var okText = (fleetbooking_config && fleetbooking_config.i18n && fleetbooking_config.i18n.cnh_valid)
                ? fleetbooking_config.i18n.cnh_valid
                : 'CNH válida para o período';
            \$msg.html('<span style=\"color:#16a34a;font-weight:500;\">✅ ' + okText + '</span>');
            \$('#fb_driver_cnh_expiry').css('border-color', '#16a34a');
            if (cnhInput) {
                cnhInput.setCustomValidity('');
            }
            return true;
        }
    }

    \$(document).ready(function () {
        // Auto-save draft on any form changes
        \$(document).on('input change', '#fleetbooking-form input, #fleetbooking-form textarea, #fleetbooking-form select', function () {
            saveFormDraft();
        });

        // Restore saved draft
        restoreFormDraft();

        // Datetime changes trigger validation and slot re-highlight
        \$('#fb_start_datetime, #fb_end_datetime').on('change input', function () {
            scheduleValidation();
            reapplySelection();
        });

        // Identification radio toggle
        \$(document).on('change', 'input[name=\"driver_id_type\"]', handleIdTypeToggle);
        handleIdTypeToggle();

        // CPF Input Mask (000.000.000-00) & Realtime Validation
        \$(document).on('input', '#fb_driver_cpf', function () {
            var v = \$(this).val().replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 9) {
                v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
            } else if (v.length > 6) {
                v = v.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
            } else if (v.length > 3) {
                v = v.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
            }
            \$(this).val(v);
            var clean = v.replace(/\D/g, '');
            if (clean.length === 11 || clean.length === 0) {
                validateCpfUI(false);
            }
        });
        \$(document).on('blur', '#fb_driver_cpf', function () {
            validateCpfUI(true);
        });

        // Registration Input Mask (0000, 3 to 4 numeric digits) & Realtime Validation
        \$(document).on('input', '#fb_driver_registration', function () {
            var v = \$(this).val().replace(/\D/g, '');
            if (v.length > 4) v = v.substring(0, 4);
            \$(this).val(v);
            if (v.length >= 3 || v.length === 0) {
                validateRegUI(false);
            }
        });
        \$(document).on('blur', '#fb_driver_registration', function () {
            validateRegUI(true);
        });

        // CNH Number Mask (digits only, max 9 digits)
        \$(document).on('input', '#fb_driver_cnh_number', function () {
            var v = \$(this).val().replace(/\D/g, '');
            if (v.length > 9) v = v.substring(0, 9);
            \$(this).val(v);
        });

        // CNH Expiry vs Return Date
        \$(document).on('change input', '#fb_driver_cnh_expiry, #fb_end_datetime', checkCnhExpiry);

        // Form submit guard
        \$('#fleetbooking-form').on('submit', function (e) {
            var vid = parseInt(\$('#fb_items_id').val() || '0', 10);
            if (!vid) {
                e.preventDefault();
                var vidErr = (fleetbooking_config.i18n && fleetbooking_config.i18n.select_vehicle)
                    ? fleetbooking_config.i18n.select_vehicle
                    : 'Por favor, selecione um veículo.';
                alert(vidErr);
                return false;
            }

            var isReg = \$('#fb_id_type_reg').is(':checked');
            if (isReg) {
                if (!validateRegUI(true)) {
                    e.preventDefault();
                    var regErr = (fleetbooking_config.i18n && fleetbooking_config.i18n.reg_invalid_format)
                        ? fleetbooking_config.i18n.reg_invalid_format
                        : 'A matrícula do funcionário deve conter de 3 a 4 dígitos numéricos (ex: 0000).';
                    var regVal = (\$('#fb_driver_registration').val() || '').replace(/\D/g, '');
                    if (regVal.length === 0 && fleetbooking_config.i18n && fleetbooking_config.i18n.reg_mandatory) {
                        regErr = fleetbooking_config.i18n.reg_mandatory;
                    }
                    alert(regErr);
                    \$('#fb_driver_registration').focus();
                    return false;
                }
            } else {
                if (!validateCpfUI(true)) {
                    e.preventDefault();
                    var cpfErr = (fleetbooking_config.i18n && fleetbooking_config.i18n.cpf_invalid)
                        ? fleetbooking_config.i18n.cpf_invalid
                        : 'Número de CPF inválido.';
                    var cpfVal = (\$('#fb_driver_cpf').val() || '').replace(/\D/g, '');
                    if (cpfVal.length === 0 && fleetbooking_config.i18n && fleetbooking_config.i18n.cpf_mandatory) {
                        cpfErr = fleetbooking_config.i18n.cpf_mandatory;
                    }
                    alert(cpfErr);
                    \$('#fb_driver_cpf').focus();
                    return false;
                }
            }

            var cnhNum = (\$('#fb_driver_cnh_number').val() || '').replace(/\D/g, '');
            if (!cnhNum || cnhNum.length > 9) {
                e.preventDefault();
                var cnhErr = (fleetbooking_config.i18n && fleetbooking_config.i18n.cnh_invalid_number)
                    ? fleetbooking_config.i18n.cnh_invalid_number
                    : 'O número da CNH deve conter apenas números (até 9 dígitos).';
                alert(cnhErr);
                \$('#fb_driver_cnh_number').focus();
                return false;
            }

            if (!checkCnhExpiry()) {
                e.preventDefault();
                var expErr = (fleetbooking_config.i18n && fleetbooking_config.i18n.cnh_expired_warning)
                    ? fleetbooking_config.i18n.cnh_expired_warning
                    : 'Atenção: CNH estará vencida na data de devolução do veículo.';
                alert(expErr);
                \$('#fb_driver_cnh_expiry').focus();
                return false;
            }

            // On successful submission, clear draft
            try {
                sessionStorage.removeItem(DRAFT_KEY);
            } catch (e) {}
        });
    });
})();
");


Html::footer();
