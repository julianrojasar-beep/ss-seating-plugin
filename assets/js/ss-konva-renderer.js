// ═══════════════════════════════════════════════════════════════════
//  SS Konva Renderer — Frontend seat map with sales integration
//  Visually identical to the builder (main.js).
//  Depends on: konva.min.js, seat-engine.js (window.SeatEngine)
//  Data from PHP: window.ssLayoutData, window.ssSeatingState
// ═══════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  // ─── Constants (must match builder) ──────────────────────────────
  var SEAT_RADIUS = 15;
  var ZOOM_MIN    = 0.3;
  var ZOOM_MAX    = 3;
  var ZOOM_STEP   = 1.08;

  // ─── Selection colors (from SS_Settings, with fallback defaults) ─
  var _fc = (window.ssSeatingAjax && window.ssSeatingAjax.colors) || {};
  var COLOR_SELECTED    = '#0073aa';
  var COLOR_SOLD_FILL   = _fc.sold     || '#e0e0e0';
  var COLOR_SOLD_STROKE = _fc.sold     || '#ccc';
  var COLOR_SOLD_TEXT   = '#aaa';
  var COLOR_RESV_FILL   = _fc.reserved || '#fff3cd';
  var COLOR_RESV_STROKE = _fc.reserved || '#ffc107';
  var COLOR_RESV_TEXT   = '#856404';

  // ─── Selection state (global within this IIFE) ───────────────────
  var selectedSeats  = [];   // flat list of seat codes, e.g. ["A1","B3"]
  var selectedByType = {};   // { "VIP": ["A1"], "GENERAL": ["B3"] }

  // ─── Wait for DOM ────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', init);

  // ─── Floor state ────────────────────────────────────────────────
  var _rawLayout    = null;
  var _activeFloor  = 0;
  var _currentStage = null;

  function _renderFrontendFloorTabs(container) {
    var tabsEl = document.getElementById('ss-floor-tabs-frontend');
    if (!tabsEl || !_rawLayout || !_rawLayout.floors || _rawLayout.floors.length < 2) return;
    tabsEl.innerHTML = '';
    for (var i = 0; i < _rawLayout.floors.length; i++) {
      (function(idx) {
        var isActive = idx === _activeFloor;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = _rawLayout.floors[idx].label || ('Piso ' + (idx + 1));
        btn.style.cssText = 'padding:4px 14px;font-size:13px;font-weight:600;border-radius:4px;cursor:pointer;margin-right:4px;border:1px solid ' + (isActive ? '#2271b1' : '#c3c4c7') + ';background:' + (isActive ? '#2271b1' : '#f0f0f0') + ';color:' + (isActive ? '#fff' : '#333') + ';';
        btn.addEventListener('click', function() {
          if (_activeFloor === idx) return;
          _activeFloor = idx;
          selectedSeats  = [];
          selectedByType = {};
          if (_currentStage) { _currentStage.destroy(); _currentStage = null; container.innerHTML = ''; }
          _renderFrontendFloorTabs(container);
          _buildMap(container);
        });
        tabsEl.appendChild(btn);
      })(i);
    }
  }

  function _buildMap(container) {
    var floorData = (_rawLayout.floors && _rawLayout.floors[_activeFloor]) || {};
    var floorRaw  = {
      startX:    _rawLayout.startX    || 100,
      spacing:   _rawLayout.spacing   || SEAT_RADIUS * 3,
      zones:     _rawLayout.zones     || [],
      rows:      floorData.rows      || [],
      zoneRects: floorData.zoneRects || [],
      layout:    floorData.layout    || {}
    };

    var config = normalizeConfig(floorRaw);
    var state  = window.ssSeatingState || { sold: [], reserved: [] };

    var saleMode = (window.ssSeatingAjax && window.ssSeatingAjax.saleMode)
                || (window.ssTicketForm && window.ssTicketForm.saleMode)
                || 'seat';
    if (saleMode === 'general') saleMode = 'zone';

    var seats = SeatEngine.buildSeatsFromConfig(config);
    if (!seats.length) return;

    var stage = new Konva.Stage({
      container: container,
      width:  container.clientWidth  || 800,
      height: container.clientHeight || 400,
    });
    _currentStage = stage;

    var zoneLayer = new Konva.Layer();
    var seatLayer = new Konva.Layer({ listening: true });
    stage.add(zoneLayer);
    stage.add(seatLayer);

    if (config.layout && config.layout.stage) {
      drawEscenario(zoneLayer, config.layout.stage);
    }

    var zoneColorMap = buildZoneColorMap(config.zones || []);
    var zoneRects = config.zoneRects || [];
    for (var z = 0; z < zoneRects.length; z++) {
      drawZoneRect(zoneLayer, zoneRects[z], zoneColorMap);
    }

    var rows = config.rows || [];
    for (var fl = 0; fl < rows.length; fl++) {
      if (rows[fl].type !== 'floor-label') continue;
      var flRow = rows[fl];
      var flGroup = new Konva.Group({ x: config.startX || 100, y: flRow.y - 14 });
      flGroup.add(new Konva.Rect({ x: 0, y: 0, width: 500, height: 22, fill: '#1e293b', opacity: 0.75, cornerRadius: 4 }));
      flGroup.add(new Konva.Text({ x: 8, y: 4, text: flRow.text || 'PISO', fontSize: 13, fontStyle: 'bold', fill: '#fbbf24', fontFamily: 'sans-serif' }));
      zoneLayer.add(flGroup);
    }
    zoneLayer.batchDraw();

    var soldSet     = arrayToSet(state.sold);
    var reservedSet = arrayToSet(state.reserved);
    var seatNodes   = {};
    var seatsGroup  = new Konva.Group();

    for (var i = 0; i < seats.length; i++) {
      var seatData = seats[i];
      var node = drawSeat(seatsGroup, seatData, zoneColorMap, soldSet, reservedSet, saleMode);
      seatNodes[seatData.id] = node;
    }
    seatLayer.add(seatsGroup);
    seatLayer.batchDraw();

    _bindMapInteraction(container, stage, seatLayer, seatNodes, config, zoneColorMap, soldSet, reservedSet, saleMode);
  }

  function init() {
    if (typeof Konva === 'undefined' || typeof SeatEngine === 'undefined') return;
    _rawLayout = window.ssLayoutData || null;
    // Normalize legacy (rows) → floors
    if (_rawLayout && !_rawLayout.floors && _rawLayout.rows) {
      _rawLayout.floors = [{
        id: 'piso-1', label: 'Principal',
        rows: _rawLayout.rows, zoneRects: _rawLayout.zoneRects || [],
        layout: _rawLayout.layout || {}
      }];
    }
    if (!_rawLayout || !_rawLayout.floors || !_rawLayout.floors.length) return;

    var container = document.getElementById('ss-konva-container');
    if (!container) return;

    _renderFrontendFloorTabs(container);
    _buildMap(container);
  }

  function _bindMapInteraction(container, stage, seatLayer, seatNodes, config, zoneColorMap, soldSet, reservedSet, saleMode) {
    var state  = window.ssSeatingState || { sold: [], reserved: [] };

    // Sale mode: seat (default), zone, hybrid
    var saleMode = (window.ssSeatingAjax && window.ssSeatingAjax.saleMode)
                || (window.ssTicketForm && window.ssTicketForm.saleMode)
                || 'seat';
    if (saleMode === 'general') saleMode = 'zone'; // PHP usa 'general', renderer usa 'zone'

    // Build seat geometry via SeatEngine
    var seats = SeatEngine.buildSeatsFromConfig(config);
    if (!seats.length) return;

    // ── Create Stage ───────────────────────────────────────────────
    var stage = new Konva.Stage({
      container: 'ss-konva-container',
      width:  container.clientWidth  || 800,
      height: container.clientHeight || 400,
    });

    var zoneLayer = new Konva.Layer();
    var seatLayer = new Konva.Layer({ listening: true });
    stage.add(zoneLayer);
    stage.add(seatLayer);

    // ── Draw escenario ─────────────────────────────────────────────
    if (config.layout && config.layout.stage) {
      drawEscenario(zoneLayer, config.layout.stage);
    }

    // ── Draw zone rects (subtle, non-interactive) ──────────────────
    var zoneColorMap = buildZoneColorMap(config.zones || []);
    var zoneRects = config.zoneRects || [];
    for (var z = 0; z < zoneRects.length; z++) {
      drawZoneRect(zoneLayer, zoneRects[z], zoneColorMap);
    }

    // ── Draw floor-label banners ────────────────────────────────────
    var rows = config.rows || [];
    for (var fl = 0; fl < rows.length; fl++) {
      if (rows[fl].type !== 'floor-label') continue;
      var flRow = rows[fl];
      var flGroup = new Konva.Group({ x: config.startX || 100, y: flRow.y - 14 });
      flGroup.add(new Konva.Rect({
        x: 0, y: 0, width: 500, height: 22,
        fill: '#1e293b', opacity: 0.75, cornerRadius: 4
      }));
      flGroup.add(new Konva.Text({
        x: 8, y: 4,
        text: flRow.text || 'PISO',
        fontSize: 13, fontStyle: 'bold',
        fill: '#fbbf24', fontFamily: 'sans-serif'
      }));
      zoneLayer.add(flGroup);
    }

    zoneLayer.batchDraw();

    // ── Draw seats (Circle + Text label) — now interactive ─────────
    var soldSet     = arrayToSet(state.sold);
    var reservedSet = arrayToSet(state.reserved);

    // seatNodes: { "A1": { circle, label, zone, baseColor } }
    var seatNodes = {};
    var seatsGroup = new Konva.Group();

    for (var i = 0; i < seats.length; i++) {
      var seatData = seats[i];
      var node = drawSeat(seatsGroup, seatData, zoneColorMap, soldSet, reservedSet, saleMode);
      seatNodes[seatData.id] = node;
    }
    seatLayer.add(seatsGroup);
    seatLayer.batchDraw();

    // ── DOM references for summary + hidden input ──────────────────
    var root             = container.closest('.ss-seating');
    var summaryContainer = root ? root.querySelector('.ss-seating__summary') : null;
    var summaryList      = root ? root.querySelector('.ss-seating__summary-list') : null;
    var hiddenInput      = document.getElementById('ss_seats_input');

    // ── Feedback de límite alcanzado ──────────────────────────────
    var limitMsg = null;
    function showLimitFeedback(zone) {
      if (!limitMsg && root) {
        limitMsg = document.createElement('div');
        limitMsg.className = 'ss-seating__limit-msg';
        limitMsg.style.cssText = 'background:#fff3e0;color:#e65100;border:1px solid #ffb74d;border-radius:6px;padding:8px 14px;margin:8px 0;text-align:center;font-size:13px;font-weight:600;display:none;';
        root.insertBefore(limitMsg, root.firstChild);
      }
      if (limitMsg) {
        limitMsg.textContent = 'Has alcanzado el límite de entradas disponibles para la zona ' + zone + '.';
        limitMsg.style.display = 'block';
        clearTimeout(limitMsg._timer);
        limitMsg._timer = setTimeout(function () { limitMsg.style.display = 'none'; }, 3000);
      }
    }

    function normalizeZoneKey(zone) {
      return String(zone || '').trim().toUpperCase().replace(/_\d+$/, '');
    }

    function getAvailableFromTicketDom(zone) {
      var target = normalizeZoneKey(zone);
      var items = document.querySelectorAll('.mep_ticket_item');
      for (var i = 0; i < items.length; i++) {
        var nameInput = items[i].querySelector('input[name="option_name[]"]');
        var name = nameInput ? normalizeZoneKey(nameInput.value) : '';
        if (name !== target) continue;
        var rem = items[i].querySelector('.ticket-remaining');
        if (!rem) continue;
        var m = (rem.textContent || '').match(/(\d+)/);
        if (m) {
          var v = parseInt(m[1], 10);
          if (!isNaN(v)) return v;
        }
      }
      return null;
    }

    function getAvailableForZone(zone, ticketMap) {
      var key = normalizeZoneKey(zone);
      var domAvail = getAvailableFromTicketDom(key);
      if (domAvail !== null) return domAvail;

      if (window.ssZoneInventory) {
        var inv = window.ssZoneInventory[key] || window.ssZoneInventory[String(key).toUpperCase()];
        if (inv && typeof inv.available !== 'undefined') {
          var avail = parseInt(inv.available, 10);
          if (!isNaN(avail)) return avail;
        }
      }
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

    // ── Click handler on seatLayer (delegation) ────────────────────
    seatLayer.on('click tap', function (e) {
      // Zone mode: no interaction at all
      if (saleMode === 'zone') return;

      var target = e.target;

      // Find which seat was clicked (circle or its label text)
      var seatId = target.getAttr('seatId');
      if (!seatId) return;

      // Ignore sold / reserved
      if (soldSet[seatId] || reservedSet[seatId]) return;

      var node = seatNodes[seatId];
      if (!node) return;

      // ── Verificar límite antes de seleccionar ──────────────────
      var isSelecting = selectedSeats.indexOf(seatId) === -1;
      if (isSelecting) {
        var zone = node.zone;
        var ticketMapCheck = buildTicketMap();
        var available = getAvailableForZone(zone, ticketMapCheck);
        var currentTotal = (saleMode === 'hybrid')
          ? getCurrentQtyForZone(zone, ticketMapCheck)
          : (selectedByType[zone] ? selectedByType[zone].length : 0);
        if (currentTotal >= available) {
          showLimitFeedback(zone);
          return; // NO seleccionar
        }
      }

      toggleSeat(seatId, node);

      // Sync differs by mode
      if (saleMode === 'seat') {
        syncSeatSelection();
      } else if (saleMode === 'hybrid') {
        enforceMinQuantities();
        clampHybridQuantities();
      }

      updateSummary(summaryContainer, summaryList);
      updateHiddenInput(hiddenInput);
      saveSeatsToSession();
      seatLayer.batchDraw();
    });

    // ── Ensure ss_seats hidden input is inside form.cart ────────────
    // PHP injects <input name="ss_seats" id="ss_seats_input"> via
    // woocommerce_before_add_to_cart_button, but if it's missing we
    // create it inside the form so it's submitted with add-to-cart.
    var cartForm = (function () {
      var firstInput = document.querySelector('.qtyIncDec input');
      return firstInput ? firstInput.closest('form') : null;
    })();

    if (cartForm && !hiddenInput) {
      hiddenInput = cartForm.querySelector('input[name="ss_seats"]');
    }
    if (cartForm && !hiddenInput) {
      hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'ss_seats';
      hiddenInput.id   = 'ss_seats_input';
      cartForm.appendChild(hiddenInput);
    }

    // ── Protect hidden input on add-to-cart + form submit ────────
    // ss-seating.js registers a click listener on add-to-cart buttons
    // that writes its own (empty) selectedSeats. Since ss-konva-renderer
    // is enqueued AFTER ss-seating, our listener registers later and
    // fires later in the same bubble phase, overwriting with the real value.
    if (cartForm) {
      var addToCartBtns = cartForm.querySelectorAll(
        'button[name="add-to-cart"], input[name="add-to-cart"]'
      );
      addToCartBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          updateHiddenInput(hiddenInput);
        });
      });
      cartForm.addEventListener('submit', function () {
        updateHiddenInput(hiddenInput);
      });
    }

    document.addEventListener('click', function (ev) {
      if (saleMode !== 'hybrid') return;
      var btn = ev.target.closest('.qtyIncDec .incQty, .qtyIncDec .decQty');
      if (!btn) return;
      setTimeout(function () { clampHybridQuantities(); }, 0);
    });
    document.addEventListener('change', function (ev) {
      if (saleMode !== 'hybrid') return;
      var input = ev.target;
      if (!input || !input.closest || !input.closest('.qtyIncDec')) return;
      clampHybridQuantities();
    });

    // ── Fit viewport ───────────────────────────────────────────────
    fitStageToContent(stage, seats, config);

    // ── Viewport controls (zoom + pan) — deshabilitado en modo zone ──
    if (saleMode !== 'zone') {
      initViewport(stage);
    }

    // ── Responsive resize ──────────────────────────────────────────
    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        stage.width(container.clientWidth || 800);
        stage.height(container.clientHeight || 400);
        fitStageToContent(stage, seats, config);
      }, 150);
    });

    // Signal that the map finished rendering
    document.dispatchEvent(new CustomEvent('ss:konva-ready'));
  }

  // ═══════════════════════════════════════════════════════════════════
  //  SEAT TOGGLE
  // ═══════════════════════════════════════════════════════════════════

  function toggleSeat(seatId, node) {
    var idx = selectedSeats.indexOf(seatId);
    var zone = node.zone;

    if (idx === -1) {
      // ── Select ──
      selectedSeats.push(seatId);
      if (!selectedByType[zone]) { selectedByType[zone] = []; }
      selectedByType[zone].push(seatId);

      node.circle.fill(COLOR_SELECTED);
      node.circle.stroke('#006095');
      node.label.fill('white');
    } else {
      // ── Deselect ──
      selectedSeats.splice(idx, 1);
      if (selectedByType[zone]) {
        var ti = selectedByType[zone].indexOf(seatId);
        if (ti !== -1) { selectedByType[zone].splice(ti, 1); }
        if (!selectedByType[zone].length) { delete selectedByType[zone]; }
      }

      node.circle.fill(node.baseColor);
      node.circle.stroke('black');
      node.label.fill('white');
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  //  SYNC WITH WOOCOMMERCE TICKET CONTROLS
  //  Mirrors logic from ss-seating.js: buildTicketMap + syncAllQuantities
  // ═══════════════════════════════════════════════════════════════════

  /**
   * Reads the ticket name from a .qtyIncDec wrapper.
   * Source: input[name="option_name[]"] inside closest .mep_ticket_item
   */
  function resolveTicketLabel(qtyWrapper) {
    var ticketItem = qtyWrapper.closest('.mep_ticket_item');
    if (ticketItem) {
      var nameInput = ticketItem.querySelector('input[name="option_name[]"]');
      if (nameInput && nameInput.value.trim()) {
        return nameInput.value.trim().toUpperCase();
      }
    }
    // Fallback: data-* attributes
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
    return null;
  }

  /**
   * Scans DOM for all .qtyIncDec wrappers and builds a map:
   *   { "VIP": { input, plus, minus }, "GENERAL": { input, plus, minus } }
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
      if (!input || !plus || !minus) return;

      // Skip disabled / locked / hidden tickets
      if (input.disabled) return;
      var ticketItem = wrapper.closest('.mep_ticket_item');
      var bothBtnsDisabled = plus.classList.contains('mpDisabled') &&
                             minus.classList.contains('mpDisabled');
      var itemLocked = ticketItem &&
                       (ticketItem.classList.contains('mpDisabled')    ||
                        ticketItem.classList.contains('mep_disabled')  ||
                        ticketItem.classList.contains('ticket-locked') ||
                        ticketItem.classList.contains('sold-out')      ||
                        ticketItem.classList.contains('mep_sold_out'));
      if (bothBtnsDisabled || itemLocked) return;
      if (wrapper.offsetParent === null) return;

      var label = resolveTicketLabel(wrapper);
      if (!label) return;

      if (map[label]) {
        var suffix = 2;
        while (map[label + '_' + suffix]) { suffix++; }
        label = label + '_' + suffix;
      }

      map[label] = { input: input, plus: plus, minus: minus };
    });

    return map;
  }

  /**
   * Sets a ticket quantity input to `desired`, respecting min/max,
   * then fires jQuery change+input events (same as MPWEM plugin expects).
   */
  function syncOneControl(controls, desired) {
    if (!controls || !controls.input) return;

    var min = parseInt(controls.input.getAttribute('min'), 10);
    var max = parseInt(controls.input.getAttribute('max'), 10);
    if (!isNaN(min)) desired = Math.max(desired, min);
    if (!isNaN(max)) desired = Math.min(desired, max);

    // Lock manual editing while seating controls quantity
    if (desired > 0) {
      controls.input.setAttribute('readonly', 'readonly');
    } else {
      controls.input.removeAttribute('readonly');
    }

    if (window.jQuery) {
      jQuery(controls.input).val(desired).trigger('change').trigger('input');
    } else {
      controls.input.value = desired;
      controls.input.dispatchEvent(new Event('change', { bubbles: true }));
      controls.input.dispatchEvent(new Event('input',  { bubbles: true }));
    }
  }

  /**
   * Synchronizes all ticket quantity inputs based on selectedByType.
   */
  function syncSeatSelection() {
    var ticketMap = buildTicketMap();
    if (!Object.keys(ticketMap).length) return;

    // Set each known ticket type to the count of selected seats
    var keys = Object.keys(ticketMap);
    for (var i = 0; i < keys.length; i++) {
      var label = keys[i];
      var count = selectedByType[label] ? selectedByType[label].length : 0;
      syncOneControl(ticketMap[label], count);
    }
  }

  /**
   * Hybrid mode: sync qty to match seat selection.
   * Always sets qty = selectedCount. User can increase via +/- afterwards.
   * Input is NOT locked (readonly) so user retains manual control.
   */
  function enforceMinQuantities() {
    var ticketMap = buildTicketMap();
    var keys = Object.keys(ticketMap);
    for (var i = 0; i < keys.length; i++) {
      var label = keys[i];
      var controls = ticketMap[label];
      var selectedCount = selectedByType[label] ? selectedByType[label].length : 0;

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
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  //  SUMMARY + HIDDEN INPUT
  // ═══════════════════════════════════════════════════════════════════

  function clampHybridQuantities() {
    if (saleMode !== 'hybrid') return;
    var ticketMap = buildTicketMap();
    var keys = Object.keys(ticketMap);
    for (var i = 0; i < keys.length; i++) {
      var label = keys[i];
      var controls = ticketMap[label];
      if (!controls || !controls.input) continue;
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
    }
  }

  function updateSummary(container, list) {
    if (!container || !list) return;
    if (selectedSeats.length > 0) {
      container.style.display = '';
      list.textContent = selectedSeats.join(', ');
    } else {
      container.style.display = 'none';
      list.textContent = '';
    }
  }

  function updateHiddenInput(input) {
    if (!input) return;
    input.value = selectedSeats.join(', ');
  }

  /**
   * Saves seat selection to WC session via AJAX (fallback for AJAX add-to-cart).
   * Uses the same endpoint as ss-seating.js: wp_ajax_ss_save_seats
   */
  function saveSeatsToSession() {
    if (!window.ssSeatingAjax || !window.ssSeatingAjax.url) return;
    var data = new FormData();
    data.append('action', 'ss_save_seats');
    data.append('nonce',  window.ssSeatingAjax.nonce);
    data.append('seats',  selectedSeats.join(', '));
    if (window.ssSeatingAjax.eventId) {
      data.append('event_id', window.ssSeatingAjax.eventId);
    }
    // Fire-and-forget — no need to await
    fetch(window.ssSeatingAjax.url, { method: 'POST', body: data, credentials: 'same-origin' })
      .catch(function () { /* silent */ });
  }

  // ═══════════════════════════════════════════════════════════════════
  //  CONFIG NORMALISATION
  //  wp_localize_script converts numbers to strings — fix that.
  // ═══════════════════════════════════════════════════════════════════

  function normalizeConfig(raw) {
    var cfg = {
      startX:    num(raw.startX, 100),
      spacing:   num(raw.spacing, SEAT_RADIUS * 3),
      rows:      [],
      zones:     raw.zones || [],
      zoneRects: [],
      layout:    {}
    };

    if (Array.isArray(raw.rows)) {
      for (var r = 0; r < raw.rows.length; r++) {
        var src = raw.rows[r];
        if (src.type === 'empty') {
          cfg.rows.push({ type: 'empty' });
          continue;
        }
        if (src.type === 'floor-label') {
          cfg.rows.push({ type: 'floor-label', text: src.text || 'PISO', y: num(src.y, 0) });
          continue;
        }
        cfg.rows.push({
          label:        src.label || '',
          count:        num(src.count, 0),
          y:            num(src.y, 0),
          zone:         src.zone || 'GENERAL',
          gaps:         normalizeGaps(src.gaps),
          removedSeats: normalizeRemovedSeats(src.removedSeats),
          reverse:      src.reverse  === true || src.reverse  === 'true',
          renumber:     src.renumber === true || src.renumber === 'true'
        });
      }
    }

    if (Array.isArray(raw.zoneRects)) {
      for (var z = 0; z < raw.zoneRects.length; z++) {
        var zr = raw.zoneRects[z];
        cfg.zoneRects.push({
          id:     zr.id || '',
          x:      num(zr.x, 0),
          y:      num(zr.y, 0),
          width:  num(zr.width, 100),
          height: num(zr.height, 40)
        });
      }
    }

    if (Array.isArray(raw.zones)) {
      cfg.zones = [];
      for (var zi = 0; zi < raw.zones.length; zi++) {
        cfg.zones.push({
          id:    raw.zones[zi].id || '',
          color: raw.zones[zi].color || '#4a90d9'
        });
      }
    }

    if (raw.layout && raw.layout.stage) {
      var s = raw.layout.stage;
      cfg.layout.stage = {
        x:      num(s.x, 80),
        y:      num(s.y, 40),
        width:  num(s.width, 500),
        height: num(s.height, 40),
        text:   s.text || 'ESCENARIO'
      };
    }

    return cfg;
  }

  function normalizeGaps(gaps) {
    if (!gaps || !Array.isArray(gaps)) return [];
    var result = [];
    for (var i = 0; i < gaps.length; i++) {
      var a = num(gaps[i].after, -1);
      var s = num(gaps[i].size, 1);
      if (a >= 0) result.push({ after: a, size: s });
    }
    return result;
  }

  function normalizeRemovedSeats(arr) {
    if (!arr || !Array.isArray(arr)) return [];
    var result = [];
    for (var i = 0; i < arr.length; i++) {
      var n = num(arr[i], -1);
      if (n > 0) result.push(n);
    }
    return result;
  }

  function num(v, fallback) {
    if (typeof v === 'number') return v;
    var n = parseFloat(v);
    return isNaN(n) ? fallback : n;
  }

  // ═══════════════════════════════════════════════════════════════════
  //  DRAWING HELPERS
  // ═══════════════════════════════════════════════════════════════════

  function buildZoneColorMap(zones) {
    var map = {};
    for (var i = 0; i < zones.length; i++) {
      map[zones[i].id] = zones[i].color;
    }
    return map;
  }

  function arrayToSet(arr) {
    var s = {};
    if (!arr) return s;
    // wp_localize_script convierte arrays PHP a objetos JS ({0:"A1",1:"A2"})
    // Soportar tanto arrays nativos como objetos indexados
    if (Array.isArray(arr)) {
      for (var i = 0; i < arr.length; i++) {
        s[String(arr[i]).trim()] = true;
      }
    } else if (typeof arr === 'object') {
      var keys = Object.keys(arr);
      for (var k = 0; k < keys.length; k++) {
        s[String(arr[keys[k]]).trim()] = true;
      }
    }
    return s;
  }

  function drawEscenario(layer, cfg) {
    var group = new Konva.Group({ x: cfg.x, y: cfg.y, listening: false });

    group.add(new Konva.Rect({
      width:        cfg.width,
      height:       cfg.height,
      fill:         '#333',
      cornerRadius: 5,
    }));

    group.add(new Konva.Text({
      y:         Math.max(0, (cfg.height - 18) / 2),
      width:     cfg.width,
      text:      cfg.text,
      fontSize:  18,
      fill:      'white',
      align:     'center',
      listening: false,
    }));

    layer.add(group);
  }

  function drawZoneRect(layer, zr, zoneColorMap) {
    var color = zoneColorMap[zr.id] || '#4a90d9';
    var group = new Konva.Group({ x: zr.x, y: zr.y, listening: false });

    group.add(new Konva.Rect({
      width:        zr.width,
      height:       zr.height,
      fill:         color,
      opacity:      0.10,
      cornerRadius: 6,
    }));

    group.add(new Konva.Rect({
      width:        zr.width,
      height:       zr.height,
      stroke:       color,
      strokeWidth:  1.5,
      cornerRadius: 6,
      dash:         [6, 3],
      opacity:      0.35,
      listening:    false,
    }));

    group.add(new Konva.Text({
      x:         6,
      y:         4,
      text:      zr.id,
      fontSize:  11,
      fontStyle: 'bold',
      fill:      color,
      opacity:   0.6,
      listening: false,
    }));

    layer.add(group);
  }

  /**
   * Draws a seat (circle + label). Returns a node descriptor for the click handler.
   * Both the circle and label carry a `seatId` custom attribute for event delegation.
   */
  function drawSeat(group, seat, zoneColorMap, soldSet, reservedSet, currentSaleMode) {
    var baseColor = zoneColorMap[seat.zone] || '#4a90d9';
    var isSold     = soldSet[seat.id]     === true;
    var isReserved = reservedSet[seat.id] === true;

    var fillColor, strokeColor, textColor;
    var interactive = true;

    // Zone mode: all seats are view-only (no interaction)
    if (currentSaleMode === 'zone') {
      interactive = false;
    }

    if (isSold) {
      fillColor   = COLOR_SOLD_FILL;
      strokeColor = COLOR_SOLD_STROKE;
      textColor   = COLOR_SOLD_TEXT;
      interactive = false;
    } else if (isReserved) {
      fillColor   = COLOR_RESV_FILL;
      strokeColor = COLOR_RESV_STROKE;
      textColor   = COLOR_RESV_TEXT;
      interactive = false;
    } else {
      fillColor   = baseColor;
      strokeColor = 'black';
      textColor   = 'white';
    }

    var circle = new Konva.Circle({
      x:           seat.x,
      y:           seat.y,
      radius:      SEAT_RADIUS,
      fill:        fillColor,
      stroke:      strokeColor,
      strokeWidth: 1,
      listening:   interactive,
      seatId:      seat.id,
    });

    var label = new Konva.Text({
      x:         seat.x - SEAT_RADIUS,
      y:         seat.y - 6,
      width:     SEAT_RADIUS * 2,
      text:      seat.id,
      fontSize:  9,
      fontStyle: 'bold',
      fill:      textColor,
      align:     'center',
      listening: interactive,
      seatId:    seat.id,
    });

    // Pointer cursor on available seats
    if (interactive) {
      circle.on('mouseenter', function () {
        circle.getStage().container().style.cursor = 'pointer';
      });
      circle.on('mouseleave', function () {
        circle.getStage().container().style.cursor = '';
      });
      label.on('mouseenter', function () {
        label.getStage().container().style.cursor = 'pointer';
      });
      label.on('mouseleave', function () {
        label.getStage().container().style.cursor = '';
      });
    }

    group.add(circle);
    group.add(label);

    return { circle: circle, label: label, zone: seat.zone, baseColor: baseColor };
  }

  // ═══════════════════════════════════════════════════════════════════
  //  VIEWPORT — Fit / Zoom / Pan  (mirrors builder logic)
  // ═══════════════════════════════════════════════════════════════════

  function fitStageToContent(stage, seats, config) {
    if (!seats.length) return;

    var minX =  Infinity, maxX = -Infinity;
    var minY =  Infinity, maxY = -Infinity;

    for (var i = 0; i < seats.length; i++) {
      var s = seats[i];
      if (s.x - SEAT_RADIUS < minX) minX = s.x - SEAT_RADIUS;
      if (s.x + SEAT_RADIUS > maxX) maxX = s.x + SEAT_RADIUS;
      if (s.y - SEAT_RADIUS < minY) minY = s.y - SEAT_RADIUS;
      if (s.y + SEAT_RADIUS > maxY) maxY = s.y + SEAT_RADIUS;
    }

    if (config.layout && config.layout.stage) {
      var esc = config.layout.stage;
      if (esc.x < minX) minX = esc.x;
      if (esc.x + esc.width  > maxX) maxX = esc.x + esc.width;
      if (esc.y < minY) minY = esc.y;
      if (esc.y + esc.height > maxY) maxY = esc.y + esc.height;
    }

    // Include floor-label banners in bounding box
    var flRows = config.rows || [];
    for (var fl = 0; fl < flRows.length; fl++) {
      if (flRows[fl].type !== 'floor-label') continue;
      var fy = flRows[fl].y;
      if (fy - 14 < minY) minY = fy - 14;
      if (fy + 8 > maxY) maxY = fy + 8;
    }

    var contentW = maxX - minX;
    var contentH = maxY - minY;
    var padding  = 30;
    var stageW   = stage.width();
    var stageH   = stage.height();

    var scaleX   = (stageW - padding * 2) / contentW;
    var scaleY   = (stageH - padding * 2) / contentH;
    var newScale  = Math.min(scaleX, scaleY);
    newScale      = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

    stage.scale({ x: newScale, y: newScale });

    var centerX = (minX + maxX) / 2;
    var centerY = (minY + maxY) / 2;
    stage.position({
      x: stageW / 2 - centerX * newScale,
      y: stageH / 2 - centerY * newScale,
    });

    stage.batchDraw();
  }

  function initViewport(stage) {
    // Zoom on wheel
    stage.container().addEventListener('wheel', function (e) {
      e.preventDefault();
      var pointer = stage.getPointerPosition();
      if (!pointer) return;

      var oldScale = stage.scaleX();
      var mousePointTo = {
        x: (pointer.x - stage.x()) / oldScale,
        y: (pointer.y - stage.y()) / oldScale,
      };

      var newScale = e.deltaY > 0
        ? oldScale / ZOOM_STEP
        : oldScale * ZOOM_STEP;
      newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

      stage.scale({ x: newScale, y: newScale });
      stage.position({
        x: pointer.x - mousePointTo.x * newScale,
        y: pointer.y - mousePointTo.y * newScale,
      });
      stage.batchDraw();
    }, { passive: false });

    // Pan via drag on empty canvas
    var isPanning = false;
    var panMoved  = false;
    var lastPos   = { x: 0, y: 0 };

    stage.on('mousedown touchstart', function (e) {
      if (e.target !== stage) return;
      isPanning = true;
      panMoved  = false;
      var pos = e.evt.touches ? e.evt.touches[0] : e.evt;
      lastPos = { x: pos.clientX, y: pos.clientY };
      stage.container().style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', onMove);
    window.addEventListener('touchmove', onMove, { passive: false });

    function onMove(e) {
      if (!isPanning) return;
      panMoved = true;
      if (e.touches) e.preventDefault();
      var pos = e.touches ? e.touches[0] : e;
      var dx = pos.clientX - lastPos.x;
      var dy = pos.clientY - lastPos.y;
      lastPos = { x: pos.clientX, y: pos.clientY };
      stage.x(stage.x() + dx);
      stage.y(stage.y() + dy);
      stage.batchDraw();
    }

    window.addEventListener('mouseup',  endPan);
    window.addEventListener('touchend', endPan);

    function endPan() {
      if (!isPanning) return;
      isPanning = false;
      stage.container().style.cursor = '';
    }

    // Touch pinch zoom
    var lastDist = 0;
    stage.container().addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        lastDist = getTouchDist(e.touches);
      }
    }, { passive: false });

    stage.container().addEventListener('touchmove', function (e) {
      if (e.touches.length !== 2) return;
      e.preventDefault();
      var dist = getTouchDist(e.touches);
      if (lastDist === 0) { lastDist = dist; return; }

      var oldScale = stage.scaleX();
      var newScale = oldScale * (dist / lastDist);
      newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

      var cx = (e.touches[0].clientX + e.touches[1].clientX) / 2;
      var cy = (e.touches[0].clientY + e.touches[1].clientY) / 2;
      var rect = stage.container().getBoundingClientRect();
      var pointer = { x: cx - rect.left, y: cy - rect.top };
      var mousePointTo = {
        x: (pointer.x - stage.x()) / oldScale,
        y: (pointer.y - stage.y()) / oldScale,
      };

      stage.scale({ x: newScale, y: newScale });
      stage.position({
        x: pointer.x - mousePointTo.x * newScale,
        y: pointer.y - mousePointTo.y * newScale,
      });
      stage.batchDraw();
      lastDist = dist;
    }, { passive: false });

    function getTouchDist(touches) {
      var dx = touches[0].clientX - touches[1].clientX;
      var dy = touches[0].clientY - touches[1].clientY;
      return Math.sqrt(dx * dx + dy * dy);
    }
  }

})();
