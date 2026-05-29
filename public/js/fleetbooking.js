/**
 * FleetBooking JavaScript Logic - Weekly Calendar with 2-Click Selection
 * Consolidated and fixed to play nicely with GLPI 11 CSRF and DOM changes.
 */

(function () {
    // 1. Keep vehicle selection persistent across DOM updates
    var clickState = 0;
    var selectedStart = null;

    $(document).ready(function () {
        var config = window.fleetbooking_config || {};
        var calItemsId = config.cal_items_id;
        var i18n = config.i18n || {};
        var itemType = config.itemtype || '';
        var ajaxUrl = config.ajax_url || '';
        var weekStart = config.week_start_date;
        var curWeekNum = config.current_week_number;
        var curYear = config.current_year;

        function forceVehicleVal() {
            if (!calItemsId) return;
            var $sel = $('select[name="items_id"]');
            if (!$sel.length || $sel.val() == calItemsId) return;
            $sel.val(calItemsId);
            if ($sel.data('select2')) {
                try { $sel.trigger('change.select2'); } catch (e) { }
            }
        }

        forceVehicleVal();

        if (window.MutationObserver && calItemsId) {
            var count = 0;
            var obs = new MutationObserver(function () {
                forceVehicleVal();
                if (++count > 30) obs.disconnect();
            });
            obs.observe(document.body, { childList: true, subtree: true });
            setTimeout(function () { obs.disconnect(); }, 6000);
        }

        // Fallback timeout for edge cases where MutationObserver misses the DOM change
        setTimeout(forceVehicleVal, 2000);

        function buildUrl(vid) {
            var base = '/plugins/fleetbooking/front/request.form.php?cal_items_id=' + encodeURIComponent(vid);
            if (weekStart) {
                base += '&week_start_date=' + encodeURIComponent(weekStart)
                    + '&current_week_number=' + curWeekNum
                    + '&current_year=' + curYear;
            }
            return base;
        }

        $(document).on('change select2:select', 'select[name="items_id"]', function () {
            var vid = $(this).val();
            if (!vid || vid === '0') return;
            if (calItemsId && vid == calItemsId) return;
            window.location.href = buildUrl(vid);
        });

        if (!calItemsId && $('#fleetbooking-form').length > 0) {
            setTimeout(function () {
                var vid = $('select[name="items_id"]').val();
                if (vid && vid !== '0') window.location.href = buildUrl(vid);
            }, 900);
        }

        // ----------------------------------------------------
        // Calendar & Availability Logic
        // ----------------------------------------------------

        var $msgDiv = $('#fb_validation_msg');

        function checkAvailability(startDt, endDt) {
            var vehicleId = calItemsId || $('select[name="items_id"]').val();

            if (!vehicleId || !startDt || !endDt) { $msgDiv.empty(); return; }

            // Loading spinner with i18n fallback
            var $spinner = $('<span>').addClass('fb-spinner').html('&#9203; ');
            $msgDiv.empty().append($spinner).append(document.createTextNode(
                i18n.validating || (console.warn('FleetBooking: i18n key "validating" missing'), 'Validating availability...')
            ));

            // CSRF token extraction with empty check (Issue #17)
            // Try meta tag first, then global var, then hidden input as fallback
            var csrfToken = $('meta[name="glpi:csrf_token"]').attr('content')
                || window.GLPI_CSRF_TOKEN
                || $('input[name="_glpi_csrf_token"]').val()
                || '';
            if (!csrfToken) {
                console.error('FleetBooking: CSRF token missing. Cannot validate availability.');
                $msgDiv.empty();
                $msgDiv.append($('<span>').addClass('fb-msg-error').text(
                    i18n.csrf_missing || 'Security token missing. Please refresh the page.'
                ));
                return;
            }

            $.ajax({
                url: ajaxUrl + '/availability.php',
                type: 'POST',
                dataType: 'json',
                headers: {
                    'X-Glpi-Csrf-Token': csrfToken
                },
                data: {
                    itemtype: itemType,
                    items_id: vehicleId,
                    start: startDt,
                    end: endDt
                },
                success: function (res) {
                    $msgDiv.empty();
                    if (res.ok) {
                        var $span = $('<span>').addClass('fb-msg-success').text('\u2713 ' + (
                            i18n.available || (console.warn('FleetBooking: i18n key "available" missing'), 'Period available.')
                        ));
                        $msgDiv.append($span);
                    } else {
                        var msg = (res.errors || []).join(' ');
                        var $span = $('<span>').addClass('fb-msg-warning').text('\u26A0 ' + msg);
                        $msgDiv.append($span);
                    }
                },
                error: function () {
                    $msgDiv.empty();
                    var $span = $('<span>').addClass('fb-msg-muted').text(
                        i18n.validation_error || (console.warn('FleetBooking: i18n key "validation_error" missing'), 'Could not validate availability.')
                    );
                    $msgDiv.append($span);
                }
            });
        }

        function pad(n) { return n < 10 ? '0' + n : '' + n; }

        function fmtForPicker(d) {
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }

        function fmtForServer(d) {
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00';
        }

        function applyToForm(startStr, endStr) {
            var sD = new Date(startStr.replace(' ', 'T'));
            var eD = new Date(endStr.replace(' ', 'T'));

            $('#fb_start_datetime').val(fmtForPicker(sD));
            $('#fb_end_datetime').val(fmtForPicker(eD));
            $('#fb_selected_start').val(startStr.substring(0, 19));
            $('#fb_selected_end').val(endStr.substring(0, 19));

            checkAvailability(fmtForServer(sD), fmtForServer(eD));
        }

        function highlightRange(startStr, endStr) {
            $('.fb-hour-slot').removeClass('fb-selected fb-selected-start fb-selected-end');
            $('.fb-hour-slot.fb-available').each(function () {
                var s = $(this).attr('data-start');
                var e = $(this).attr('data-end');
                if (s === startStr) $(this).addClass('fb-selected-start');
                if (e === endStr) $(this).addClass('fb-selected-end');
                if (s >= startStr && e <= endStr) $(this).addClass('fb-selected');
            });
        }

        function updatePanel(startStr, endStr) {
            var s = new Date(startStr.replace(' ', 'T')), e = new Date(endStr.replace(' ', 'T'));
            var h = Math.round((e - s) / 3600000);
            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            function fmtDisp(d) {
                return d.getFullYear() + '/' + pad(d.getMonth() + 1) + '/' + pad(d.getDate())
                    + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            }
            $('#fb_selection_details').text(
                (i18n.start_label || (console.warn('FleetBooking: i18n key "start_label" missing'), 'Start'))
                + ': ' + fmtDisp(s) + '  |  '
                + (i18n.end_label || (console.warn('FleetBooking: i18n key "end_label" missing'), 'End'))
                + ': ' + fmtDisp(e) + '  |  ' + h + 'h'
            );
            $('#fb_selection_info').show();
        }

        $(document).on('click', '.fb-hour-slot.fb-available', function (e) {
            e.stopPropagation();
            var slotStart = $(this).attr('data-start');
            var slotEnd = $(this).attr('data-end');
            if (!slotStart || !slotEnd) return;

            if (clickState === 0) {
                clickState = 1;
                selectedStart = slotStart;
                $('.fb-hour-slot').removeClass('fb-selected fb-selected-start fb-selected-end');
                $(this).addClass('fb-selected-start fb-selected');
                updatePanel(slotStart, slotEnd);
            } else {
                if (slotStart < selectedStart) {
                    selectedStart = slotStart;
                    $('.fb-hour-slot').removeClass('fb-selected fb-selected-start fb-selected-end');
                    $(this).addClass('fb-selected-start fb-selected');
                    updatePanel(slotStart, slotEnd);
                    return;
                }
                clickState = 0;
                highlightRange(selectedStart, slotEnd);
                updatePanel(selectedStart, slotEnd);
                applyToForm(selectedStart, slotEnd);
            }
        });

        $(document).on('mouseenter', '.fb-hour-slot.fb-available', function () {
            if (clickState !== 1 || !selectedStart) return;
            var hoverStart = $(this).attr('data-start');
            var hoverEnd = $(this).attr('data-end');
            if (hoverStart && hoverStart >= selectedStart) {
                highlightRange(selectedStart, hoverEnd);
                updatePanel(selectedStart, hoverEnd);
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && clickState === 1) {
                clickState = 0; selectedStart = null;
                $('.fb-hour-slot').removeClass('fb-selected fb-selected-start fb-selected-end');
                $('#fb_selection_info').hide();
            }
        });

        // Manual datepicker change → validate cross-week selections
        $(document).on('change', '#fb_start_datetime, #fb_end_datetime', function () {
            var startVal = $('#fb_start_datetime').val();
            var endVal = $('#fb_end_datetime').val();
            if (startVal && endVal) {
                var sD = new Date(startVal);
                var eD = new Date(endVal);
                updatePanel(fmtForServer(sD).replace(' ', 'T'), fmtForServer(eD).replace(' ', 'T'));
                checkAvailability(fmtForServer(sD), fmtForServer(eD));
            }
        });
    });
})();
