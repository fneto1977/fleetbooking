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
            $('#fb_start_datetime, #fb_end_datetime').trigger('change');

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
            checkCnhExpiry();
        });

        // ----------------------------------------------------
        // Driver Information & Identification Validation Logic
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
            var $msg = $('#fb_cpf_msg');
            var rawVal = $('#fb_driver_cpf').val() || '';
            var clean = rawVal.replace(/\D/g, '');

            if (clean.length === 0) {
                if (showEmptyError) {
                    var emptyMsg = i18n.cpf_mandatory || 'O CPF é obrigatório quando a identificação por CPF está selecionada.';
                    $msg.html('<span style="color:#dc2626;font-weight:500;">⚠️ ' + emptyMsg + '</span>');
                    $('#fb_driver_cpf').css('border-color', '#dc2626');
                    if (input) input.setCustomValidity(emptyMsg);
                    return false;
                } else {
                    $msg.empty();
                    $('#fb_driver_cpf').css('border-color', '');
                    if (input) input.setCustomValidity('');
                    return null;
                }
            }

            if (clean.length === 11) {
                if (isValidCpf(clean)) {
                    var okMsg = i18n.cpf_valid || 'CPF válido.';
                    $msg.html('<span style="color:#16a34a;font-weight:500;">✅ ' + okMsg + '</span>');
                    $('#fb_driver_cpf').css('border-color', '#16a34a');
                    if (input) input.setCustomValidity('');
                    return true;
                } else {
                    var errMsg = i18n.cpf_invalid || 'Número de CPF inválido.';
                    $msg.html('<span style="color:#dc2626;font-weight:500;">⚠️ ' + errMsg + '</span>');
                    $('#fb_driver_cpf').css('border-color', '#dc2626');
                    if (input) input.setCustomValidity(errMsg);
                    return false;
                }
            } else {
                if (showEmptyError) {
                    var errMsg = i18n.cpf_invalid || 'Número de CPF inválido.';
                    $msg.html('<span style="color:#dc2626;font-weight:500;">⚠️ ' + errMsg + '</span>');
                    $('#fb_driver_cpf').css('border-color', '#dc2626');
                    if (input) input.setCustomValidity(errMsg);
                    return false;
                } else {
                    $msg.empty();
                    $('#fb_driver_cpf').css('border-color', '');
                    if (input) input.setCustomValidity('');
                    return false;
                }
            }
        }

        function validateRegUI(showEmptyError) {
            var input = document.getElementById('fb_driver_registration');
            var $msg = $('#fb_reg_msg');
            var rawVal = $('#fb_driver_registration').val() || '';
            var clean = rawVal.replace(/\D/g, '');

            if (clean.length === 0) {
                if (showEmptyError) {
                    var emptyMsg = i18n.reg_mandatory || 'A matrícula do funcionário é obrigatória.';
                    $msg.html('<span style="color:#dc2626;font-weight:500;">⚠️ ' + emptyMsg + '</span>');
                    $('#fb_driver_registration').css('border-color', '#dc2626');
                    if (input) input.setCustomValidity(emptyMsg);
                    return false;
                } else {
                    $msg.empty();
                    $('#fb_driver_registration').css('border-color', '');
                    if (input) input.setCustomValidity('');
                    return null;
                }
            }

            if (isValidReg(clean)) {
                var okMsg = i18n.reg_valid || 'Matrícula válida.';
                $msg.html('<span style="color:#16a34a;font-weight:500;">✅ ' + okMsg + '</span>');
                $('#fb_driver_registration').css('border-color', '#16a34a');
                if (input) input.setCustomValidity('');
                return true;
            } else {
                if (showEmptyError) {
                    var errMsg = i18n.reg_invalid_format || 'A matrícula do funcionário deve conter de 3 a 4 dígitos numéricos (ex: 0000).';
                    $msg.html('<span style="color:#dc2626;font-weight:500;">⚠️ ' + errMsg + '</span>');
                    $('#fb_driver_registration').css('border-color', '#dc2626');
                    if (input) input.setCustomValidity(errMsg);
                    return false;
                } else {
                    $msg.empty();
                    $('#fb_driver_registration').css('border-color', '');
                    if (input) input.setCustomValidity('');
                    return false;
                }
            }
        }

        function handleIdTypeToggle() {
            var isCpf = $('#fb_id_type_cpf').is(':checked');
            if (isCpf) {
                $('#fb_cpf_field_wrapper').show();
                $('#fb_reg_field_wrapper').hide();
                $('#fb_driver_cpf').prop('required', true);
                $('#fb_driver_registration').prop('required', false).val('').css('border-color', '');
                $('#fb_reg_msg').empty();
                var regInput = document.getElementById('fb_driver_registration');
                if (regInput) regInput.setCustomValidity('');
            } else {
                $('#fb_cpf_field_wrapper').hide();
                $('#fb_reg_field_wrapper').show();
                $('#fb_driver_cpf').prop('required', false).val('').css('border-color', '');
                $('#fb_cpf_msg').empty();
                var cpfInput = document.getElementById('fb_driver_cpf');
                if (cpfInput) cpfInput.setCustomValidity('');
                $('#fb_driver_registration').prop('required', true);
            }
        }

        $(document).on('change', 'input[name="driver_id_type"]', handleIdTypeToggle);
        handleIdTypeToggle();

        // CPF Input Mask (000.000.000-00) & Realtime Validation
        $(document).on('input', '#fb_driver_cpf', function () {
            var v = $(this).val().replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 9) {
                v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
            } else if (v.length > 6) {
                v = v.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
            } else if (v.length > 3) {
                v = v.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
            }
            $(this).val(v);
            var clean = v.replace(/\D/g, '');
            if (clean.length === 11 || clean.length === 0) {
                validateCpfUI(false);
            }
        });
        $(document).on('blur', '#fb_driver_cpf', function () {
            validateCpfUI(true);
        });

        // Registration Input Mask (0000, 3 to 4 numeric digits) & Realtime Validation
        $(document).on('input', '#fb_driver_registration', function () {
            var v = $(this).val().replace(/\D/g, '');
            if (v.length > 4) v = v.substring(0, 4);
            $(this).val(v);
            if (v.length >= 3 || v.length === 0) {
                validateRegUI(false);
            }
        });
        $(document).on('blur', '#fb_driver_registration', function () {
            validateRegUI(true);
        });

        // CNH Number Mask (digits only, max 9 digits)
        $(document).on('input', '#fb_driver_cnh_number', function () {
            var v = $(this).val().replace(/\D/g, '');
            if (v.length > 9) v = v.substring(0, 9);
            $(this).val(v);
        });

        // Realtime CNH Expiry vs Vehicle Return Date validation
        function checkCnhExpiry() {
            var cnhVal = $('#fb_driver_cnh_expiry').val();
            var retVal = $('#fb_end_datetime').val();
            var $msg = $('#fb_cnh_expiry_msg');
            var cnhInput = document.getElementById('fb_driver_cnh_expiry');

            if (!cnhVal || !retVal) {
                $msg.empty();
                $('#fb_driver_cnh_expiry').css('border-color', '');
                if (cnhInput) cnhInput.setCustomValidity('');
                return true;
            }

            var cnhDate = cnhVal.substring(0, 10);
            var retDate = retVal.substring(0, 10);

            if (cnhDate < retDate) {
                var warnText = i18n.cnh_expired_warning || 'Atenção: CNH estará vencida na data de devolução do veículo.';
                $msg.html('<span style="color:#dc2626;font-weight:500;">⚠️ ' + warnText + '</span>');
                $('#fb_driver_cnh_expiry').css('border-color', '#dc2626');
                if (cnhInput) {
                    cnhInput.setCustomValidity(warnText);
                }
                return false;
            } else {
                var okText = i18n.cnh_valid || 'CNH válida para o período';
                $msg.html('<span style="color:#16a34a;font-weight:500;">✅ ' + okText + '</span>');
                $('#fb_driver_cnh_expiry').css('border-color', '#16a34a');
                if (cnhInput) {
                    cnhInput.setCustomValidity('');
                }
                return true;
            }
        }

        $(document).on('change input', '#fb_driver_cnh_expiry, #fb_end_datetime', checkCnhExpiry);

        // Form submit guard
        $('#fleetbooking-form').on('submit', function (e) {
            var isReg = $('#fb_id_type_reg').is(':checked');
            if (isReg) {
                if (!validateRegUI(true)) {
                    e.preventDefault();
                    var regErr = i18n.reg_invalid_format || 'A matrícula do funcionário deve conter de 3 a 4 dígitos numéricos (ex: 0000).';
                    var regVal = ($('#fb_driver_registration').val() || '').replace(/\D/g, '');
                    if (regVal.length === 0 && i18n.reg_mandatory) {
                        regErr = i18n.reg_mandatory;
                    }
                    alert(regErr);
                    $('#fb_driver_registration').focus();
                    return false;
                }
            } else {
                if (!validateCpfUI(true)) {
                    e.preventDefault();
                    var cpfErr = i18n.cpf_invalid || 'Número de CPF inválido.';
                    var cpfVal = ($('#fb_driver_cpf').val() || '').replace(/\D/g, '');
                    if (cpfVal.length === 0 && i18n.cpf_mandatory) {
                        cpfErr = i18n.cpf_mandatory;
                    }
                    alert(cpfErr);
                    $('#fb_driver_cpf').focus();
                    return false;
                }
            }

            var cnhNum = ($('#fb_driver_cnh_number').val() || '').replace(/\D/g, '');
            if (!cnhNum || cnhNum.length > 9) {
                e.preventDefault();
                alert(i18n.cnh_invalid_number || 'O número da CNH deve conter apenas números (até 9 dígitos).');
                $('#fb_driver_cnh_number').focus();
                return false;
            }

            if (!checkCnhExpiry()) {
                e.preventDefault();
                alert(i18n.cnh_expired_warning || 'Atenção: CNH estará vencida na data de devolução do veículo.');
                $('#fb_driver_cnh_expiry').focus();
                return false;
            }
        });
    });
})();
