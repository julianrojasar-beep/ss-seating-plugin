// ═══════════════════════════════════════════════════════════════════
//  SS Box Office — Standalone seat map for manual reservations/sales
//  Depends on: konva.min.js, seat-engine.js (window.SeatEngine)
//  Data from PHP: window.ssLayoutData, window.ssBoxOfficeState, window.ssBoxOffice
// ═══════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  // ─── Constants ─────────────────────────────────────────────────
  var SEAT_RADIUS = 15;
  var ZOOM_MIN    = 0.3;
  var ZOOM_MAX    = 3;
  var ZOOM_STEP   = 1.08;
  var POLL_INTERVAL = 30000; // 30 seconds

  // ─── Colors by state ──────────────────────────────────────────
  // Colores configurables desde WP admin (SS Seating → Colores del mapa)
  var _c = (window.ssBoxOffice && window.ssBoxOffice.colors) || {};
  var COLOR_SOLD_FILL     = _c.sold     || '#ef5350';
  var COLOR_SOLD_STROKE   = _c.sold     ? shadeColor(_c.sold,     -30) : '#c62828';
  var COLOR_SOLD_TEXT     = '#fff';
  var COLOR_RESV_FILL     = _c.reserved || '#fff3cd';
  var COLOR_RESV_STROKE   = _c.reserved ? shadeColor(_c.reserved, -20) : '#ffc107';
  var COLOR_RESV_TEXT     = '#856404';
  var COLOR_MANUAL_FILL   = _c.manual   || '#90caf9';
  var COLOR_MANUAL_STROKE = _c.manual   ? shadeColor(_c.manual,   -30) : '#1976d2';
  var COLOR_MANUAL_TEXT   = '#0d47a1';
  var COLOR_SELECTED_FILL   = _c.selected || '#e94560';
  var COLOR_SELECTED_STROKE = _c.selected ? shadeColor(_c.selected, -30) : '#b71c1c';
  var COLOR_SELECTED_TEXT   = '#fff';

  function shadeColor(hex, pct) {
    var n = parseInt(hex.replace('#',''), 16);
    var r = Math.min(255, Math.max(0, (n >> 16) + pct));
    var g = Math.min(255, Math.max(0, ((n >> 8) & 0xff) + pct));
    var b = Math.min(255, Math.max(0, (n & 0xff) + pct));
    return '#' + ((1<<24)|(r<<16)|(g<<8)|b).toString(16).slice(1);
  }

  // ─── State ────────────────────────────────────────────────────
  var selectedSeats = [];
  var currentMode   = 'reservar'; // 'reservar' | 'vender' | 'liberar'
  var soldSet       = {};
  var reservedSet   = {};
  var manualSet     = {};
  var manualInfo    = {}; // seatId → nombre de reserva
  var soldInfo      = {}; // seatId → nombre del comprador
  var zoneInventory = {};
  var seatNodes     = {};
  var seats         = [];
  var stage, seatLayer, zoneColorMap;
  var bo = window.ssBoxOffice || {};
  var saleMode = bo.saleMode || 'seat'; // 'seat' | 'zone' | 'hybrid'

  // ─── Floor state ─────────────────────────────────────────────
  var _rawLayout     = null;  // full layout data (may have floors[])
  var _activeFloorBO = 0;     // index into _rawLayout.floors

  // ─── Init ─────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', init);

  function init() {
    var hasKonva  = typeof Konva !== 'undefined' && typeof SeatEngine !== 'undefined';
    _rawLayout    = window.ssLayoutData || null;
    // Normalize legacy (rows) → floors format
    if (_rawLayout && !_rawLayout.floors && _rawLayout.rows) {
      _rawLayout.floors = [{
        id: 'piso-1', label: 'Principal',
        rows: _rawLayout.rows, zoneRects: _rawLayout.zoneRects || [],
        layout: _rawLayout.layout || {}
      }];
    }
    var hasLayout = hasKonva && _rawLayout && _rawLayout.floors && _rawLayout.floors.length > 0;
    var showMap   = hasLayout;

    var container   = document.getElementById('bo-konva-container');
    var legend      = document.getElementById('bo-legend');
    var zoneTickets = document.getElementById('bo-zone-tickets');

    // ── Show/hide sections based on sale mode ────────────────
    if (saleMode === 'zone') {
      // Zone: show map (view-only) + ticket controls
      if (zoneTickets) zoneTickets.style.display = '';
      // Hide reservar/liberar — only vender in zone mode
      hideModesForZoneOnly();
    } else if (saleMode === 'hybrid') {
      // Both map + ticket controls
      if (zoneTickets) zoneTickets.style.display = '';
    }
    // seat mode: defaults are fine (map shown, zone tickets hidden)

    // ── Init Konva map if needed ─────────────────────────────
    if (showMap && container) {
      initMap(container);
    }

    // ── Zone ticket controls ─────────────────────────────────
    if (saleMode === 'zone' || saleMode === 'hybrid') {
      initZoneTicketControls();
    }

    // ── Toolbar ──────────────────────────────────────────────
    initToolbar();
    initReserveModal();
    initSellModal();
    initSuccessModal();

    // ── Sidebar tabs ────────────────────────────────────────
    initSidebarTabs();
    initTransferPanel();

    // ── Load initial state + start polling ────────────────
    refreshState();
    refreshLog();
    refreshOrders();
    setInterval(function () {
      refreshState();
      refreshLog();
      refreshOrders();
    }, POLL_INTERVAL);
  }

  function hideModesForZoneOnly() {
    var reservarBtn = document.querySelector('.bo-toolbar__mode[data-mode="reservar"]');
    var liberarBtn  = document.querySelector('.bo-toolbar__mode[data-mode="liberar"]');
    if (reservarBtn) reservarBtn.style.display = 'none';
    if (liberarBtn) liberarBtn.style.display = 'none';
    // Auto-select vender mode
    currentMode = 'vender';
    var venderBtn = document.querySelector('.bo-toolbar__mode[data-mode="vender"]');
    if (venderBtn) venderBtn.classList.add('active');
  }

  // ═══════════════════════════════════════════════════════════════
  //  MAP INIT
  // ═══════════════════════════════════════════════════════════════

  function _renderBOFloorTabs() {
    var tabsEl = document.getElementById('bo-floor-tabs');
    if (!tabsEl || !_rawLayout || !_rawLayout.floors || _rawLayout.floors.length < 2) return;
    tabsEl.innerHTML = '';
    for (var i = 0; i < _rawLayout.floors.length; i++) {
      (function(idx) {
        var isActive = idx === _activeFloorBO;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = _rawLayout.floors[idx].label || ('Piso ' + (idx + 1));
        btn.style.cssText = 'padding:3px 10px;font-size:12px;font-weight:600;border-radius:4px;cursor:pointer;border:1px solid ' + (isActive ? '#2271b1' : '#c3c4c7') + ';background:' + (isActive ? '#2271b1' : '#f6f7f7') + ';color:' + (isActive ? '#fff' : '#50575e') + ';';
        btn.addEventListener('click', function() {
          if (_activeFloorBO === idx) return;
          _activeFloorBO = idx;
          selectedSeats = [];
          var container = document.getElementById('bo-konva-container');
          if (container) initMap(container);
          _renderBOFloorTabs();
        });
        tabsEl.appendChild(btn);
      })(i);
    }
  }

  function initMap(container) {
    var floorData  = (_rawLayout && _rawLayout.floors) ? _rawLayout.floors[_activeFloorBO] : (_rawLayout || {});
    var floorRaw   = {
      startX:    _rawLayout ? _rawLayout.startX    : 100,
      spacing:   _rawLayout ? _rawLayout.spacing   : 45,
      zones:     _rawLayout ? _rawLayout.zones     : [],
      rows:      floorData.rows      || [],
      zoneRects: floorData.zoneRects || [],
      layout:    floorData.layout    || {}
    };
    var config = normalizeConfig(floorRaw);
    _renderBOFloorTabs();
    var state  = window.ssBoxOfficeState || { sold: [], reserved: [], manual_reserved: [] };

    seats = SeatEngine.buildSeatsFromConfig(config);
    if (!seats.length) return;

    // ── Destroy existing stage before re-init (floor switch) ──
    if (stage) { stage.destroy(); stage = null; }
    container.innerHTML = '';

    // ── Create Stage ──────────────────────────────────────────
    stage = new Konva.Stage({
      container: 'bo-konva-container',
      width:  container.clientWidth  || 800,
      height: container.clientHeight || 400,
    });

    var zoneLayer = new Konva.Layer();
    seatLayer = new Konva.Layer({ listening: true });
    stage.add(zoneLayer);
    stage.add(seatLayer);

    // ── Draw escenario ─────────────────────────────────────────
    if (config.layout && config.layout.stage) {
      drawEscenario(zoneLayer, config.layout.stage);
    }

    // ── Draw zone rects ────────────────────────────────────────
    zoneColorMap = buildZoneColorMap(config.zones || []);
    var zoneRects = config.zoneRects || [];
    for (var z = 0; z < zoneRects.length; z++) {
      drawZoneRect(zoneLayer, zoneRects[z], zoneColorMap);
    }
    zoneLayer.batchDraw();

    // ── Draw seats ─────────────────────────────────────────────
    soldSet     = arrayToSet(state.sold);
    reservedSet = arrayToSet(state.reserved);
    manualSet   = arrayToSet(state.manual_reserved);

    var seatsGroup = new Konva.Group();
    for (var i = 0; i < seats.length; i++) {
      var node = drawSeat(seatsGroup, seats[i]);
      seatNodes[seats[i].id] = node;
    }
    seatLayer.add(seatsGroup);
    seatLayer.batchDraw();

    // ── Click handler (disabled in zone mode — view only) ─────
    if (saleMode !== 'zone') {
      seatLayer.on('click tap', onSeatClick);
    }

    // ── Viewport ───────────────────────────────────────────────
    fitStageToContent(stage, seats, config);
    initViewport(stage);

    // ── Resize ─────────────────────────────────────────────────
    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        stage.width(container.clientWidth || 800);
        stage.height(container.clientHeight || 400);
        fitStageToContent(stage, seats, config);
      }, 150);
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  ZONE TICKET CONTROLS
  // ═══════════════════════════════════════════════════════════════

  function initZoneTicketControls() {
    var btns = document.querySelectorAll('.bo-zone-ticket__btn');
    btns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = btn.closest('.bo-zone-ticket');
        if (!row) return;
        var input = row.querySelector('.bo-zone-ticket__qty');
        if (!input) return;
        var val = parseInt(input.value, 10) || 0;
        var dir = parseInt(btn.getAttribute('data-dir'), 10);
        var max = parseInt(row.getAttribute('data-max'), 10);
        var name = row.getAttribute('data-ticket') || '';
        var seatCountInZone = getSelectedSeatCountForZone(name);
        var maxZoneTickets = isNaN(max) ? Infinity : Math.max(0, max - seatCountInZone);
        val = Math.max(0, val + dir);
        if (val > maxZoneTickets) val = maxZoneTickets;
        input.value = val;
        updateSelectionInfo();
        updateActionButton();
      });
    });
  }

  function getZoneTicketQtys() {
    var qtys = {};
    var rows = document.querySelectorAll('.bo-zone-ticket');
    rows.forEach(function (row) {
      var name  = row.getAttribute('data-ticket');
      var input = row.querySelector('.bo-zone-ticket__qty');
      var val   = input ? parseInt(input.value, 10) || 0 : 0;
      if (val > 0 && name) { qtys[name] = val; }
    });
    return qtys;
  }

  function getTotalZoneQty() {
    var qtys = getZoneTicketQtys();
    var total = 0;
    var keys = Object.keys(qtys);
    for (var i = 0; i < keys.length; i++) { total += qtys[keys[i]]; }
    return total;
  }

  function resetZoneTicketQtys() {
    var inputs = document.querySelectorAll('.bo-zone-ticket__qty');
    inputs.forEach(function (inp) { inp.value = '0'; });
  }

  function getSelectedSeatCountForZone(zone) {
    if (!zone) return 0;
    var target = String(zone).trim().toUpperCase();
    var count = 0;
    for (var i = 0; i < selectedSeats.length; i++) {
      var seatId = selectedSeats[i];
      var node = seatNodes[seatId];
      var seatZone = node && node.zone ? String(node.zone).trim().toUpperCase() : '';
      if (seatZone === target) count++;
    }
    return count;
  }

  function getZoneTicketQtyFor(zone) {
    if (!zone) return 0;
    var target = String(zone).trim().toUpperCase();
    var rows = document.querySelectorAll('.bo-zone-ticket');
    for (var i = 0; i < rows.length; i++) {
      var name = (rows[i].getAttribute('data-ticket') || '').trim().toUpperCase();
      if (name !== target) continue;
      var input = rows[i].querySelector('.bo-zone-ticket__qty');
      var qty = input ? parseInt(input.value, 10) : 0;
      return isNaN(qty) ? 0 : qty;
    }
    return 0;
  }

  function getZoneAvailableFor(zone) {
    if (!zone) return Infinity;
    var target = String(zone).trim().toUpperCase();
    if (zoneInventory[target] && typeof zoneInventory[target].available !== 'undefined') {
      var a = parseInt(zoneInventory[target].available, 10);
      if (!isNaN(a)) return a;
    }
    var rows = document.querySelectorAll('.bo-zone-ticket');
    for (var i = 0; i < rows.length; i++) {
      var name = (rows[i].getAttribute('data-ticket') || '').trim().toUpperCase();
      if (name !== target) continue;
      var availEl = rows[i].querySelector('.bo-zone-ticket__avail');
      if (availEl) {
        var txt = (availEl.textContent || '').match(/(\d+)/);
        if (txt) {
          var parsed = parseInt(txt[1], 10);
          if (!isNaN(parsed)) return parsed;
        }
      }
      var max = parseInt(rows[i].getAttribute('data-max'), 10);
      return isNaN(max) ? Infinity : max;
    }
    return Infinity;
  }

  function updateStatsPanel(inventory) {
    var total = 0, sold = 0, reserved = 0, available = 0;
    Object.keys(inventory || {}).forEach(function (k) {
      var z = inventory[k];
      total     += (z.total     || 0);
      sold      += (z.sold      || 0);
      reserved  += (z.reserved  || 0);
      available += (z.available || 0);
    });
    var elTotal = document.getElementById('bo-stat-total');
    var elSold  = document.getElementById('bo-stat-sold');
    var elResv  = document.getElementById('bo-stat-reserved');
    var elAvail = document.getElementById('bo-stat-available');
    var elBar   = document.getElementById('bo-stat-bar');
    var elPct   = document.getElementById('bo-stat-pct');
    if (!elTotal) return;
    elTotal.textContent = total;
    elSold.textContent  = sold;
    elResv.textContent  = reserved;
    elAvail.textContent = available;
    var pct = total > 0 ? Math.round((sold / total) * 100) : 0;
    if (elBar)  elBar.style.width = pct + '%';
    if (elPct)  elPct.textContent = pct + '% ocupado';
  }

  function updateZoneAvailability(inventory) {
    // Store normalized inventory for cross-zone lookups
    zoneInventory = {};
    Object.keys(inventory || {}).forEach(function (k) {
      zoneInventory[String(k).trim().toUpperCase()] = inventory[k];
    });

    var rows = document.querySelectorAll('.bo-zone-ticket');
    rows.forEach(function (row) {
      var name = row.getAttribute('data-ticket');
      if (!name) return;
      var inv = inventory[name] || inventory[name.toUpperCase()];
      if (!inv) return;
      // Update data-max and display
      row.setAttribute('data-max', inv.available);
      var availEl = row.querySelector('.bo-zone-ticket__avail');
      if (availEl) availEl.textContent = inv.available;
      // Clamp: available minus seats already selected in this zone
      var seatCountInZone = getSelectedSeatCountForZone(name);
      var maxZoneTickets = Math.max(0, inv.available - seatCountInZone);
      var input = row.querySelector('.bo-zone-ticket__qty');
      if (input) {
        var val = parseInt(input.value, 10) || 0;
        if (val > maxZoneTickets) input.value = maxZoneTickets;
      }
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  SEAT CLICK
  // ═══════════════════════════════════════════════════════════════

  function onSeatClick(e) {
    var seatId = e.target.getAttr('seatId');
    if (!seatId) return;

    var node = seatNodes[seatId];
    if (!node) return;

    // Rules based on mode
    if (currentMode === 'reservar') {
      if (soldSet[seatId] || reservedSet[seatId] || manualSet[seatId]) return;
    } else if (currentMode === 'vender') {
      if (soldSet[seatId] || reservedSet[seatId]) return;
      // manual_reserved is selectable (we'll sell them)
    } else if (currentMode === 'liberar') {
      if (!manualSet[seatId]) return; // only manual
    }

    // In hybrid mode, check zone availability before adding
    if (currentMode === 'vender' && saleMode === 'hybrid' && selectedSeats.indexOf(seatId) === -1) {
      var zone = node.zone || '';
      var zoneAvailable = getZoneAvailableFor(zone);
      var zoneSeatCount = getSelectedSeatCountForZone(zone);
      var zoneTicketQty = getZoneTicketQtyFor(zone);
      if ((zoneSeatCount + zoneTicketQty) >= zoneAvailable) {
        showToast('Límite alcanzado en ' + zone + '.', 'error');
        return;
      }
    }

    // Toggle selection
    var idx = selectedSeats.indexOf(seatId);
    if (idx === -1) {
      selectedSeats.push(seatId);
      node.circle.fill(COLOR_SELECTED_FILL);
      node.circle.stroke(COLOR_SELECTED_STROKE);
      node.label.fill(COLOR_SELECTED_TEXT);
      if (currentMode === 'vender') { boTempReserve([seatId], 'lock'); }
    } else {
      selectedSeats.splice(idx, 1);
      applySeatColor(seatId, node);
      if (currentMode === 'vender') { boTempReserve([seatId], 'unlock'); }
    }

    seatLayer.batchDraw();
    updateSelectionInfo();
  }

  function applySeatColor(seatId, node) {
    if (soldSet[seatId]) {
      node.circle.fill(COLOR_SOLD_FILL);
      node.circle.stroke(COLOR_SOLD_STROKE);
      node.label.fill(COLOR_SOLD_TEXT);
    } else if (reservedSet[seatId]) {
      node.circle.fill(COLOR_RESV_FILL);
      node.circle.stroke(COLOR_RESV_STROKE);
      node.label.fill(COLOR_RESV_TEXT);
    } else if (manualSet[seatId]) {
      node.circle.fill(COLOR_MANUAL_FILL);
      node.circle.stroke(COLOR_MANUAL_STROKE);
      node.label.fill(COLOR_MANUAL_TEXT);
    } else {
      node.circle.fill(node.baseColor);
      node.circle.stroke('black');
      node.label.fill('white');
    }
  }

  function boTempReserve(seats, lockAction) {
    if (!seats || !seats.length) return;
    var fd = new FormData();
    fd.append('action', 'ss_boxoffice_temp_reserve');
    fd.append('nonce', getNonce());
    fd.append('event_id', bo.eventId);
    fd.append('seats', seats.join(','));
    fd.append('lock_action', lockAction);
    fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
  }

  function clearSelection() {
    // Si estamos en modo vender, liberar las temp_reserved antes de limpiar
    if (currentMode === 'vender' && selectedSeats.length) {
      boTempReserve(selectedSeats.slice(), 'unlock');
    }
    for (var i = 0; i < selectedSeats.length; i++) {
      var node = seatNodes[selectedSeats[i]];
      if (node) { applySeatColor(selectedSeats[i], node); }
    }
    selectedSeats = [];
    if (seatLayer) seatLayer.batchDraw();
    if (saleMode === 'hybrid') {
      updateZoneAvailability(zoneInventory);
    }
    updateSelectionInfo();
  }

  function updateSelectionInfo() {
    var info = document.getElementById('bo-selection-info');
    var btn  = document.getElementById('bo-action-btn');
    if (!info || !btn) return;

    var seatCount = selectedSeats.length;
    var zoneQty   = (saleMode === 'zone' || saleMode === 'hybrid') ? getTotalZoneQty() : 0;
    var total     = seatCount + zoneQty;

    if (total === 0) {
      info.textContent = '';
      btn.disabled = true;
    } else {
      var parts = [];
      if (seatCount > 0) {
        parts.push(seatCount + ' silla' + (seatCount > 1 ? 's' : '') + ': ' + selectedSeats.join(', '));
      }
      if (zoneQty > 0) {
        var qtys = getZoneTicketQtys();
        var tktParts = [];
        var keys = Object.keys(qtys);
        for (var i = 0; i < keys.length; i++) { tktParts.push(keys[i] + ' x' + qtys[keys[i]]); }
        parts.push(tktParts.join(', '));
      }
      info.textContent = parts.join(' + ');
      btn.disabled = false;
    }
  }

  // ═══════════════════════════════════════════════════════════════
  //  TOOLBAR + MODES
  // ═══════════════════════════════════════════════════════════════

  function initToolbar() {
    var modeButtons = document.querySelectorAll('.bo-toolbar__mode');
    var actionBtn   = document.getElementById('bo-action-btn');

    modeButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        currentMode = btn.getAttribute('data-mode');
        modeButtons.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        clearSelection();
        resetZoneTicketQtys();
        updateActionButton();
      });
    });

    if (actionBtn) {
      actionBtn.addEventListener('click', onActionClick);
    }

    updateActionButton();
  }

  function updateActionButton() {
    var btn = document.getElementById('bo-action-btn');
    if (!btn) return;

    // Remove all variant classes
    btn.className = 'bo-toolbar__action visible';

    if (currentMode === 'reservar') {
      btn.textContent = 'Reservar selección';
      btn.classList.add('bo-toolbar__action--reserve');
    } else if (currentMode === 'vender') {
      btn.textContent = 'Crear pedido';
      btn.classList.add('bo-toolbar__action--sell');
    } else if (currentMode === 'liberar') {
      btn.textContent = 'Liberar selección';
      btn.classList.add('bo-toolbar__action--release');
    }

    var seatCount = selectedSeats.length;
    var zoneQty   = (saleMode === 'zone' || saleMode === 'hybrid') ? getTotalZoneQty() : 0;
    btn.disabled = (seatCount + zoneQty) === 0;
  }

  function onActionClick() {
    var seatCount = selectedSeats.length;
    var zoneQty   = (saleMode === 'zone' || saleMode === 'hybrid') ? getTotalZoneQty() : 0;
    if (seatCount + zoneQty === 0) return;

    if (currentMode === 'reservar') {
      openReserveModal();
    } else if (currentMode === 'vender') {
      openSellModal();
    } else if (currentMode === 'liberar') {
      doRelease();
    }
  }

  // ═══════════════════════════════════════════════════════════════
  //  AJAX ACTIONS
  // ═══════════════════════════════════════════════════════════════

  function getNonce() {
    return (typeof SS_BoxOffice !== 'undefined' && SS_BoxOffice.nonce) ? SS_BoxOffice.nonce : bo.nonce;
  }

  function ajaxPost(action, extraData) {
    var fd = new FormData();
    fd.append('action', 'ss_boxoffice_' + action);
    fd.append('nonce', getNonce());
    fd.append('event_id', bo.eventId);
    fd.append('bo_user', bo.user);
    if (selectedSeats.length) {
      fd.append('seats', selectedSeats.join(','));
    }
    if (extraData) {
      Object.keys(extraData).forEach(function (k) {
        fd.append(k, extraData[k]);
      });
    }
    return fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function doReserve(nombre) {
    var btn = document.getElementById('bo-action-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Reservando...'; }

    ajaxPost('reserve', { reserve_nombre: nombre || '' }).then(function (res) {
      if (res.success) {
        showToast(res.data.message, 'success');
      } else {
        showToast(res.data || 'Error al reservar', 'error');
      }
      closeReserveModal();
      clearSelection();
      refreshState();
      refreshLog();
      updateActionButton();
    }).catch(function () {
      showToast('Error de conexión', 'error');
      if (btn) { btn.disabled = false; }
      updateActionButton();
    });
  }

  function doRelease() {
    var btn = document.getElementById('bo-action-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Liberando...'; }

    ajaxPost('release').then(function (res) {
      if (res.success) {
        showToast(res.data.message, 'success');
      } else {
        showToast(res.data || 'Error al liberar', 'error');
      }
      clearSelection();
      refreshState();
      refreshLog();
      updateActionButton();
    }).catch(function () {
      showToast('Error de conexión', 'error');
      if (btn) { btn.disabled = false; }
      updateActionButton();
    });
  }

  function doSell(formData) {
    var btn = document.getElementById('bo-sell-confirm');
    if (btn) { btn.disabled = true; btn.textContent = 'Procesando...'; }

    // Append zone ticket qtys if any
    var zoneQtys = (saleMode === 'zone' || saleMode === 'hybrid') ? getZoneTicketQtys() : {};
    if (Object.keys(zoneQtys).length > 0) {
      formData.ticket_qtys = JSON.stringify(zoneQtys);
    }

    ajaxPost('sell', formData).then(function (res) {
      closeSellModal();
      if (res.success) {
        showSuccessModal(res.data);
      } else {
        showToast(res.data || 'Error al vender', 'error');
      }
      clearSelection();
      resetZoneTicketQtys();
      refreshState();
      refreshLog();
      updateActionButton();
      if (btn) { btn.disabled = false; btn.textContent = 'Crear pedido'; }
    }).catch(function () {
      showToast('Error de conexión', 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Crear pedido'; }
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  RESERVE MODAL
  // ═══════════════════════════════════════════════════════════════

  function openReserveModal() {
    var modal   = document.getElementById('bo-reserve-modal');
    var seatsEl = document.getElementById('bo-reserve-seats');
    if (!modal) { doReserve(''); return; } // fallback si no existe el modal en el DOM
    if (seatsEl) {
      seatsEl.textContent = selectedSeats.length > 0 ? 'Sillas: ' + selectedSeats.join(', ') : '';
    }
    var inp = document.getElementById('bo-reserve-nombre');
    if (inp) { inp.value = ''; }
    modal.classList.add('open');
    if (inp) { inp.focus(); }
  }

  function closeReserveModal() {
    var modal = document.getElementById('bo-reserve-modal');
    if (modal) { modal.classList.remove('open'); }
  }

  function initReserveModal() {
    var cancel  = document.getElementById('bo-reserve-cancel');
    var confirm = document.getElementById('bo-reserve-confirm');
    if (cancel) {
      cancel.addEventListener('click', closeReserveModal);
    }
    if (confirm) {
      confirm.addEventListener('click', function () {
        var nombre = (document.getElementById('bo-reserve-nombre').value || '').trim();
        if (!nombre) {
          showToast('Ingresa el nombre de la reserva', 'error');
          return;
        }
        doReserve(nombre);
      });
    }
  }

  // ═══════════════════════════════════════════════════════════════
  //  SELL MODAL
  // ═══════════════════════════════════════════════════════════════

  function initSellModal() {
    var cancel  = document.getElementById('bo-sell-cancel');
    var confirm = document.getElementById('bo-sell-confirm');

    if (cancel) {
      cancel.addEventListener('click', closeSellModal);
    }
    if (confirm) {
      confirm.addEventListener('click', function () {
        var nombre = document.getElementById('bo-sell-nombre').value.trim();
        if (!nombre) {
          showToast('El nombre es obligatorio', 'error');
          return;
        }
        doSell({
          nombre:        nombre,
          correo:        document.getElementById('bo-sell-correo').value.trim(),
          telefono:      document.getElementById('bo-sell-telefono').value.trim(),
          metodo_pago:   document.getElementById('bo-sell-metodo').value,
          qr_mode:       document.getElementById('bo-sell-qrmode').value,
          valor_cobrado: (parseInt(document.getElementById('bo-valor-input').value, 10) || 0),
          nota_bo:       (document.getElementById('bo-sell-nota') ? document.getElementById('bo-sell-nota').value.trim() : ''),
        });
      });
    }
  }

  function openSellModal() {
    var modal     = document.getElementById('bo-sell-modal');
    var seatsEl   = document.getElementById('bo-sell-seats');
    var ticketsEl = document.getElementById('bo-sell-tickets');
    if (!modal) return;

    // Show seats info
    if (seatsEl) {
      if (selectedSeats.length > 0) {
        seatsEl.textContent = 'Sillas: ' + selectedSeats.join(', ');
        seatsEl.style.display = '';
      } else {
        seatsEl.style.display = 'none';
      }
    }

    // Show zone tickets info
    if (ticketsEl) {
      var qtys = (saleMode === 'zone' || saleMode === 'hybrid') ? getZoneTicketQtys() : {};
      var keys = Object.keys(qtys);
      if (keys.length > 0) {
        var parts = [];
        for (var i = 0; i < keys.length; i++) { parts.push(keys[i] + ' x' + qtys[keys[i]]); }
        ticketsEl.textContent = 'Tickets: ' + parts.join(', ');
        ticketsEl.style.display = '';
      } else {
        ticketsEl.style.display = 'none';
      }
    }

    // Clear form
    document.getElementById('bo-sell-nombre').value = '';
    document.getElementById('bo-sell-correo').value = '';
    document.getElementById('bo-sell-telefono').value = '';
    document.getElementById('bo-sell-metodo').value = 'efectivo';
    var valorInput = document.getElementById('bo-valor-input');
    if (valorInput) valorInput.value = '';
    var notaInput = document.getElementById('bo-sell-nota');
    if (notaInput) notaInput.value = '';
    document.getElementById('bo-sell-qrmode').value = 'order';

    // Show/hide QR mode selector — only relevant when there are seats
    var qrModeSelect = document.getElementById('bo-sell-qrmode');
    var qrModeLabel  = qrModeSelect ? qrModeSelect.previousElementSibling : null;
    if (qrModeSelect) {
      // Mostrar selector si hay más de 1 asiento O si hay tickets de zona con qty > 1
      var totalTickets = 0;
      var zqtys = (saleMode === 'zone' || saleMode === 'hybrid') ? getZoneTicketQtys() : {};
      var zkeys = Object.keys(zqtys);
      for (var zi = 0; zi < zkeys.length; zi++) { totalTickets += parseInt(zqtys[zkeys[zi]], 10) || 0; }
      var show = selectedSeats.length > 1 || totalTickets > 1;
      qrModeSelect.style.display = show ? '' : 'none';
      if (qrModeLabel) qrModeLabel.style.display = show ? '' : 'none';
    }

    modal.classList.add('open');

    // Actualizar calculadora
    updateValorCobrado();
  }

  // ═══════════════════════════════════════════════════════════════
  //  CALCULADORA DE DESCUENTOS
  // ═══════════════════════════════════════════════════════════════

  function updateValorCobrado() {
    var el = document.getElementById('bo-valor-cobrado');
    if (!el) return;

    var qty = 0;
    var unitPrice = 0;

    if (saleMode === 'zone' || saleMode === 'hybrid') {
      var zqtys = getZoneTicketQtys();
      var zkeys = Object.keys(zqtys);
      for (var zi = 0; zi < zkeys.length; zi++) {
        qty += parseInt(zqtys[zkeys[zi]], 10) || 0;
        if (ssBoxOffice && ssBoxOffice.ticketTypes) {
          for (var ti = 0; ti < ssBoxOffice.ticketTypes.length; ti++) {
            var tt = ssBoxOffice.ticketTypes[ti];
            if (tt.zone === zkeys[zi]) { unitPrice = parseFloat(tt.price) || 0; break; }
          }
        }
      }
    } else {
      qty = selectedSeats.length;
      if (ssBoxOffice && ssBoxOffice.ticketTypes && ssBoxOffice.ticketTypes.length > 0) {
        unitPrice = parseFloat(ssBoxOffice.ticketTypes[0].price) || 0;
      }
    }

    if (qty <= 0) { el.style.display = 'none'; return; }
    el.style.display = '';

    var refEl = document.getElementById('bo-valor-ref');
    if (refEl && unitPrice > 0) {
      var subtotal = qty * unitPrice;
      refEl.textContent = 'Referencia: $' + Math.round(subtotal).toLocaleString('es-CO') + ' (' + qty + ' × $' + Math.round(unitPrice).toLocaleString('es-CO') + ')';
    } else if (refEl) {
      refEl.textContent = '';
    }
  }

  function closeSellModal() {
    var modal = document.getElementById('bo-sell-modal');
    if (modal) { modal.classList.remove('open'); }
    // Liberar temp_reserved del BO al cerrar el modal (venta exitosa o cancelación)
    // En venta exitosa el servidor ya promovió a 'sold', el DELETE es un no-op seguro
    if (selectedSeats.length) { boTempReserve(selectedSeats.slice(), 'unlock'); }
  }

  // ═══════════════════════════════════════════════════════════════
  //  SUCCESS MODAL (QR)
  // ═══════════════════════════════════════════════════════════════

  var lastOrderId  = 0;
  var lastQrUrl    = '';
  var lastSeatQrs  = {};
  var ordersCache  = {}; // id → order data, para modal de QR desde lista

  function showSuccessModal(data) {
    var overlay = document.getElementById('bo-success-modal');
    var detail  = document.getElementById('bo-success-detail');
    var qrBox   = document.getElementById('bo-success-qr');
    if (!overlay || !detail || !qrBox) {
      showToast(data.message, 'success');
      return;
    }

    lastOrderId = data.order_id || 0;
    lastQrUrl   = data.qr_url || '';
    lastSeatQrs = data.seat_qrs && typeof data.seat_qrs === 'object' ? data.seat_qrs : {};

    // Detail info
    var detailHtml = '<strong>Pedido #' + data.order_id + '</strong><br>'
      + 'Cliente: ' + escHtml(data.nombre || '') + '<br>';

    if (data.seats && data.seats.length > 0) {
      detailHtml += 'Asientos: ' + escHtml(data.seats.join(', ')) + '<br>';
    }
    if (data.ticket_qtys && Object.keys(data.ticket_qtys).length > 0) {
      var tktParts = [];
      var tKeys = Object.keys(data.ticket_qtys);
      for (var t = 0; t < tKeys.length; t++) { tktParts.push(tKeys[t] + ' x' + data.ticket_qtys[tKeys[t]]); }
      detailHtml += 'Tickets: ' + escHtml(tktParts.join(', ')) + '<br>';
    }
    if (data.zones && data.zones.length > 0) {
      detailHtml += 'Zona: ' + escHtml(data.zones.join(', '));
    }

    detail.innerHTML = detailHtml;

    // Show QRs
    qrBox.innerHTML = '';
    var seatKeys = Object.keys(lastSeatQrs);

    if (seatKeys.length > 0) {
      // Per-seat QRs
      var grid = document.createElement('div');
      grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:16px;justify-content:center;';
      for (var i = 0; i < seatKeys.length; i++) {
        var seatId  = seatKeys[i];
        var seatUrl = lastSeatQrs[seatId];
        var card = document.createElement('div');
        card.style.cssText = 'text-align:center;background:#1a1a2e;border:1px solid #333;border-radius:8px;padding:10px;';
        var label = document.createElement('div');
        label.style.cssText = 'font-weight:700;font-size:15px;color:#90caf9;margin-bottom:6px;';
        label.textContent = seatId;
        var img = document.createElement('img');
        img.src    = seatUrl;
        img.width  = 160;
        img.height = 160;
        img.alt    = 'QR ' + seatId;
        img.style.display = 'block';
        card.appendChild(label);
        card.appendChild(img);
        grid.appendChild(card);
      }
      qrBox.appendChild(grid);
    } else if (lastQrUrl) {
      // Fallback: single order QR
      var img = document.createElement('img');
      img.src    = lastQrUrl;
      img.width  = 220;
      img.height = 220;
      img.alt    = 'QR Ticket #' + lastOrderId;
      img.style.display = 'block';
      qrBox.appendChild(img);
    } else {
      qrBox.innerHTML = '<p style="color:#999;font-size:13px;">QR no disponible</p>';
    }

    overlay.classList.add('open');
  }

  function initSuccessModal() {
    var closeBtn    = document.getElementById('bo-success-close');
    var downloadBtn = document.getElementById('bo-success-download');
    if (closeBtn)    { closeBtn.addEventListener('click', closeSuccessModal); }
    if (downloadBtn) { downloadBtn.addEventListener('click', downloadQR); }
  }

  function closeSuccessModal() {
    var overlay = document.getElementById('bo-success-modal');
    if (overlay) { overlay.classList.remove('open'); }
  }

  function downloadQR() {
    var seatKeys = Object.keys(lastSeatQrs);
    if (seatKeys.length > 0) {
      // Download each seat QR
      for (var i = 0; i < seatKeys.length; i++) {
        (function(seat, url) {
          var link = document.createElement('a');
          link.download = 'ticket-' + lastOrderId + '-' + seat + '.png';
          link.href = url;
          link.click();
        })(seatKeys[i], lastSeatQrs[seatKeys[i]]);
      }
    } else if (lastQrUrl) {
      var link = document.createElement('a');
      link.download = 'ticket-' + lastOrderId + '.png';
      link.href = lastQrUrl;
      link.click();
    }
  }

  // ═══════════════════════════════════════════════════════════════
  //  STATE REFRESH
  // ═══════════════════════════════════════════════════════════════

  function refreshState() {
    var fd = new FormData();
    fd.append('action', 'ss_boxoffice_get_state');
    fd.append('nonce', getNonce());
    fd.append('event_id', bo.eventId);
    fd.append('bo_user', bo.user);

    fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) return;

        soldSet     = arrayToSet(res.data.sold);
        reservedSet = arrayToSet(res.data.reserved);
        manualSet   = arrayToSet(res.data.manual_reserved);
        manualInfo  = res.data.manual_info || {};
        soldInfo    = res.data.sold_info   || {};

        // Update zone ticket available counts from inventory
        if (res.data.zone_inventory) {
          updateZoneAvailability(res.data.zone_inventory);
          updateStatsPanel(res.data.zone_inventory);
        }

        if (!seatLayer) return; // zone-only mode — no map to redraw

        // Remove conflicting selections
        for (var i = selectedSeats.length - 1; i >= 0; i--) {
          var id = selectedSeats[i];
          var conflict = false;
          if (currentMode === 'reservar' && (soldSet[id] || reservedSet[id] || manualSet[id])) conflict = true;
          if (currentMode === 'vender' && (soldSet[id] || reservedSet[id])) conflict = true;
          if (currentMode === 'liberar' && !manualSet[id]) conflict = true;
          if (conflict) { selectedSeats.splice(i, 1); }
        }

        // Redraw all seats
        var ids = Object.keys(seatNodes);
        for (var j = 0; j < ids.length; j++) {
          var seatId = ids[j];
          if (selectedSeats.indexOf(seatId) !== -1) {
            seatNodes[seatId].circle.fill(COLOR_SELECTED_FILL);
            seatNodes[seatId].circle.stroke(COLOR_SELECTED_STROKE);
            seatNodes[seatId].label.fill(COLOR_SELECTED_TEXT);
          } else {
            applySeatColor(seatId, seatNodes[seatId]);
          }
        }

        seatLayer.batchDraw();
        updateSelectionInfo();
        updateActionButton();
      })
      .catch(function () { /* silent */ });
  }

  // ═══════════════════════════════════════════════════════════════
  //  LOG PANEL
  // ═══════════════════════════════════════════════════════════════

  function refreshLog() {
    var fd = new FormData();
    fd.append('action', 'ss_boxoffice_get_log');
    fd.append('nonce', getNonce());
    fd.append('event_id', bo.eventId);
    fd.append('bo_user', bo.user);

    fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) return;
        var list = document.getElementById('bo-log-list');
        if (!list) return;

        var log = res.data.log || [];
        if (!log.length) {
          list.innerHTML = '<div style="padding:20px;text-align:center;color:#666;font-size:13px">Sin actividad</div>';
          return;
        }

        var html = '';
        var actionLabels = { reservar: 'reservó', vender: 'vendió', liberar: 'liberó', cancelar: 'canceló' };
        for (var i = 0; i < log.length; i++) {
          var entry = log[i];
          var cls   = 'bo-sidebar__item bo-sidebar__item--' + entry.accion;
          var label = actionLabels[entry.accion] || entry.accion;
          var time  = entry.created_at ? entry.created_at.substring(11, 16) : '';
          var orderNote = entry.order_id ? ' (Pedido #' + entry.order_id + ')' : '';

          html += '<div class="' + cls + '">'
                + '<span class="bo-sidebar__time">' + time + '</span> '
                + '<span class="bo-sidebar__user">' + escHtml(entry.usuario) + '</span> '
                + label + ' '
                + '<span class="bo-sidebar__seats">' + escHtml(entry.asientos) + '</span>'
                + orderNote
                + '</div>';
        }
        list.innerHTML = html;
      })
      .catch(function () { /* silent */ });
  }

  // ═══════════════════════════════════════════════════════════════
  //  TOAST
  // ═══════════════════════════════════════════════════════════════

  function showToast(msg, type) {
    var container = document.getElementById('bo-toast');
    if (!container) return;

    var el = document.createElement('div');
    el.className = 'bo-toast__msg bo-toast__msg--' + (type || 'success');
    el.textContent = msg;
    container.appendChild(el);

    setTimeout(function () {
      el.style.opacity = '0';
      el.style.transition = 'opacity .3s';
      setTimeout(function () { el.remove(); }, 300);
    }, 4000);
  }

  function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // Tooltip simple para nombre de reserva
  var _tooltip = null;
  function showSeatTooltip(nombre, konvaNode) {
    hideSeatTooltip();
    _tooltip = document.createElement('div');
    _tooltip.className = 'bo-seat-tooltip';
    _tooltip.textContent = nombre;
    document.body.appendChild(_tooltip);
    document.addEventListener('mousemove', _moveSeatTooltip);
  }
  function _moveSeatTooltip(e) {
    if (!_tooltip) return;
    _tooltip.style.left = (e.clientX + 12) + 'px';
    _tooltip.style.top  = (e.clientY - 8) + 'px';
  }
  function hideSeatTooltip() {
    if (_tooltip) { _tooltip.remove(); _tooltip = null; }
    document.removeEventListener('mousemove', _moveSeatTooltip);
  }

  // ═══════════════════════════════════════════════════════════════
  //  CONFIG NORMALISATION (copied from ss-konva-renderer.js)
  // ═══════════════════════════════════════════════════════════════

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
        cfg.rows.push({
          label:        src.label || '',
          count:        num(src.count, 0),
          y:            num(src.y, 0),
          zone:         src.zone || 'GENERAL',
          gaps:         normalizeGaps(src.gaps),
          removedSeats: normalizeRemovedSeats(src.removedSeats),
          reverse:      !!src.reverse,
          renumber:     !!src.renumber
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

  // ═══════════════════════════════════════════════════════════════
  //  DRAWING HELPERS (copied from ss-konva-renderer.js)
  // ═══════════════════════════════════════════════════════════════

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
    for (var i = 0; i < arr.length; i++) {
      s[String(arr[i]).trim()] = true;
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

  function drawZoneRect(layer, zr, zcm) {
    var color = zcm[zr.id] || '#4a90d9';
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

  function drawSeat(group, seat) {
    var baseColor = zoneColorMap[seat.zone] || '#4a90d9';
    var isSold     = soldSet[seat.id]     === true;
    var isReserved = reservedSet[seat.id] === true;
    var isManual   = manualSet[seat.id]   === true;

    var fillColor, strokeColor, textColor;

    if (isSold) {
      fillColor = COLOR_SOLD_FILL; strokeColor = COLOR_SOLD_STROKE; textColor = COLOR_SOLD_TEXT;
    } else if (isReserved) {
      fillColor = COLOR_RESV_FILL; strokeColor = COLOR_RESV_STROKE; textColor = COLOR_RESV_TEXT;
    } else if (isManual) {
      fillColor = COLOR_MANUAL_FILL; strokeColor = COLOR_MANUAL_STROKE; textColor = COLOR_MANUAL_TEXT;
    } else {
      fillColor = baseColor; strokeColor = 'black'; textColor = 'white';
    }

    var circle = new Konva.Circle({
      x:           seat.x,
      y:           seat.y,
      radius:      SEAT_RADIUS,
      fill:        fillColor,
      stroke:      strokeColor,
      strokeWidth: 1,
      listening:   true,
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
      listening: true,
      seatId:    seat.id,
    });

    // Pointer cursor + tooltip para sillas con reserva manual
    if (saleMode !== 'zone') {
      var sId = seat.id;
      circle.on('mouseenter', function () {
        circle.getStage().container().style.cursor = 'pointer';
        if (manualSet[sId] && manualInfo[sId]) { showSeatTooltip(manualInfo[sId], circle); }
        else if (soldSet[sId] && soldInfo[sId]) { showSeatTooltip(soldInfo[sId], circle); }
      });
      circle.on('mouseleave', function () {
        circle.getStage().container().style.cursor = '';
        hideSeatTooltip();
      });
      label.on('mouseenter', function () {
        label.getStage().container().style.cursor = 'pointer';
        if (manualSet[sId] && manualInfo[sId]) { showSeatTooltip(manualInfo[sId], label); }
        else if (soldSet[sId] && soldInfo[sId]) { showSeatTooltip(soldInfo[sId], label); }
      });
      label.on('mouseleave', function () {
        label.getStage().container().style.cursor = '';
        hideSeatTooltip();
      });
    }

    group.add(circle);
    group.add(label);

    return { circle: circle, label: label, zone: seat.zone, baseColor: baseColor };
  }

  // ═══════════════════════════════════════════════════════════════
  //  VIEWPORT (copied from ss-konva-renderer.js)
  // ═══════════════════════════════════════════════════════════════

  function fitStageToContent(stg, seatList, config) {
    if (!seatList.length) return;

    var minX =  Infinity, maxX = -Infinity;
    var minY =  Infinity, maxY = -Infinity;

    for (var i = 0; i < seatList.length; i++) {
      var s = seatList[i];
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

    var contentW = maxX - minX;
    var contentH = maxY - minY;
    var padding  = 30;
    var stageW   = stg.width();
    var stageH   = stg.height();

    var scaleX   = (stageW - padding * 2) / contentW;
    var scaleY   = (stageH - padding * 2) / contentH;
    var newScale  = Math.min(scaleX, scaleY);
    newScale      = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

    stg.scale({ x: newScale, y: newScale });

    var centerX = (minX + maxX) / 2;
    var centerY = (minY + maxY) / 2;
    stg.position({
      x: stageW / 2 - centerX * newScale,
      y: stageH / 2 - centerY * newScale,
    });

    stg.batchDraw();
  }

  function initViewport(stg) {
    // Zoom on wheel
    stg.container().addEventListener('wheel', function (e) {
      e.preventDefault();
      var pointer = stg.getPointerPosition();
      if (!pointer) return;

      var oldScale = stg.scaleX();
      var mousePointTo = {
        x: (pointer.x - stg.x()) / oldScale,
        y: (pointer.y - stg.y()) / oldScale,
      };

      var newScale = e.deltaY > 0
        ? oldScale / ZOOM_STEP
        : oldScale * ZOOM_STEP;
      newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

      stg.scale({ x: newScale, y: newScale });
      stg.position({
        x: pointer.x - mousePointTo.x * newScale,
        y: pointer.y - mousePointTo.y * newScale,
      });
      stg.batchDraw();
    }, { passive: false });

    // Pan via drag
    var isPanning = false;
    var lastPos   = { x: 0, y: 0 };

    stg.on('mousedown touchstart', function (e) {
      if (e.target !== stg) return;
      isPanning = true;
      var pos = e.evt.touches ? e.evt.touches[0] : e.evt;
      lastPos = { x: pos.clientX, y: pos.clientY };
      stg.container().style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', onMove);
    window.addEventListener('touchmove', onMove, { passive: false });

    function onMove(e) {
      if (!isPanning) return;
      if (e.touches) e.preventDefault();
      var pos = e.touches ? e.touches[0] : e;
      var dx = pos.clientX - lastPos.x;
      var dy = pos.clientY - lastPos.y;
      lastPos = { x: pos.clientX, y: pos.clientY };
      stg.x(stg.x() + dx);
      stg.y(stg.y() + dy);
      stg.batchDraw();
    }

    window.addEventListener('mouseup',  endPan);
    window.addEventListener('touchend', endPan);

    function endPan() {
      if (!isPanning) return;
      isPanning = false;
      stg.container().style.cursor = '';
    }

    // Touch pinch zoom
    var lastDist = 0;
    stg.container().addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        lastDist = getTouchDist(e.touches);
      }
    }, { passive: false });

    stg.container().addEventListener('touchmove', function (e) {
      if (e.touches.length !== 2) return;
      e.preventDefault();
      var dist = getTouchDist(e.touches);
      if (lastDist === 0) { lastDist = dist; return; }

      var oldScale = stg.scaleX();
      var newScale = oldScale * (dist / lastDist);
      newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

      var cx = (e.touches[0].clientX + e.touches[1].clientX) / 2;
      var cy = (e.touches[0].clientY + e.touches[1].clientY) / 2;
      var rect = stg.container().getBoundingClientRect();
      var pointer = { x: cx - rect.left, y: cy - rect.top };
      var mousePointTo = {
        x: (pointer.x - stg.x()) / oldScale,
        y: (pointer.y - stg.y()) / oldScale,
      };

      stg.scale({ x: newScale, y: newScale });
      stg.position({
        x: pointer.x - mousePointTo.x * newScale,
        y: pointer.y - mousePointTo.y * newScale,
      });
      stg.batchDraw();
      lastDist = dist;
    }, { passive: false });

    function getTouchDist(touches) {
      var dx = touches[0].clientX - touches[1].clientX;
      var dy = touches[0].clientY - touches[1].clientY;
      return Math.sqrt(dx * dx + dy * dy);
    }
  }

  // ═══════════════════════════════════════════════════════════════
  //  SIDEBAR TABS
  // ═══════════════════════════════════════════════════════════════

  function initSidebarTabs() {
    var tabs = document.querySelectorAll('.bo-sidebar__tab');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var panelId = tab.getAttribute('data-panel');
        tabs.forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        // Hide all panels, show target
        document.querySelectorAll('.bo-sidebar__list').forEach(function (p) {
          p.style.display = 'none';
        });
        var panel = document.getElementById(panelId);
        if (panel) panel.style.display = '';
      });
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  ORDERS LIST
  // ═══════════════════════════════════════════════════════════════

  function refreshOrders() {
    var fd = new FormData();
    fd.append('action', 'ss_boxoffice_get_orders');
    fd.append('nonce', getNonce());
    fd.append('event_id', bo.eventId);
    fd.append('bo_user', bo.user);

    fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) return;
        var list = document.getElementById('bo-orders-list');
        if (!list) return;

        var orders = res.data.orders || [];
        if (!orders.length) {
          list.innerHTML = '<div style="padding:20px;text-align:center;color:#666;font-size:13px">Sin pedidos activos</div>';
          return;
        }

        ordersCache = {};
        var html = '';
        for (var i = 0; i < orders.length; i++) {
          var o = orders[i];
          ordersCache[o.id] = o;
          var seatsStr = o.seats && o.seats.length ? o.seats.join(', ') : '';
          var zonesStr = o.zones && o.zones.length ? o.zones.join(', ') : '';

          html += '<div class="bo-order">';
          html += '<div class="bo-order__header">';
          html += '<span class="bo-order__id">#' + o.id + '</span>';
          html += '<span class="bo-order__status">' + escHtml(o.status) + '</span>';
          html += '</div>';
          html += '<div class="bo-order__customer">' + escHtml(o.customer) + '</div>';
          html += '<div class="bo-order__detail">' + escHtml(o.date) + ' · ' + escHtml(o.method) + '</div>';
          if (o.total > 0) {
            html += '<div class="bo-order__detail">Total: $' + parseFloat(o.total).toLocaleString() + ' ' + o.currency + '</div>';
          }
          if (seatsStr) {
            html += '<div class="bo-order__seats">Sillas: ' + escHtml(seatsStr) + '</div>';
          }
          if (zonesStr) {
            html += '<div class="bo-order__zones">Zona: ' + escHtml(zonesStr) + '</div>';
          }
          html += '<div class="bo-order__actions">';
          if (o.qr_url) {
            html += '<button type="button" class="bo-order__qr-btn" data-order-id="' + o.id + '" data-qr="' + escHtml(o.qr_url) + '">⬇ QR</button>';
          }
          html += '<button type="button" class="bo-order__cancel" data-order-id="' + o.id + '">Cancelar pedido</button>';
          html += '</div>';
          html += '</div>';
        }
        list.innerHTML = html;

        // Bind QR view buttons — abre el modal de éxito con datos del pedido
        list.querySelectorAll('.bo-order__qr-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var orderId = parseInt(btn.getAttribute('data-order-id'), 10);
            var o = ordersCache[orderId];
            if (!o || !o.qr_url) return;
            showSuccessModal({
              order_id:  o.id,
              qr_url:    o.qr_url,
              seat_qrs:  {},
              nombre:    o.customer,
              seats:     o.seats || [],
              zones:     o.zones || [],
            });
          });
        });

        // Bind cancel buttons
        list.querySelectorAll('.bo-order__cancel').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var orderId = btn.getAttribute('data-order-id');
            doCancelOrder(orderId, btn);
          });
        });
      })
      .catch(function () { /* silent */ });
  }

  function doCancelOrder(orderId, btn) {
    if (!confirm('¿Cancelar y reembolsar el pedido #' + orderId + '?\n\nEsta acción libera las sillas y no se puede deshacer.')) {
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Cancelando...';

    var fd = new FormData();
    fd.append('action', 'ss_boxoffice_cancel_order');
    fd.append('nonce', getNonce());
    fd.append('event_id', bo.eventId);
    fd.append('order_id', orderId);
    fd.append('bo_user', bo.user);

    fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          showToast(res.data.message, 'success');
          refreshOrders();
          refreshState();
          refreshLog();
        } else {
          showToast(res.data || 'Error al cancelar', 'error');
          btn.disabled = false;
          btn.textContent = 'Cancelar pedido';
        }
      })
      .catch(function () {
        showToast('Error de conexión', 'error');
        btn.disabled = false;
        btn.textContent = 'Cancelar pedido';
      });
  }

  // ═══════════════════════════════════════════════════════════════
  //  TRANSFER PANEL
  // ═══════════════════════════════════════════════════════════════

  function initTransferPanel() {
    var searchBtn  = document.getElementById('bo-transfer-search-btn');
    var confirmBtn = document.getElementById('bo-transfer-confirm-btn');
    if (!searchBtn || !confirmBtn) return;

    var currentOrderId = 0;

    searchBtn.addEventListener('click', function () {
      var orderId = parseInt(document.getElementById('bo-transfer-order-input').value, 10);
      if (!orderId) { showToast('Ingresa un número de pedido', 'error'); return; }

      searchBtn.disabled = true;
      searchBtn.textContent = '...';

      var fd = new FormData();
      fd.append('action', 'ss_boxoffice_get_order');
      fd.append('nonce', getNonce());
      fd.append('event_id', bo.eventId);
      fd.append('bo_user', bo.user);
      fd.append('order_id', orderId);

      fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          searchBtn.disabled = false;
          searchBtn.textContent = 'Buscar';
          if (!res.success) { showToast(res.data || 'No encontrado', 'error'); return; }

          var d = res.data;
          currentOrderId = d.order_id;

          document.getElementById('bo-transfer-nombre').textContent = d.nombre + ' (' + d.email + ')';
          document.getElementById('bo-transfer-seats-row').textContent = 'Sillas: ' + (d.seats || []).join(', ');
          document.getElementById('bo-transfer-event-row').textContent = 'Evento: ' + d.event_title + ' (#' + d.event_id + ')';

          // Llenar sugerencia de sillas destino con las mismas del origen
          document.getElementById('bo-transfer-dest-seats').value = (d.seats || []).join(', ');

          // Llenar dropdown de eventos hermanos
          var sel = document.getElementById('bo-transfer-dest-event');
          sel.innerHTML = '<option value="">— Seleccionar —</option>';
          (d.sibling_events || []).forEach(function (ev) {
            var opt = document.createElement('option');
            opt.value = ev.id;
            opt.textContent = ev.title + ' (#' + ev.id + ')';
            sel.appendChild(opt);
          });

          document.getElementById('bo-transfer-info').style.display = '';
        })
        .catch(function () {
          searchBtn.disabled = false;
          searchBtn.textContent = 'Buscar';
          showToast('Error de conexión', 'error');
        });
    });

    confirmBtn.addEventListener('click', function () {
      var destEventId = document.getElementById('bo-transfer-dest-event').value;
      var destSeats   = document.getElementById('bo-transfer-dest-seats').value.trim();

      if (!currentOrderId) { showToast('Busca un pedido primero', 'error'); return; }
      if (!destEventId)    { showToast('Selecciona el evento destino', 'error'); return; }
      if (!destSeats)      { showToast('Indica las sillas destino', 'error'); return; }

      if (!confirm('¿Confirmar traslado del pedido #' + currentOrderId + ' al evento #' + destEventId + '?')) return;

      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Trasladando...';

      var fd = new FormData();
      fd.append('action', 'ss_boxoffice_transfer');
      fd.append('nonce', getNonce());
      fd.append('event_id', bo.eventId);
      fd.append('bo_user', bo.user);
      fd.append('order_id', currentOrderId);
      fd.append('dest_event_id', destEventId);
      fd.append('dest_seats', destSeats);

      fetch(bo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Confirmar traslado';
          if (res.success) {
            showToast(res.data.message, 'success');
            // Limpiar panel
            document.getElementById('bo-transfer-order-input').value = '';
            document.getElementById('bo-transfer-info').style.display = 'none';
            currentOrderId = 0;
            refreshState();
            refreshLog();
            refreshOrders();
          } else {
            showToast(res.data || 'Error al trasladar', 'error');
          }
        })
        .catch(function () {
          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Confirmar traslado';
          showToast('Error de conexión', 'error');
        });
    });

    // Permitir buscar con Enter
    document.getElementById('bo-transfer-order-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { searchBtn.click(); }
    });
  }

})();
