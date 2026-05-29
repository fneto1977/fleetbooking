/**
 * FleetBooking — Approval Calendar initialisation.
 *
 * Reads configuration from data-* attributes on the #approval-calendar
 * container and bootstraps FullCalendar with script lazy-loading and
 * retry logic for DOM readiness.
 *
 * Expected data attributes on #approval-calendar:
 *   data-initial-date     Initial visible date (YYYY-MM-DD).
 *   data-slot-min-time    Earliest visible time slot (HH:mm:ss).
 *   data-slot-max-time    Latest visible time slot (HH:mm:ss).
 *   data-events-url       JSON feed endpoint for calendar events.
 *   data-fc-url           FullCalendar core script URL.
 *   data-fc-locale-url    FullCalendar pt-BR locale script URL.
 *
 * @license GPL-2.0-or-later
 */

(function () {
    var maxAttempts = 100;
    var attempt = 0;
    var initialized = false;

    function initApprovalCalendar() {
        if (initialized) {
            return;
        }

        var calendarEl = document.getElementById("approval-calendar");
        if (!calendarEl) {
            if (attempt < maxAttempts) {
                attempt++;
                setTimeout(initApprovalCalendar, 100);
            }
            return;
        }

        if (typeof FullCalendar === "undefined") {
            if (attempt < maxAttempts) {
                attempt++;
                setTimeout(initApprovalCalendar, 100);
            }
            return;
        }

        // Safely initialise globalLocales array if needed before checking
        // loaded locales.
        if (
            typeof FullCalendar !== "undefined" &&
            typeof FullCalendar.globalLocales === "undefined"
        ) {
            FullCalendar.globalLocales = [];
        }

        initialized = true;

        var eventsUrl = calendarEl.getAttribute("data-events-url") || "";
        var localeVal = calendarEl.dataset.locale || "pt-br";

        console.log("[FleetBooking] Calendar initializing — eventsUrl:", eventsUrl, "locale:", localeVal);

        var calendarOptions = {
            initialView: "timeGridWeek",
            initialDate: calendarEl.getAttribute("data-initial-date") || "",
            allDaySlot: false,
            slotMinTime: calendarEl.getAttribute("data-slot-min-time") || "07:00:00",
            slotMaxTime: calendarEl.getAttribute("data-slot-max-time") || "18:00:00",
            height: "auto",
            headerToolbar: {
                left: "prev,next today",
                center: "title",
                right: "timeGridWeek,dayGridMonth"
            },
            events: eventsUrl,
            eventSourceSuccess: function (content, xhr) {
                console.log("[FleetBooking] Events fetched successfully — count:", Array.isArray(content) ? content.length : "not an array", "response:", content);
                return content;
            },
            eventSourceFailure: function (error, xhr) {
                console.error("[FleetBooking] Events fetch FAILED — status:", xhr ? xhr.status : "unknown", "error:", error);
            },
            eventClick: function (info) {
                var message = info.event.title;
                if (info.event.extendedProps.description) {
                    message += "\n" + info.event.extendedProps.description;
                }
                showEventTooltip(info.el, message);
            }
        };

        // Read locale from GLPI config injected by PHP or fallback to pt-br
        var glpiLocale = calendarEl.dataset.locale
            || (window.fleetbooking_config && window.fleetbooking_config.locale)
            || 'pt-br';
        calendarOptions.locale = glpiLocale;

        var calendar = new FullCalendar.Calendar(calendarEl, calendarOptions);
        calendar.render();
        console.log("[FleetBooking] Calendar rendered successfully. Events will load shortly via:", eventsUrl);
    }

    /**
     * Display a styled, dismissible tooltip for a calendar event.
     *
     * Replaces browser-native alert() with a DOM-based overlay that can be
     * closed by clicking outside, pressing Escape, or clicking the × button.
     *
     * @param {Element} anchorEl  The DOM element to position the tooltip near.
     * @param {string}  text      The message to display (newlines are preserved).
     */
    function showEventTooltip(anchorEl, text) {
        // Remove any existing tooltip
        var existing = document.getElementById("fb-event-tooltip");
        if (existing) {
            existing.parentNode.removeChild(existing);
        }

        var i18nLabels = (window.fleetbooking_config && window.fleetbooking_config.i18n) || {};

        var tooltip = document.createElement("div");
        tooltip.id = "fb-event-tooltip";
        tooltip.className = "fb-event-tooltip";
        tooltip.setAttribute("role", "dialog");
        tooltip.setAttribute("aria-label", i18nLabels.event_details || "Event details");
        // Create close button
        var closeBtn = document.createElement("button");
        closeBtn.id = "fb-tooltip-close";
        closeBtn.className = "fb-event-tooltip-close";
        closeBtn.setAttribute("aria-label", i18nLabels.close || "Close");
        closeBtn.innerHTML = "&times;";
        closeBtn.addEventListener("click", function () {
            tooltip.remove();
        });

        // Create body
        var bodyDiv = document.createElement("div");
        bodyDiv.className = "fb-event-tooltip-body";
        bodyDiv.textContent = text;  // SAFE: no HTML interpretation

        // Assemble tooltip
        tooltip.appendChild(closeBtn);
        tooltip.appendChild(bodyDiv);

        document.body.appendChild(tooltip);

        // Position near the anchor element
        var anchorRect = anchorEl.getBoundingClientRect();
        var top = anchorRect.bottom + window.scrollY + 6;
        var left = anchorRect.left + window.scrollX;
        // Keep tooltip within viewport
        if (left + 400 > window.innerWidth) {
            left = window.innerWidth - 410;
        }
        if (left < 10) {
            left = 10;
        }
        tooltip.style.top = top + "px";
        tooltip.style.left = left + "px";

        function dismiss() {
            if (tooltip.parentNode) {
                tooltip.parentNode.removeChild(tooltip);
            }
            document.removeEventListener("click", onOutsideClick);
            document.removeEventListener("keydown", onEscape);
        }

        function onOutsideClick(e) {
            if (!tooltip.contains(e.target)) {
                dismiss();
            }
        }

        function onEscape(e) {
            if (e.key === "Escape") {
                dismiss();
            }
        }

        // Defer listeners so the current click doesn't immediately dismiss
        setTimeout(function () {
            document.addEventListener("click", onOutsideClick);
            document.addEventListener("keydown", onEscape);
        }, 0);
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
            if (
                script.getAttribute("data-loaded") === "true" ||
                typeof FullCalendar !== "undefined"
            ) {
                callback();
            } else {
                script.addEventListener("load", callback);
            }
        }
    }

    // Bootstrapping function that waits for the calendar container to exist in the DOM
    // before resolving its attributes and loading the dependencies.
    function bootstrap() {
        var calendarEl = document.getElementById("approval-calendar");
        if (!calendarEl) {
            if (attempt < maxAttempts) {
                attempt++;
                setTimeout(bootstrap, 100);
            }
            return;
        }

        var fcUrl = calendarEl.getAttribute("data-fc-url") || "";
        var fcLocaleUrl = calendarEl.getAttribute("data-fc-locale-url") || "";

        loadScript(fcUrl, "local-fullcalendar-script", function () {
            if (
                typeof FullCalendar !== "undefined" &&
                typeof FullCalendar.globalLocales === "undefined"
            ) {
                FullCalendar.globalLocales = [];
            }
            loadScript(fcLocaleUrl, "local-fullcalendar-locale-script", function () {
                var script = document.getElementById("local-fullcalendar-locale-script");
                if (script) {
                    script.setAttribute("data-loaded", "true");
                }
                initApprovalCalendar();
            });
        });
    }

    bootstrap();
})();