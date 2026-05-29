<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_request", READ);

global $DB;

$items_id = (int) ($_GET['items_id'] ?? 0);
$itemtype = $_GET['itemtype'] ?? '';
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));

// Clamp month
if ($month < 1) {
    $month = 12;
    $year--;
}
if ($month > 12) {
    $month = 1;
    $year++;
}

$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// ----------------------------------------------------------------
// Fetch all reservations for this vehicle and mark booked days
// ----------------------------------------------------------------
$bookedDays = [];
if ($items_id > 0 && !empty($itemtype)) {
    $firstDay = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $lastDay = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));

    foreach ($DB->request([
        'FROM' => 'glpi_plugin_fleetbooking_requests',
        'WHERE' => [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'status' => ['pending', 'approved'],
        ],
    ]) as $row) {
        if (empty($row['start_datetime']) || empty($row['end_datetime']))
            continue;
        $start = new DateTime($row['start_datetime']);
        $end = new DateTime($row['end_datetime']);
        $end->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        foreach ($period as $dt) {
            $bookedDays[$dt->format('Y-m-d')] = $row['status'];
        }
    }
}

// ----------------------------------------------------------------
// Render calendar HTML
// ----------------------------------------------------------------
$daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
$firstWeekday = (int) date('N', mktime(0, 0, 0, $month, 1, $year)); // 1=Mon 7=Sun
$monthLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));

// Locale-aware day abbreviations
if (extension_loaded('intl')) {
    $locale = substr($_SESSION['glpilanguage'] ?? 'pt_BR', 0, 5);
    $dayFmt = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEEEE');
    $dayNames = [];
    // Generate day names starting from Monday
    $refMonday = new DateTime('2024-01-01'); // A known Monday
    for ($wd = 0; $wd < 7; $wd++) {
        $dayNames[] = $dayFmt->format((clone $refMonday)->modify("+$wd days")->getTimestamp());
    }
} else {
    $dayNames = [
        __('Mon', 'fleetbooking'),
        __('Tue', 'fleetbooking'),
        __('Wed', 'fleetbooking'),
        __('Thu', 'fleetbooking'),
        __('Fri', 'fleetbooking'),
        __('Sat', 'fleetbooking'),
        __('Sun', 'fleetbooking'),
    ];
}

$today = date('Y-m-d');

header('Content-Type: text/html; charset=utf-8');

?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <button class="btn btn-ghost" id="fb-cal-prev" data-year="<?= $prevYear ?>" data-month="<?= $prevMonth ?>">&#8592;
        <?= htmlspecialchars(__('Previous', 'fleetbooking'), ENT_QUOTES, 'UTF-8') ?></button>
    <strong><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></strong>
    <button class="btn btn-ghost" id="fb-cal-next" data-year="<?= $nextYear ?>"
        data-month="<?= $nextMonth ?>"><?= htmlspecialchars(__('Next', 'fleetbooking'), ENT_QUOTES, 'UTF-8') ?>
        &#8594;</button>
</div>

<table class="tab_cadre" style="width:100%;border-collapse:collapse;font-size:0.9em;">
    <thead>
        <tr>
            <?php foreach ($dayNames as $n): ?>
                <th style="padding:5px;text-align:center;background:#f5f5f5;border:1px solid #ddd;">
                    <?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?>
                </th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php
        $col = $firstWeekday;
        $day = 1;
        echo '<tr>';
        for ($i = 1; $i < $firstWeekday; $i++)
            echo '<td style="border:1px solid var(--fb-calendar-border);" aria-hidden="true">&nbsp;</td>';

        while ($day <= $daysInMonth):
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $status = $bookedDays[$dateStr] ?? '';

            if ($status === 'approved') {
                $bg = '#f8d7da';
                $title = __('Booked', 'fleetbooking');
            } elseif ($status === 'pending') {
                $bg = '#fff3cd';
                $title = __('Pending', 'fleetbooking');
            } else {
                $bg = '#d4edda';
                $title = __('Available', 'fleetbooking');
                $cursor = 'cursor:pointer;';
            }

            $border = ($dateStr === $today) ? 'border:2px solid #007bff;' : 'border:1px solid #ddd;';
            $cursor = ($status === '') ? 'cursor:pointer;' : '';
            $dataCursor = ($status === '') ? "data-date='" . htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') . "'" : '';

            echo "<td class='fb-cal-day' $dataCursor data-status='" . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . "' title='" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "'
          style='text-align:center;padding:6px;background:$bg;$border$cursor'>$day</td>";

            if ($col === 7 && $day < $daysInMonth) {
                echo '</tr><tr>';
                $col = 0;
            }
            $col++;
            $day++;
        endwhile;

        while ($col <= 7) {
            echo '<td style="border:1px solid var(--fb-calendar-border);" aria-hidden="true">&nbsp;</td>';
            $col++;
        }
        echo '</tr>';
        ?>
    </tbody>
</table>

<div style="margin-top:8px;font-size:0.8em;display:flex;gap:14px;">
    <span><span style="background:#d4edda;padding:2px 8px;border-radius:3px;">&nbsp;</span>
        <?= htmlspecialchars(__('Available - Click to select', 'fleetbooking'), ENT_QUOTES, 'UTF-8') ?></span>
    <span><span style="background:#fff3cd;padding:2px 8px;border-radius:3px;">&nbsp;</span>
        <?= htmlspecialchars(__('Pending', 'fleetbooking'), ENT_QUOTES, 'UTF-8') ?></span>
    <span><span style="background:#f8d7da;padding:2px 8px;border-radius:3px;">&nbsp;</span>
        <?= htmlspecialchars(__('Booked', 'fleetbooking'), ENT_QUOTES, 'UTF-8') ?></span>
</div>