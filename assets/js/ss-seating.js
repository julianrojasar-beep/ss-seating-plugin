// ss-seating: manejo de selección de sillas
(function () {

    // ─────────────────────────────────────────────────────────────────────
    // RENDER DINÁMICO DESDE LAYOUT JSON
    //
    // Si window.ssLayoutData existe y el contenedor #ss-seating-container
    // está presente, genera los botones .ss-seat dinámicamente en lugar
    // de depender del grid PHP.
    //
    // Formato esperado de layout:
    //   { rows: [ { type: "seats", label: "A", zone: "VIP",
    //               count: 10, removedSeats: [3,7],
    //               gaps: [{ after: 5, size: 2 }] },
    //             { type: "empty", height: 40 }, ... ] }
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Genera los botones de sillas dentro de `container` según el `layout` JSON.
     *
     * @param {Object}      layout    - Objeto con propiedad `rows` (array).
     * @param {HTMLElement}  container - Elemento donde insertar el HTML generado.
     */
    function renderSeatingFromLayout(layout, container) {
        if (!layout || !Array.isArray(layout.rows) || !container) {
            return;
        }

        var spacing = parseInt(layout.spacing, 10) || 10;

        // Header
        var header = document.createElement('div');
        header.className = 'ss-seating__header';
        header.innerHTML =
            '<h3 class="ss-seating__title">Selecciona tu silla</h3>';
        container.appendChild(header);

        // Contenedor de filas
        var wrap = document.createElement('div');
        wrap.className = 'ss-seating__rows';
        container.appendChild(wrap);

        // Recorrer filas
        layout.rows.forEach(function (row) {
            // Filas vacías: separador con altura dinámica
            if (row.type === 'empty') {
                var emptyHeight = parseInt(row.height, 10) || 20;
                var separator = document.createElement('div');
                separator.style.cssText = 'height: ' + emptyHeight + 'px;';
                wrap.appendChild(separator);
                return;
            }

            if (row.type && row.type !== 'seats') { return; }

            var label   = row.label || '?';
            var zone    = row.zone  || 'GENERAL';
            var count   = parseInt(row.count, 10) || 0;
            var removed = Array.isArray(row.removedSeats) ? row.removedSeats : [];
            var gaps    = Array.isArray(row.gaps) ? row.gaps : [];

            // Set de sillas eliminadas para O(1)
            var removedSet = {};
            removed.forEach(function (n) { removedSet[n] = true; });

            // Mapa de gaps: after → size (gap se inserta DESPUÉS de la silla `after`)
            var gapMap = {};
            gaps.forEach(function (g) {
                gapMap[g.after] = parseInt(g.size, 10) || 1;
            });

            // Crear fila flex
            var rowDiv = document.createElement('div');
            rowDiv.className = 'ss-row';
            rowDiv.style.cssText = 'display:flex; align-items:center; margin-bottom:12px;';
            wrap.appendChild(rowDiv);

            for (var i = 1; i <= count; i++) {
                // Silla eliminada: no emitir botón
                if (removedSet[i]) {
                    // Aun así verificar si hay gap después de esta silla eliminada
                    if (gapMap[i]) {
                        var gapAfterRemoved = document.createElement('span');
                        gapAfterRemoved.className = 'ss-gap';
                        gapAfterRemoved.style.cssText = 'display:inline-block; width:' + (gapMap[i] * spacing) + 'px;';
                        rowDiv.appendChild(gapAfterRemoved);
                    }
                    continue;
                }

                var seatCode = label + i;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ss-seat';
                btn.style.marginRight = spacing + 'px';
                btn.setAttribute('data-ticket-type', zone);
                btn.setAttribute('aria-label', 'Silla ' + seatCode);
                btn.textContent = seatCode;
                rowDiv.appendChild(btn);

                // Insertar gap DESPUÉS de esta silla si corresponde
                if (gapMap[i]) {
                    var gapSpan = document.createElement('span');
                    gapSpan.className = 'ss-gap';
                    gapSpan.style.cssText = 'display:inline-block; width:' + (gapMap[i] * spacing) + 'px;';
                    rowDiv.appendChild(gapSpan);
                }
            }
        });

        console.log('[ss-seating] Layout renderizado dinámicamente:', layout.rows.length, 'filas');
    }

    document.addEventListener('DOMContentLoaded', function () {

        // ── Render dinámico si hay layout JSON disponible ──
        if (typeof window.ssLayoutData !== 'undefined' && window.ssLayoutData) {
            var layoutContainer = document.getElementById('ss-seating-container');
            if (layoutContainer) {
                renderSeatingFromLayout(window.ssLayoutData, layoutContainer);
            }
        }

        var rootLists = document.querySelectorAll('.ss-seating');
        if (!rootLists.length) {
            return;
        }

        // ─────────────────────────────────────────────────────────────────────
        // DETECCIÓN DINÁMICA DE TICKET TYPES
        //
        // buildTicketMap() escanea en tiempo real todos los .mep_ticket_item
        // del formulario del plugin. Por cada uno lee el nombre real del ticket
        // desde input[name="option_name[]"] — la misma fuente que usa el plugin
        // internamente. No existen claves por defecto ni fallbacks: si un tipo
        // no está en el DOM de este evento, no existe en el mapa.
        // ─────────────────────────────────────────────────────────────────────

        /**
         * Extrae el nombre del ticket asociado a un .qtyIncDec.
         *
         * Fuente canónica (mpwem_script.js, mpwem_attendee_management):
         *   current_parent.find('[name="option_name[]"]').val()
         *   donde current_parent = $(input).closest('.mep_ticket_item')
         *
         * Cada .mep_ticket_item contiene un hidden input[name="option_name[]"]
         * con el nombre exacto que el plugin usa internamente ("VIP", "GENERAL"…).
         */
        function resolveTicketLabel(qtyWrapper) {
            // 1. Fuente canónica del plugin
            var ticketItem = qtyWrapper.closest('.mep_ticket_item');
            if (ticketItem) {
                var nameInput = ticketItem.querySelector('input[name="option_name[]"]');
                if (nameInput && nameInput.value.trim()) {
                    return nameInput.value.trim().toUpperCase();
                }
            }

            // 2. Fallback: atributo data-* en el wrapper o sus padres inmediatos
            var candidates = [qtyWrapper, qtyWrapper.parentElement,
                              qtyWrapper.parentElement && qtyWrapper.parentElement.parentElement]
                             .filter(Boolean);
            for (var i = 0; i < candidates.length; i++) {
                var attr = candidates[i].getAttribute('data-ticket-name') ||
                           candidates[i].getAttribute('data-name')        ||
                           candidates[i].getAttribute('data-ticket-type') ||
                           candidates[i].getAttribute('data-type');
                if (attr && attr.trim()) {
                    return attr.trim().toUpperCase();
                }
            }

            return null; // sin nombre resoluble → el wrapper se descarta
        }

        /**
         * Escanea el DOM en tiempo real y construye el mapa ticketLabel → controles.
         * Solo incluye tipos que existen en el formulario del evento actual.
         * Sin valores por defecto. Sin fallbacks. Sin claves hardcodeadas.
         */
        function buildTicketMap() {
            var map = {};
            var allWrappers = document.querySelectorAll('.qtyIncDec');

            allWrappers.forEach(function (wrapper) {
                var input = wrapper.querySelector('input.inputIncDec[name="option_qty[]"]') ||
                            wrapper.querySelector('input.inputIncDec') ||
                            wrapper.querySelector('input[name^="option_qty"]');
                var plus  = wrapper.querySelector('.incQty');
                var minus = wrapper.querySelector('.decQty');

                if (!input || !plus || !minus) {
                    return; // wrapper incompleto, ignorar
                }

                // ── Filtro de tickets activos ─────────────────────────────
                // 1. Input deshabilitado: el plugin marca así los tickets
                //    agotados o fuera de fecha (atributo disabled).
                if (input.disabled) { return; }

                // 2. Wrapper o ticket item con clase de bloqueo.
                //    mpwem_script.js usa .mpDisabled en los botones +/-;
                //    cuando AMBOS están deshabilitados el ticket está bloqueado.
                var ticketItem = wrapper.closest('.mep_ticket_item');
                var bothBtnsDisabled = plus.classList.contains('mpDisabled') &&
                                       minus.classList.contains('mpDisabled');
                var itemLocked = ticketItem &&
                                 (ticketItem.classList.contains('mpDisabled')    ||
                                  ticketItem.classList.contains('mep_disabled')  ||
                                  ticketItem.classList.contains('ticket-locked') ||
                                  ticketItem.classList.contains('sold-out')      ||
                                  ticketItem.classList.contains('mep_sold_out'));
                if (bothBtnsDisabled || itemLocked) { return; }

                // 3. Ticket oculto: offsetParent === null significa que el
                //    elemento o algún ancestro tiene display:none.
                //    Excepción: inputs type=hidden tienen offsetParent nulo
                //    por diseño, por eso comprobamos el wrapper, no el input.
                if (wrapper.offsetParent === null) { return; }
                // ─────────────────────────────────────────────────────────

                var label = resolveTicketLabel(wrapper);

                // Si no se pudo determinar un nombre real, descartar el wrapper.
                if (!label) { return; }

                // Si ya existe la clave (nombres duplicados), agregar sufijo
                if (map[label]) {
                    var suffix = 2;
                    while (map[label + '_' + suffix]) { suffix++; }
                    label = label + '_' + suffix;
                }

                map[label] = { input: input, plus: plus, minus: minus };
            });

            // Mapa construido exclusivamente desde el DOM real del evento.
            // Sin fallbacks ni claves hardcodeadas.
            console.log('[ss-seating] Mapa de ticket types detectado:', Object.keys(map).join(', ') || '(ninguno en el DOM)');
            return map;
        }

        // ─────────────────────────────────────────────────────────────────────
        // SINCRONIZACIÓN DE CANTIDADES POR TIPO
        // ─────────────────────────────────────────────────────────────────────

        /**
         * Ajusta la cantidad de un input hacia `desired` usando el ciclo de
         * eventos exacto que dispara el plugin en mpwem_script.js:
         *
         *   target.val(newValue);
         *   target.trigger('change').trigger('input');
         *
         * El listener del plugin es:
         *   $(document).on('change', '.mpwem_registration_area [name="option_qty[]"]', …)
         *   → llama mpwem_price_calculation(parent)
         *
         * Nunca simulamos clics en +/-: el plugin valida min/max al hacer clic
         * y esos controles son para interacción humana. Aquí escribimos el valor
         * directamente y notificamos al plugin con los mismos triggers que él
         * mismo usa internamente.
         */
        function syncOneControl(controls, desired) {
            if (!controls || !controls.input) {
                return;
            }

            // Respetar los límites declarados en el input (min / max)
            var min = parseInt(controls.input.getAttribute('min'), 10);
            var max = parseInt(controls.input.getAttribute('max'), 10);
            if (!isNaN(min)) { desired = Math.max(desired, min); }
            if (!isNaN(max)) { desired = Math.min(desired, max); }

            // Bloquea edición manual del input mientras el seating controla la cantidad
            if (desired > 0) {
                controls.input.setAttribute('readonly', 'readonly');
            } else {
                controls.input.removeAttribute('readonly');
            }

            // Escribir valor y disparar exactamente los mismos eventos que el plugin:
            //   target.trigger('change').trigger('input')
            if (window.jQuery) {
                jQuery(controls.input).val(desired).trigger('change').trigger('input');
            } else {
                // Sin jQuery: fallback con eventos nativos
                controls.input.value = desired;
                controls.input.dispatchEvent(new Event('change', { bubbles: true }));
                controls.input.dispatchEvent(new Event('input',  { bubbles: true }));
            }
        }

        /**
         * Dado el mapa de sillas seleccionadas por tipo y el mapa de controles,
         * sincroniza cada tipo conocido y pone a 0 los que ya no tienen sillas.
         */
        function syncAllQuantities(selectedByType, ticketMap) {
            if (!Object.keys(ticketMap).length) {
                console.log('[ss-seating] Sin controles de cantidad disponibles.');
                return;
            }

            // Recorrer todos los tipos del mapa de controles
            Object.keys(ticketMap).forEach(function (label) {
                var count = selectedByType[label] ? selectedByType[label].length : 0;
                syncOneControl(ticketMap[label], count);
                console.log('[ss-seating] Tipo "' + label + '" → cantidad: ' + count);
            });

            // Advertir sobre tipos seleccionados sin control mapeado
            Object.keys(selectedByType).forEach(function (type) {
                if (!ticketMap[type] && selectedByType[type].length) {
                    console.warn('[ss-seating] Tipo de silla "' + type + '" no tiene control de cantidad en el formulario.');
                }
            });
        }

        // ─────────────────────────────────────────────────────────────────────
        // INICIALIZACIÓN POR INSTANCIA DE SEATING
        // ─────────────────────────────────────────────────────────────────────

        /**
         * Hybrid mode: sync qty to match seat selection.
         * Always sets qty = selectedCount. User can increase via +/- afterwards.
         * Input is NOT locked (readonly) so user retains manual control.
         */
        function enforceMinQuantities(selectedByType, ticketMap) {
            Object.keys(ticketMap).forEach(function (label) {
                var selectedCount = selectedByType[label] ? selectedByType[label].length : 0;
                var controls = ticketMap[label];

                // Update min so user can't go below seat count
                controls.input.setAttribute('min', String(selectedCount));

                // Sync value to match seat selection
                var desired = selectedCount;
                var max = parseInt(controls.input.getAttribute('max'), 10);
                if (!isNaN(max)) { desired = Math.min(desired, max); }

                if (window.jQuery) {
                    jQuery(controls.input).val(desired).trigger('change').trigger('input');
                } else {
                    controls.input.value = desired;
                    controls.input.dispatchEvent(new Event('change', { bubbles: true }));
                    controls.input.dispatchEvent(new Event('input',  { bubbles: true }));
                }

                // Never lock input in hybrid — user can increase freely
                controls.input.removeAttribute('readonly');
            });
        }

        rootLists.forEach(function (root) {
            var saleMode = root.getAttribute('data-ss-mode') || 'seat';
            var selectedSeats  = [];
            var selectedByType = {};
            var summaryContainer = root.querySelector('.ss-seating__summary');
            var summaryList      = root.querySelector('.ss-seating__summary-list');

            if (!summaryContainer || !summaryList) {
                return;
            }

            // ── Sillas vendidas: leer desde data-ss-sold del contenedor ──────
            // PHP serializa _ss_sold_seats como JSON en el atributo data-ss-sold.
            // Usamos un Set para O(1) en las comprobaciones del click handler.
            var soldRaw = root.getAttribute('data-ss-sold');
            var soldSeats = new Set();
            try {
                var parsed = soldRaw ? JSON.parse(soldRaw) : [];
                if (Array.isArray(parsed)) {
                    parsed.forEach(function (code) { soldSeats.add(String(code).trim()); });
                }
            } catch (e) { /* JSON malformado: soldSeats queda vacío, el grid funciona normal */ }

            // Marcar visualmente las sillas vendidas en el DOM
            soldSeats.forEach(function (code) {
                root.querySelectorAll('.ss-seat').forEach(function (btn) {
                    if ((btn.textContent || '').trim() === code) {
                        btn.classList.add('ss-sold');
                        btn.setAttribute('aria-disabled', 'true');
                        btn.setAttribute('aria-label',
                            (btn.getAttribute('aria-label') || code) + ' — Ocupada');
                    }
                });
            });

            // Marcar sillas reservadas (en carrito de otro usuario — amarillo)
            var reservedRaw = root.getAttribute('data-ss-reserved');
            var reservedSeats = new Set();
            try {
                var parsedReserved = reservedRaw ? JSON.parse(reservedRaw) : [];
                if (Array.isArray(parsedReserved)) {
                    parsedReserved.forEach(function (code) { reservedSeats.add(String(code).trim()); });
                }
            } catch (e) { /* JSON malformado */ }

            reservedSeats.forEach(function (code) {
                root.querySelectorAll('.ss-seat').forEach(function (btn) {
                    if ((btn.textContent || '').trim() === code) {
                        btn.classList.add('ss-reserved');
                        btn.setAttribute('aria-disabled', 'true');
                        btn.setAttribute('title', 'En proceso de compra');
                        btn.setAttribute('aria-label',
                            (btn.getAttribute('aria-label') || code) + ' — En proceso de compra');
                    }
                });
            });
            // ─────────────────────────────────────────────────────────────────

            // ticketMap NO se precarga: el plugin re-renderiza los controles
            // dinámicamente, así que se reconstruye en cada clic (ver abajo).

            // Buscar el form para el hidden input de sillas
            var cartForm = (function () {
                var firstInput = document.querySelector('.qtyIncDec input');
                return firstInput ? firstInput.closest('form') : null;
            }());

            // ── Feedback de límite alcanzado ──────────────────────────────
            var limitMsg = null;
            function showLimitFeedback(zone) {
                if (!limitMsg) {
                    limitMsg = document.createElement('div');
                    limitMsg.className = 'ss-seating__limit-msg';
                    limitMsg.style.cssText = 'background:#fff3e0;color:#e65100;border:1px solid #ffb74d;border-radius:6px;padding:8px 14px;margin:8px 0;text-align:center;font-size:13px;font-weight:600;display:none;';
                    root.insertBefore(limitMsg, root.firstChild);
                }
                limitMsg.textContent = 'Has alcanzado el límite de entradas disponibles para la zona ' + zone + '.';
                limitMsg.style.display = 'block';
                clearTimeout(limitMsg._timer);
                limitMsg._timer = setTimeout(function () { limitMsg.style.display = 'none'; }, 3000);
            }

            function normalizeZoneKey(zone) {
                return String(zone || '').trim().toUpperCase().replace(/_\d+$/, '');
            }
            function getAvailableForZone(zone, ticketMap) {
                var key = normalizeZoneKey(zone);
                var controls = ticketMap[key] || ticketMap[zone];
                if (!controls || !controls.input) return Infinity;
                var max = parseInt(controls.input.getAttribute('max'), 10);
                return isNaN(max) ? Infinity : max;
            }
            function getCurrentQtyForZone(zone, ticketMap) {
                var key = normalizeZoneKey(zone);
                var controls = ticketMap[key] || ticketMap[zone];
                if (!controls || !controls.input) return 0;
                var qty = parseInt(controls.input.value, 10);
                return isNaN(qty) ? 0 : qty;
            }
            function clampHybridQuantities(ticketMap) {
                if (saleMode !== 'hybrid') return;
                Object.keys(ticketMap).forEach(function (label) {
                    var controls = ticketMap[label];
                    if (!controls || !controls.input) return;
                    var available = getAvailableForZone(label, ticketMap);
                    var selectedCount = selectedByType[label] ? selectedByType[label].length : 0;
                    var val = parseInt(controls.input.value, 10);
                    if (isNaN(val)) val = 0;
                    val = Math.max(val, selectedCount);
                    if (available !== Infinity) val = Math.min(val, available);
                    if (String(val) !== String(controls.input.value)) {
                        if (window.jQuery) {
                            jQuery(controls.input).val(val).trigger('change').trigger('input');
                        } else {
                            controls.input.value = val;
                            controls.input.dispatchEvent(new Event('change', { bubbles: true }));
                            controls.input.dispatchEvent(new Event('input',  { bubbles: true }));
                        }
                    }
                });
            }

            // ── Manejador de clic en sillas ───────────────────────────────
            // ── Guardar sillas en sesión WC + reservar temporalmente ────
            function saveSeatsToSession(seats) {
                if (!window.ssSeatingAjax || !window.ssSeatingAjax.url) return;
                var data = new FormData();
                data.append('action', 'ss_save_seats');
                data.append('nonce',  window.ssSeatingAjax.nonce);
                data.append('seats',  seats.join(', '));
                if (window.ssSeatingAjax.eventId) {
                    data.append('event_id', window.ssSeatingAjax.eventId);
                }
                fetch(window.ssSeatingAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
                    .catch(function () { /* silent */ });
            }

            root.addEventListener('click', function (event) {
                // Zone mode: seats are view-only
                if (saleMode === 'zone') { return; }

                var seat = event.target.closest('.ss-seat');
                if (!seat || !root.contains(seat)) {
                    return;
                }

                var code = (seat.textContent || '').trim();
                // Silla vendida o reservada por otro: ignorar el click
                if (soldSeats.has(code) || reservedSeats.has(code)) { return; }
                // data-ticket-type puesto por PHP; coincide con las claves del ticketMap
                var type = (seat.getAttribute('data-ticket-type') || '').trim().toUpperCase();
                // Si la silla no tiene data-ticket-type, no la procesamos:
                // no existe un ticket real al que mapearla.
                if (!type) { return; }
                if (!code) {
                    return;
                }

                var index = selectedSeats.indexOf(code);
                if (index === -1) {
                    // ── Verificar límite antes de seleccionar ──────────────
                    var ticketMapPreCheck = buildTicketMap();
                    var available = getAvailableForZone(type, ticketMapPreCheck);
                    var currentTotal = (saleMode === 'hybrid')
                        ? getCurrentQtyForZone(type, ticketMapPreCheck)
                        : (selectedByType[type] ? selectedByType[type].length : 0);
                    if (currentTotal >= available) {
                        showLimitFeedback(type);
                        return; // NO seleccionar
                    }

                    // Seleccionar
                    selectedSeats.push(code);
                    if (!selectedByType[type]) { selectedByType[type] = []; }
                    selectedByType[type].push(code);
                    seat.classList.add('ss-selected');
                } else {
                    // Deseleccionar — siempre permitido
                    selectedSeats.splice(index, 1);
                    if (selectedByType[type]) {
                        var ti = selectedByType[type].indexOf(code);
                        if (ti !== -1) { selectedByType[type].splice(ti, 1); }
                        if (!selectedByType[type].length) { delete selectedByType[type]; }
                    }
                    seat.classList.remove('ss-selected');
                }

                // Actualizar resumen visible
                if (selectedSeats.length > 0) {
                    summaryContainer.style.display = '';
                    summaryList.textContent = selectedSeats.join(', ');
                } else {
                    summaryContainer.style.display = 'none';
                    summaryList.textContent = '';
                }

                console.log('[ss-seating] Selección por tipo', selectedByType);

                // Reconstruir el mapa leyendo el DOM actual en este momento.
                var ticketMap = buildTicketMap();
                if (saleMode === 'seat') {
                    syncAllQuantities(selectedByType, ticketMap);
                } else if (saleMode === 'hybrid') {
                    enforceMinQuantities(selectedByType, ticketMap);
                    clampHybridQuantities(ticketMap);
                }

                // Reserva temporal inmediata (hybrid): guardar en sesión + reservar en backend
                if (saleMode === 'hybrid') {
                    saveSeatsToSession(selectedSeats);
                }
            });

            // ── Hidden input con sillas para el carrito ───────────────────
            if (cartForm) {
                var addToCartButtons = cartForm.querySelectorAll('button[name="add-to-cart"], input[name="add-to-cart"]');
                if (addToCartButtons.length) {
                    var hiddenInput = cartForm.querySelector('input[name="ss_seats"]');
                    if (!hiddenInput) {
                        hiddenInput = document.createElement('input');
                        hiddenInput.type  = 'hidden';
                        hiddenInput.name  = 'ss_seats';
                        cartForm.appendChild(hiddenInput);
                    }
                    addToCartButtons.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            hiddenInput.value = selectedSeats.join(', ');
                        });
                    });
                }
            }

            document.addEventListener('click', function (ev) {
                if (saleMode !== 'hybrid') return;
                var btn = ev.target.closest('.qtyIncDec .incQty, .qtyIncDec .decQty');
                if (!btn) return;
                setTimeout(function () { clampHybridQuantities(buildTicketMap()); }, 0);
            });
            document.addEventListener('change', function (ev) {
                if (saleMode !== 'hybrid') return;
                var input = ev.target;
                if (!input || !input.closest || !input.closest('.qtyIncDec')) return;
                clampHybridQuantities(buildTicketMap());
            });
        }); // fin rootLists.forEach
    }); // fin DOMContentLoaded
})();
