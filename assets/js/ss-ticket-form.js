/**
 * SS Ticket Form — Lógica del formulario de compra de tickets.
 *
 * Escucha cambios del renderer Konva en #ss_seats_input (change/input events),
 * actualiza resumen y total, y maneja el flujo de compra via AJAX.
 *
 * Globals esperados:
 *   ssTicketForm.ajaxUrl
 *   ssTicketForm.nonce
 *   ssTicketForm.cartUrl
 *   ssTicketForm.eventId
 *   ssTicketForm.productId
 *   ssTicketForm.saleMode
 */
(function ($) {
    'use strict';

    if (typeof ssTicketForm === 'undefined') { return; }

    $(function () {

        var config      = ssTicketForm;

        // Evento ya finalizado: el botón ya quedó deshabilitado por PHP con el
        // mensaje "Este evento ya finalizó" — no inicializar nada de lo de abajo,
        // que de lo contrario lo reactiva en cuanto detecta una silla seleccionada
        // (el mapa no debería dejar seleccionar nada, pero esto es la segunda barrera).
        if (config.isPast) { return; }

        var saleMode    = config.saleMode || 'seat';
        var $form       = $('.ss-ticket-form');
        var $summary    = $form.find('.ss-ticket-form__summary');
        var $summaryCount = $form.find('.ss-ticket-form__summary-count');
        var $summaryItems = $form.find('.ss-ticket-form__summary-items');
        var $totalPrice = $form.find('.ss-ticket-form__total-price');
        var $buyBtn     = $form.find('.ss-ticket-form__buy-btn');
        var $seatsInput    = $('#ss_seats_input');
        var $seatsSelected = $('#ss-selected-seats');
        var $zoneInput     = $('#ss-ticket-form-zone');
        var buyText        = $buyBtn.text();
        var $steps         = $form.find('.ss-steps__item');

        // ── Compra flotante: shortcut visible solo mientras la tarjeta real
        //    (.cta-section, con el botón de compra) no está en pantalla ──
        var $floatBar   = $('#ss-float-buy');
        var $floatDots  = $floatBar.find('.ss-float-buy__dot');
        var $floatCount = $('#ss-float-buy-count');
        var $floatTotal = $('#ss-float-buy-total');
        var $floatBtn   = $('#ss-float-buy-btn');

        if ($floatBar.length && window.IntersectionObserver) {
            var ctaEl = $form.find('.cta-section')[0];
            if (ctaEl) {
                new IntersectionObserver(function (entries) {
                    $floatBar.toggleClass('is-visible', !entries[0].isIntersecting);
                    $floatBar.attr('aria-hidden', entries[0].isIntersecting ? 'true' : 'false');
                }, { threshold: 0 }).observe(ctaEl);
            }
        }

        $floatBtn.on('click', function () {
            $buyBtn.trigger('click');
        });

        // ── Pasos de compra: 0 Selecciona, 1 Resumen, 2 Pago ──
        function updateSteps(currentIndex) {
            $steps.each(function () {
                var idx = parseInt($(this).data('step'), 10);
                $(this)
                    .toggleClass('is-complete', idx < currentIndex)
                    .attr('aria-current', idx === currentIndex ? 'step' : null);
            });
            $floatDots.each(function () {
                var idx = parseInt($(this).data('step'), 10);
                $(this)
                    .toggleClass('is-complete', idx < currentIndex)
                    .attr('aria-current', idx === currentIndex ? 'step' : null);
            });
        }

        // URL de add-to-cart (inyectada por PHP en el template)
        var addToCartUrl = (typeof ssTicketFormExtra !== 'undefined' && ssTicketFormExtra.addToCartUrl)
                         ? ssTicketFormExtra.addToCartUrl
                         : '';

        // ── Estado ──

        var selectedSeats = [];
        var zoneQtys      = {};
        var isSubmitting  = false;

        // ── Mapa seatId → zona (construido desde ssLayoutData) ──
        var seatZoneMap = {};
        (function buildSeatZoneMap() {
            if (typeof ssLayoutData === 'undefined' || !ssLayoutData) { return; }

            // El layout puede venir en formato legacy ({rows:[...]}) o multi-piso
            // ({floors:[{rows:[...]}, ...]}) — mismo criterio que ss_layout_get_rows()
            // en PHP. Antes este mapa solo leía `.rows`, así que en cualquier evento
            // con layout multi-piso quedaba vacío y el precio de toda silla caía a $0.
            var rows = ssLayoutData.rows;
            if (!rows && Array.isArray(ssLayoutData.floors)) {
                rows = [];
                ssLayoutData.floors.forEach(function (floor) {
                    if (Array.isArray(floor.rows)) { rows = rows.concat(floor.rows); }
                });
            }
            if (!rows || !rows.length) { return; }

            rows.forEach(function (row) {
                if (!row.label || !row.zone) { return; }
                var zone  = String(row.zone).toUpperCase();
                var count = parseInt(row.count, 10) || 0;
                for (var i = 1; i <= count; i++) {
                    seatZoneMap[(row.label + i).toUpperCase()] = zone;
                }
            });
        }());

        // ── Detectar cambios del renderer Konva en #ss_seats_input ──
        // El renderer actualiza input.value directamente sin disparar eventos,
        // así que usamos polling como mecanismo principal de detección.

        var lastSeatsVal = '';

        function checkSeatsInput() {
            var val = ($seatsInput.val() || '').trim();
            if (val === lastSeatsVal) { return; }
            lastSeatsVal = val;
            selectedSeats = val ? val.split(/\s*,\s*/).filter(Boolean) : [];
            $seatsSelected.val(val);
            updateSummary();
        }

        if ($seatsInput.length) {
            // Polling para detectar cambios del renderer
            setInterval(checkSeatsInput, 150);

            // También escuchar eventos por si se disparan
            $seatsInput.on('change input', checkSeatsInput);
        }

        // Escuchar evento custom ss:seats-changed (si existe)
        document.addEventListener('ss:seats-changed', function (e) {
            if (e.detail && Array.isArray(e.detail.seats)) {
                selectedSeats = e.detail.seats;
                lastSeatsVal = selectedSeats.join(', ');
                $seatsInput.val(lastSeatsVal);
                $seatsSelected.val(lastSeatsVal);
                updateSummary();
            }
        });

        // ── Controles de cantidad (modo general/hybrid) ──

        $form.on('click', '.ss-qty-minus', function () {
            var zone  = $(this).data('zone');
            var $input = $form.find('.ss-qty-input[data-zone="' + zone + '"]');
            var val = parseInt($input.val(), 10) || 0;
            if (val > 0) {
                $input.val(val - 1);
                zoneQtys[zone] = val - 1;
                updateSummary();
            }
        });

        $form.on('click', '.ss-qty-plus', function () {
            var zone  = $(this).data('zone');
            var $input = $form.find('.ss-qty-input[data-zone="' + zone + '"]');
            var maxAttr = $input.attr('max');
            var max = (maxAttr !== undefined && maxAttr !== '') ? parseInt(maxAttr, 10) : 999;
            var val = parseInt($input.val(), 10) || 0;
            if (val < max) {
                $input.val(val + 1);
                zoneQtys[zone] = val + 1;
                updateSummary();
            }
        });

        // ── Actualizar resumen ──

        function updateSummary() {
            var items = [];
            var total = 0;
            var count = 0;

            if (saleMode === 'seat') {
                // Modo seat: los asientos seleccionados del mapa
                selectedSeats.forEach(function (seat) {
                    var price = getSeatPrice(seat);
                    items.push({ label: seat, price: price });
                    total += price;
                });
                count = selectedSeats.length;
            } else {
                // Modo general/hybrid: cantidades por zona
                $form.find('.ss-ticket-form__zone-item').each(function () {
                    var zone  = $(this).data('zone');
                    var price = parseFloat($(this).data('price')) || 0;
                    var qty   = zoneQtys[zone] || 0;
                    if (qty > 0) {
                        items.push({ label: zone + ' x' + qty, price: price * qty });
                        total += price * qty;
                        count += qty;
                    }
                });
            }

            // Actualizar contador
            $summaryCount.text(count);

            // Actualizar items del resumen
            var html = '';
            items.forEach(function (item) {
                html += '<div class="ss-ticket-form__summary-item">'
                      + '<span>' + escHtml(item.label) + '</span>'
                      + '<span>' + formatPrice(item.price) + '</span>'
                      + '</div>';
            });
            $summaryItems.html(html);
            $totalPrice.text(formatPrice(total));

            var hasSelection = count > 0;
            $summary.toggle(hasSelection);
            $buyBtn.prop('disabled', !hasSelection);
            updateSteps(hasSelection ? 1 : 0);

            // Compra flotante: desglose por zona (ej. "General x1 · ZONA_2 x2"),
            // para que no se confunda con cuántas entradas son de cada zona.
            var zoneBreakdown = {};
            if (saleMode === 'seat') {
                selectedSeats.forEach(function (seat) {
                    var zone = getSeatZoneName(seat) || '—';
                    zoneBreakdown[zone] = (zoneBreakdown[zone] || 0) + 1;
                });
            } else {
                for (var zk in zoneQtys) {
                    if (zoneQtys[zk] > 0) { zoneBreakdown[zk] = zoneQtys[zk]; }
                }
            }
            var breakdownParts = [];
            for (var zn in zoneBreakdown) { breakdownParts.push(zn + ' x' + zoneBreakdown[zn]); }
            $floatCount.text(breakdownParts.length ? breakdownParts.join(' · ') : '0 entradas');
            $floatTotal.text(formatPrice(total));
            $floatBtn.prop('disabled', !hasSelection);

            // Actualizar texto del botón
            if (hasSelection) {
                $buyBtn.text('Comprar ' + count + (count === 1 ? ' entrada' : ' entradas') + ' — ' + formatPrice(total));
            } else {
                $buyBtn.text(buyText);
            }

            // Actualizar disponibilidad visual por zona
            updateAvailability();
        }

        // ── Actualizar disponibilidad visual ──

        function updateAvailability() {
            if (saleMode !== 'seat') { return; }
            // Contar asientos seleccionados por zona
            var zoneCounts = {};
            selectedSeats.forEach(function (seat) {
                var zone = getSeatZoneName(seat);
                if (zone) {
                    zoneCounts[zone] = (zoneCounts[zone] || 0) + 1;
                }
            });

            $form.find('.ticket-type__avail').each(function () {
                var $el = $(this);
                var zone = $el.data('zone');
                if (!zone) { return; }
                var $item = $form.find('.ss-ticket-form__zone-item[data-zone="' + zone + '"]');
                // Leer max original del DOM (set by PHP)
                var baseAvail = parseInt($item.attr('data-available'), 10);
                if (isNaN(baseAvail)) {
                    // Fallback: parse del texto original
                    baseAvail = parseInt($el.text(), 10) || 0;
                }
                var selected = zoneCounts[zone] || 0;
                var current = Math.max(0, baseAvail - selected);
                $el.text(current + ' disponibles');
            });
        }

        // ── Obtener zona de un asiento por su código ──

        // Resolver confiable: usa seatZoneMap (filas reales del layout, soporta
        // multi-piso) y devuelve el nombre de zona con el casing original del
        // data-zone del DOM. getZoneForSeat() (abajo) solo queda como último
        // fallback para layouts legacy sin seatZoneMap.
        function getSeatZoneName(seatId) {
            var upperZone = seatZoneMap[String(seatId).toUpperCase()];
            if (upperZone) {
                var found = '';
                $form.find('.ss-ticket-form__zone-item').each(function () {
                    if (String($(this).data('zone')).toUpperCase() === upperZone) {
                        found = $(this).data('zone');
                        return false;
                    }
                });
                if (found) { return found; }
            }
            return getZoneForSeat(seatId);
        }

        function getZoneForSeat(seatId) {
            var zone = '';
            $form.find('.ss-ticket-form__zone-item').each(function () {
                var z = $(this).data('zone');
                if (z && seatId.toUpperCase().indexOf(String(z).toUpperCase()) === 0) {
                    zone = z;
                    return false;
                }
            });
            return zone;
        }

        // ── Obtener precio de una silla por su zona ──

        function getSeatPrice(seatId) {
            var price = 0;
            var seatKey = String(seatId).toUpperCase();

            // 1) Usar el mapa construido desde ssLayoutData (más preciso)
            var mappedZone = seatZoneMap[seatKey];
            if (mappedZone) {
                $form.find('.ss-ticket-form__zone-item').each(function () {
                    if (String($(this).data('zone')).toUpperCase() === mappedZone) {
                        price = parseFloat($(this).data('price')) || 0;
                        return false;
                    }
                });
                return price;
            }

            // 2) Si solo hay una zona, usar su precio directamente
            var $zoneItems = $form.find('.ss-ticket-form__zone-item');
            if ($zoneItems.length === 1) {
                return parseFloat($zoneItems.first().data('price')) || 0;
            }

            // 3) Fallback: coincidencia por prefijo (modo legacy)
            $zoneItems.each(function () {
                var zone = $(this).data('zone');
                if (zone && seatKey.indexOf(String(zone).toUpperCase()) === 0) {
                    price = parseFloat($(this).data('price')) || 0;
                    return false;
                }
            });
            return price;
        }

        // ── Toast de notificación ──

        function showToast(message) {
            var $toast = $('<div class="ss-ticket-toast">' + escHtml(message) + '</div>');
            $toast.css({
                position: 'fixed', top: '20px', left: '50%', transform: 'translateX(-50%)',
                background: '#c62828', color: '#fff', padding: '12px 24px',
                borderRadius: '8px', fontSize: '14px', fontWeight: '500',
                zIndex: 99999, boxShadow: '0 4px 12px rgba(0,0,0,0.3)',
                opacity: 0, transition: 'opacity 0.3s'
            });
            $('body').append($toast);
            setTimeout(function () { $toast.css('opacity', 1); }, 10);
            setTimeout(function () {
                $toast.css('opacity', 0);
                setTimeout(function () { $toast.remove(); }, 300);
            }, 4500);
        }

        // ── Botón comprar: guardar sillas en sesión WC → POST add-to-cart ──

        // ── Handlers comunes para done/fail ──

        function onCartSuccess(response) {
            if (response && response.success && response.data && response.data.cart_url) {
                window.location.href = response.data.cart_url;
            } else {
                var msg = (response && response.data && response.data.message)
                        ? response.data.message
                        : 'Error al añadir al carrito.';
                alert(msg);
                isSubmitting = false;
                $buyBtn.prop('disabled', false).text(buyText);
            }
        }

        function onCartFail(xhr) {
            if (xhr === 'rejected_seats') { return; }
            var msg = 'Error de conexión.';
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp && resp.data && resp.data.message) { msg = resp.data.message; }
            } catch (e) {}
            alert(msg);
            isSubmitting = false;
            $buyBtn.prop('disabled', false).text(buyText);
            updateSteps(1);
        }

        $buyBtn.on('click', function () {
            if (isSubmitting) { return; }
            updateSteps(2);

            var productId = (typeof ssTicketFormExtra !== 'undefined' && ssTicketFormExtra.productId)
                          ? ssTicketFormExtra.productId
                          : config.eventId;

            // ── Modo zone/general: enviar cantidades por zona ──
            if (saleMode !== 'seat') {
                var hasQty = false;
                for (var k in zoneQtys) { if (zoneQtys[k] > 0) { hasQty = true; break; } }

                // En modo hybrid también puede haber seats seleccionados
                if (!hasQty && selectedSeats.length === 0) { return; }

                isSubmitting = true;
                $buyBtn.prop('disabled', true).text('Procesando...');

                // Si hay seats seleccionados (hybrid), usar flujo de seats
                if (selectedSeats.length > 0) {
                    // Flujo seat (reutilizar para hybrid con sillas)
                    doSeatPurchase(productId);
                    return;
                }

                // Flujo zone puro: enviar zone_qtys
                $.ajax({
                    url:    config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:     'ss_add_to_cart',
                        nonce:      config.nonce,
                        product_id: productId,
                        event_id:   config.eventId,
                        sale_mode:  'zone',
                        zone_qtys:  JSON.stringify(zoneQtys)
                    }
                }).done(onCartSuccess).fail(onCartFail);
                return;
            }

            // ── Modo seat: flujo existente ──
            if (selectedSeats.length === 0) { return; }

            isSubmitting = true;
            $buyBtn.prop('disabled', true).text('Procesando...');
            doSeatPurchase(productId);
        });

        function doSeatPurchase(productId) {
            $.ajax({
                url:    config.ajaxUrl,
                method: 'POST',
                data: {
                    action:   'ss_save_seats',
                    nonce:    config.nonce,
                    seats:    selectedSeats.join(','),
                    event_id: config.eventId
                }
            }).then(function (saveResponse) {
                var rejected = (saveResponse && saveResponse.data && saveResponse.data.rejected) || [];
                if (rejected.length > 0) {
                    var msg = rejected.length === 1
                        ? 'La silla ' + rejected[0] + ' acaba de ser tomada por otro usuario.'
                        : 'Las sillas ' + rejected.join(', ') + ' acaban de ser tomadas por otro usuario.';
                    showToast(msg);

                    var acceptedRaw = (saveResponse.data && saveResponse.data.seats) || '';
                    selectedSeats = acceptedRaw ? acceptedRaw.split(/\s*,\s*/).filter(Boolean) : [];
                    lastSeatsVal = acceptedRaw;
                    $seatsInput.val(acceptedRaw);
                    $seatsSelected.val(acceptedRaw);
                    updateSummary();

                    isSubmitting = false;
                    $buyBtn.prop('disabled', selectedSeats.length === 0);
                    if (selectedSeats.length > 0) {
                        updateSummary();
                    } else {
                        $buyBtn.text(buyText);
                        updateSteps(0);
                    }
                    return $.Deferred().reject('rejected_seats');
                }

                return $.ajax({
                    url:    config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:     'ss_add_to_cart',
                        nonce:      config.nonce,
                        product_id: productId,
                        event_id:   config.eventId,
                        seats:      selectedSeats.join(',')
                    }
                });
            }).done(onCartSuccess).fail(onCartFail);
        }

        // ── Helpers ──

        function formatPrice(amount) {
            return '$' + amount.toLocaleString('es-CO', { minimumFractionDigits: 0 });
        }

        function escHtml(str) {
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

    }); // end $(document).ready

})(jQuery);
