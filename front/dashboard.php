<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_request", READ);

Html::header(
    __('Fleet Reservation Dashboard', 'fleetbooking'),
    '/plugins/fleetbooking/front/dashboard.php',
    'tools',
    'GlpiPlugin\Fleetbooking\Request'
);

$ajaxUrl = Plugin::getWebDir('fleetbooking', true) . '/ajax/calendar.php';
$fcUrl = Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.global.min.js';
$fcLocaleUrl = Plugin::getWebDir('fleetbooking', true) . '/js/fullcalendar.pt-br.global.min.js';

echo "<div class='center fleetbooking-container'>";
echo "<h2 style='text-align: left; margin-bottom: 20px; font-weight: 600;'><i class='ti ti-calendar-event' style='vertical-align: middle; margin-right: 8px;'></i>" . __('Fleet Reservation Dashboard', 'fleetbooking') . "</h2>";

echo "<div style='background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #eef2f5; min-height: 600px;'>";
echo "<div id='global-calendar'></div>";
echo "</div>";
echo "</div>";

// Inject GLPI language for FullCalendar
$glpiLocale = $_SESSION['glpilanguage'] ?? 'pt_BR';
// Map GLPI locale to FullCalendar locale
$fcLocaleMap = [
    'pt_BR' => 'pt-br',
    'en_GB' => 'en-gb',
    'es_ES' => 'es',
    'fr_FR' => 'fr',
];
$fcLocale = $fcLocaleMap[$glpiLocale] ?? 'pt-br';
echo "<script>window.fleetbooking_config = window.fleetbooking_config || {}; window.fleetbooking_config.locale = '" . $fcLocale . "';</script>\n";

?>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        var calendarEl = document.getElementById("global-calendar");
        var initialized = false;

        function initGlobalCalendar() {
            if (initialized) return;

            // Safely initialize globalLocales array if needed before checking loaded locales
            if (typeof FullCalendar !== "undefined" && typeof FullCalendar.globalLocales === "undefined") {
                FullCalendar.globalLocales = [];
            }

            initialized = true;
            var calendarOptions = {
                initialView: "dayGridMonth",
                height: "auto", // Adjust natural height to avoid empty space stretching
                headerToolbar: {
                    left: "prev,next today",
                    center: "title",
                    right: "dayGridMonth,timeGridWeek,listMonth"
                },
                events: "<?php echo $ajaxUrl; ?>",
                eventClick: function (info) {
                    if (info.event.url) {
                        info.jsEvent.preventDefault();
                        window.open(info.event.url, '_blank');
                    } else {
                        showDashboardEventTooltip(info);
                    }
                }
            };

            // Get locale from GLPI session — injected via PHP
            var glpiLocale = window.fleetbooking_config?.locale || 'pt-br';
            calendarOptions.locale = glpiLocale;

            var calendar = new FullCalendar.Calendar(calendarEl, calendarOptions);
            calendar.render();
        }

        function loadScript(src, id, callback) {
            var script = document.getElementById(id);
            if (!script) {
                script = document.createElement("script");
                script.id = id;
                script.src = src;
                script.onload = callback;
                document.head.appendChild(script);
            } else {
                if (script.getAttribute('data-loaded') === 'true' || typeof FullCalendar !== "undefined") {
                    callback();
                } else {
                    script.addEventListener('load', callback);
                }
            }
        }

        function showDashboardEventTooltip(info) {
            // Remove any existing tooltip
            $('.fb-event-tooltip').remove();

            var title = info.event.title;
            var details = info.event.extendedProps.details || '';
            if (!details) {
                var start = info.event.start ? info.event.start.toLocaleString() : '';
                var end = info.event.end ? info.event.end.toLocaleString() : '';
                details = "<?= addslashes(__('Period', 'fleetbooking')) ?>: " + start + " <?= addslashes(__('to', 'fleetbooking')) ?> " + end;
            }
            var i18n = window.fleetbooking_config?.i18n || {};

            var closeLabel = i18n.close || 'Close';

            var $tooltip = $('<div>')
                .addClass('fb-event-tooltip')
                .attr('role', 'dialog')
                .attr('aria-label', i18n.event_details || 'Event details')
                .append(
                    $('<button>')
                        .addClass('fb-event-tooltip-close')
                        .attr('aria-label', closeLabel)
                        .html('&times;')
                        .on('click', function () { $tooltip.remove(); })
                )
                .append(
                    $('<div>')
                        .addClass('fb-event-tooltip-body')
                        .html('<strong>' + title + '</strong>' + (details ? '\n' + details : ''))
                );

            $('body').append($tooltip);

            // Position near the clicked event
            var jsEvent = info.jsEvent;
            $tooltip.css({
                position: 'fixed',
                left: Math.min(jsEvent.clientX + 10, window.innerWidth - 320) + 'px',
                top: Math.min(jsEvent.clientY + 10, window.innerHeight - 200) + 'px'
            });

            // Close on Escape key
            $(document).on('keydown.fb-tooltip', function (e) {
                if (e.key === 'Escape') {
                    $tooltip.remove();
                    $(document).off('keydown.fb-tooltip');
                }
            });

            // Close on click outside
            setTimeout(function () {
                $(document).on('click.fb-tooltip', function (e) {
                    if (!$tooltip.is(e.target) && $tooltip.has(e.target).length === 0) {
                        $tooltip.remove();
                        $(document).off('click.fb-tooltip');
                    }
                });
            }, 0);
        }

        // First load main FullCalendar script
        loadScript("<?php echo $fcUrl; ?>", "local-fullcalendar-script", function () {
            // Pre-initialize globalLocales on FullCalendar if present/needed before loading locale file
            if (typeof FullCalendar !== "undefined" && typeof FullCalendar.globalLocales === "undefined") {
                FullCalendar.globalLocales = [];
            }
            // Then load locale script
            loadScript("<?php echo $fcLocaleUrl; ?>", "local-fullcalendar-locale-script", function () {
                var script = document.getElementById("local-fullcalendar-locale-script");
                if (script) script.setAttribute('data-loaded', 'true');
                initGlobalCalendar();
            });
        });
    });
</script>
<?php

Html::footer();
