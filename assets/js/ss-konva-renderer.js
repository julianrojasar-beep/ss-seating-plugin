// ═══════════════════════════════════════════════════════════════════
//  SS Konva Renderer — Frontend seat map with sales integration
//  Visually identical to the builder (main.js).
//  Depends on: konva.min.js, seat-engine.js (window.SeatEngine)
//  Data from PHP: window.ssLayoutData, window.ssSeatingState
// ═══════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  // ─── Constants (must match builder) ──────────────────────────────
  // SEAT_RADIUS sigue siendo la unidad de referencia para spacing/bounding-box
  // (no se tocan coordenadas guardadas) — SEAT_W/SEAT_H son el tamaño real del
  // rectángulo dibujado, derivado de ella.
  var SEAT_RADIUS   = 15;
  var SEAT_W        = SEAT_RADIUS * 2.13; // 32
  var SEAT_H        = SEAT_RADIUS * 1.73; // 26
  var SEAT_CORNER   = 6;
  var ZOOM_MIN    = 0.3;
  var ZOOM_MAX    = 3;
  var ZOOM_STEP   = 1.08;

  // Por debajo de esta escala de stage, los números dejan de leerse — se
  // ocultan (el rectángulo de color sigue visible, el estado se sigue viendo).
  // 0.7 y no menos: con fontSize base 10, por debajo de eso el número renderiza
  // a menos de ~7px en pantalla — técnicamente "visible" pero ya ilegible.
  var LABEL_VISIBILITY_SCALE_THRESHOLD = 0.7;

  // Texto blanco u oscuro según el brillo del fill — el fill de cada estado es
  // configurable (SS_Settings), así que el texto NO puede ser un hex fijo: un
  // sitio con un color de "Vendida" oscuro guardado de antes (ej. #2e2e2e)
  // dejaría el número ilegible con un texto oscuro fijo. Mismo criterio que
  // _contrastText() en ss-builder-main.js y contrastColor() en class-ss-settings.php.
  function contrastText(hex) {
    hex = String(hex || '').replace('#', '');
    if (hex.length === 3) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
    var r = parseInt(hex.substr(0, 2), 16) || 0;
    var g = parseInt(hex.substr(2, 2), 16) || 0;
    var b = parseInt(hex.substr(4, 2), 16) || 0;
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? '#1E293B' : '#FFFFFF';
  }

  // ─── Paleta de ESTADO, centralizada — configurable en SS Seating →
  // Configuración → Estilo (SS_Settings), con estos hex como fallback ────────
  var _fc = (window.ssSeatingAjax && window.ssSeatingAjax.colors) || {};
  var SEAT_COLORS = (function () {
    var available = _fc.available || '#64748B';
    var sold      = _fc.sold      || '#F1F5F9';
    var reserved  = _fc.reserved  || '#F59E0B';
    var selected  = _fc.selected  || '#8B5CF6';
    return {
      available: { fill: available, text: contrastText(available), stroke: 'rgba(0,0,0,.25)' },
      sold:      { fill: sold,      text: contrastText(sold),      stroke: 'rgba(0,0,0,.25)' },
      reserved:  { fill: reserved,  text: contrastText(reserved),  stroke: 'rgba(0,0,0,.25)' },
      selected:  { fill: selected,  text: contrastText(selected),  stroke: '#E9D5FF' },
    };
  }());

  // ─── Selection state (global within this IIFE) ───────────────────
  var selectedSeats  = [];   // flat list of seat codes, e.g. ["A1","B3"]
  var selectedByType = {};   // { "VIP": ["A1"], "GENERAL": ["B3"] }

  // Si el usuario ya interactuó con el mapa (zoom/pan), el wiggle de entrada
  // no debe pelearse con eso — ver playIntroWiggle().
  var _userInteractedWithMap = false;

  // ─── Wait for DOM ────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', init);

  // ─── Floor state ────────────────────────────────────────────────
  var _rawLayout    = null;
  var _activeFloor  = 0;
  var _currentStage = null;
  var _currentSeats = null;
  var _currentConfig = null;
  var _currentUpdateLabelVisibility = null; // seteado en _bindMapInteraction

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

  // Botones de zoom/centrar — viven en el PHP, fuera de #ss-konva-container,
  // así que sobreviven a los rebuilds del mapa (cambio de piso). Actúan sobre
  // _currentStage leído al momento del clic, no capturado al bindear.
  function _bindMapControls() {
    var zoomInBtn  = document.getElementById('ss-map-zoom-in');
    var zoomOutBtn = document.getElementById('ss-map-zoom-out');
    var fitBtn     = document.getElementById('ss-map-zoom-fit');
    var hint       = document.getElementById('ss-map-nav-hint');

    function dismissNavHint() {
      if (hint) { hint.classList.add('is-hidden'); }
    }

    if (zoomInBtn)  { zoomInBtn.addEventListener('click', function () { zoomStageBy(_currentStage, 1.3); dismissNavHint(); }); }
    if (zoomOutBtn) { zoomOutBtn.addEventListener('click', function () { zoomStageBy(_currentStage, 1 / 1.3); dismissNavHint(); }); }
    if (fitBtn) {
      fitBtn.addEventListener('click', function () {
        if (_currentStage && _currentSeats && _currentConfig) {
          fitStageToContent(_currentStage, _currentSeats, _currentConfig, true);
          if (_currentUpdateLabelVisibility) { _currentUpdateLabelVisibility(_currentStage.scaleX()); }
        }
        dismissNavHint();
      });
    }

    // Cualquier interacción real con el mapa también apaga el hint — mismo
    // patrón que el de "Elige tu silla".
    if (hint) {
      var mapContainer = document.getElementById('ss-konva-container');
      if (mapContainer) {
        mapContainer.addEventListener('mousedown',  dismissNavHint, { once: true });
        mapContainer.addEventListener('touchstart', dismissNavHint, { once: true, passive: true });
        mapContainer.addEventListener('wheel',      dismissNavHint, { once: true, passive: true });
      }
    }
  }

  // Zoom-in-y-vuelta breve al cargar: comunica visualmente que el mapa es
  // interactivo (acercable/movible), sin depender de que alguien lea el hint.
  function playIntroWiggle(stage) {
    if (typeof Konva.Tween === 'undefined') return;
    // Si el usuario ya tocó zoom/pan antes de que esto arranque, no hacer nada
    // — de lo contrario la vuelta del wiggle le pisa el zoom que acaba de hacer.
    if (_userInteractedWithMap) return;

    var baseScale = stage.scaleX();
    var baseX = stage.x(), baseY = stage.y();
    var pulseScale = baseScale * 1.06;
    var cx = stage.width() / 2, cy = stage.height() / 2;
    var mousePointTo = { x: (cx - baseX) / baseScale, y: (cy - baseY) / baseScale };

    new Konva.Tween({
      node: stage,
      duration: 0.4,
      scaleX: pulseScale,
      scaleY: pulseScale,
      x: cx - mousePointTo.x * pulseScale,
      y: cy - mousePointTo.y * pulseScale,
      easing: Konva.Easings.EaseInOut,
      onFinish: function () {
        // Mismo chequeo acá: si el usuario interactuó MIENTRAS el wiggle
        // estaba a mitad de camino, no volver a la posición vieja — dejar el
        // mapa donde el usuario lo puso.
        if (_userInteractedWithMap) return;
        new Konva.Tween({
          node: stage,
          duration: 0.4,
          scaleX: baseScale,
          scaleY: baseScale,
          x: baseX,
          y: baseY,
          easing: Konva.Easings.EaseInOut,
        }).play();
      },
    }).play();
  }

  function zoomStageBy(stage, factor) {
    if (!stage) return;
    _userInteractedWithMap = true;
    var oldScale = stage.scaleX();
    var newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, oldScale * factor));
    var cx = stage.width() / 2, cy = stage.height() / 2;
    var mousePointTo = {
      x: (cx - stage.x()) / oldScale,
      y: (cy - stage.y()) / oldScale,
    };
    stage.scale({ x: newScale, y: newScale });
    stage.position({
      x: cx - mousePointTo.x * newScale,
      y: cy - mousePointTo.y * newScale,
    });
    stage.batchDraw();
    if (_currentUpdateLabelVisibility) { _currentUpdateLabelVisibility(newScale); }
  }

  function _buildMap(container) {
    _userInteractedWithMap = false; // mapa fresco (carga o cambio de piso) → wiggle puede jugar de nuevo
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
    _currentSeats  = seats;
    _currentConfig = config;

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

    drawFloorLabelBoxes(zoneLayer, config.rows || [], seats);
    zoneLayer.batchDraw();

    var soldSet     = arrayToSet(state.sold);
    var reservedSet = arrayToSet(state.reserved);
    var seatNodes   = {};
    var labelNodes  = [];
    var seatsGroup  = new Konva.Group();

    for (var i = 0; i < seats.length; i++) {
      var seatData = seats[i];
      var node = drawSeat(seatsGroup, seatData, zoneColorMap, soldSet, reservedSet, saleMode);
      seatNodes[seatData.id] = node;
      labelNodes.push(node.label);
    }
    seatLayer.add(seatsGroup);
    seatLayer.batchDraw();

    _bindMapInteraction(container, stage, seatLayer, seatNodes, seats, config, zoneColorMap, soldSet, reservedSet, saleMode, labelNodes);
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
    _bindMapControls();
  }

  function _bindMapInteraction(container, stage, seatLayer, seatNodes, seats, config, zoneColorMap, soldSet, reservedSet, saleMode, labelNodes) {
    // Legibilidad progresiva: oculta los números por debajo de cierto zoom (el
    // rectángulo de color sigue visible — el estado se sigue viendo igual).
    function updateLabelVisibility(scale) {
      var visible = scale >= LABEL_VISIBILITY_SCALE_THRESHOLD;
      for (var li = 0; li < labelNodes.length; li++) {
        labelNodes[li].visible(visible);
      }
      seatLayer.batchDraw();
    }
    _currentUpdateLabelVisibility = updateLabelVisibility;

    // NOTA: stage/seatLayer/seatNodes/seats/zoneColorMap/soldSet/reservedSet ya fueron
    // construidos y dibujados por _buildMap(); esta función solo ata la interacción
    // (antes duplicaba la construcción completa del stage — ver historial de git).

    // ── DOM references for summary + hidden input ──────────────────
    var root             = container.closest('.ss-seating');
    var summaryContainer = root ? root.querySelector('.ss-seating__summary') : null;
    var summaryList      = root ? root.querySelector('.ss-seating__summary-list') : null;
    var hiddenInput      = document.getElementById('ss_seats_input');

    // ── Tooltip de zona: hover (PC) / tap (touch) sobre una silla ──────
    // Vive en document.body con position:fixed (no dentro de #ss-konva-container,
    // que un contenedor padre recorta con overflow:hidden cerca de los bordes).
    var tooltipEl = document.querySelector('.ss-seat-tooltip');
    if (!tooltipEl) {
      tooltipEl = document.createElement('div');
      tooltipEl.className = 'ss-seat-tooltip';
      document.body.appendChild(tooltipEl);
    }
    var tooltipHideTimer = null;

    function showSeatTooltip(node) {
      if (!node.zone) return;
      // Sin relativeTo: así el rect ya viene con el pan/zoom actual del stage
      // aplicado (coordenadas reales en pantalla). Con relativeTo:stage el
      // propio zoom/paneo del stage queda excluido del cálculo — por eso el
      // tooltip aparecía lejos en cuanto el mapa no estaba a escala 1:1.
      var seatRect  = node.circle.getClientRect();
      var hostRect  = container.getBoundingClientRect();
      tooltipEl.textContent = node.zone;
      // Debajo de la silla (no arriba): evita que se recorte cerca del borde
      // superior del mapa.
      tooltipEl.style.left = (hostRect.left + seatRect.x + seatRect.width / 2) + 'px';
      tooltipEl.style.top  = (hostRect.top + seatRect.y + seatRect.height) + 'px';
      tooltipEl.classList.add('is-visible');
      clearTimeout(tooltipHideTimer);
    }
    function hideSeatTooltip(delay) {
      clearTimeout(tooltipHideTimer);
      tooltipHideTimer = setTimeout(function () {
        tooltipEl.classList.remove('is-visible');
      }, delay || 0);
    }

    Object.keys(seatNodes).forEach(function (seatIdKey) {
      if (soldSet[seatIdKey] || reservedSet[seatIdKey]) return; // sin tooltip en no-interactivas
      var n = seatNodes[seatIdKey];
      n.circle.on('mouseenter.ssTooltip', function () { showSeatTooltip(n); });
      n.circle.on('mouseleave.ssTooltip', function () { hideSeatTooltip(); });
      n.label.on('mouseenter.ssTooltip', function () { showSeatTooltip(n); });
      n.label.on('mouseleave.ssTooltip', function () { hideSeatTooltip(); });
    });

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

      // Evento ya finalizado: no se puede seleccionar nada, sin importar el modo
      if (window.ssSeatingAjax && window.ssSeatingAjax.isPast) return;

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

      // Touch no tiene hover — mostrar el tooltip un momento al tocar/seleccionar.
      showSeatTooltip(node);
      hideSeatTooltip(1400);

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
    updateLabelVisibility(stage.scaleX());

    // Wiggle de entrada: un leve zoom-in-y-vuelta para dejar claro que el mapa
    // se puede mover/acercar (antes nadie lo notaba, el hint de texto no alcanzaba).
    if (saleMode !== 'zone') {
      setTimeout(function () { playIntroWiggle(stage); }, 500);
    }

    // ── Viewport controls (zoom + pan) — deshabilitado en modo zone ──
    if (saleMode !== 'zone') {
      initViewport(stage, updateLabelVisibility);
    }

    // ── Responsive resize ──────────────────────────────────────────
    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        stage.width(container.clientWidth || 800);
        stage.height(container.clientHeight || 400);
        fitStageToContent(stage, seats, config);
        updateLabelVisibility(stage.scaleX());
      }, 150);
    });

    // Hint inicial "Elige tu silla": solo modo seat, solo si no hay nada vendido/reservado
    // bloqueando todo (si no hay sillas disponibles no tiene sentido sugerir nada).
    startSeatHint(seats, soldSet, reservedSet, saleMode, seatLayer, container, config);

    // Signal that the map finished rendering
    document.dispatchEvent(new CustomEvent('ss:konva-ready'));
  }

  // ═══════════════════════════════════════════════════════════════════
  //  HINT INICIAL — 2 sillas contiguas cerca del escenario titilando +
  //  banner "Elige tu silla" (solo modo seat; se apaga sola al primer
  //  clic/touch/scroll sobre el mapa, sin límite de tiempo)
  // ═══════════════════════════════════════════════════════════════════

  /**
   * Busca, entre las sillas disponibles, el par "contiguo" (sin otra silla
   * vendida/reservada en medio — distancia ~= 1 espaciado de silla) más cercano
   * al escenario. Orden lexicográfico, NO distancia euclidiana combinada: la
   * fila (eje Y) manda siempre primero — con un promedio de distancia (dx²+dy²)
   * una pareja 2 filas más atrás pero más centrada en X terminaba ganándole a
   * una pareja en la fila justo detrás del escenario, que es lo que de verdad
   * importa. El centrado en X (refX) solo desempata entre parejas de la MISMA fila.
   * Si ninguna pareja está así de junta, devuelve null (el llamador cae al
   * fallback de las 2 disponibles más cercanas, aunque no estén juntas).
   */
  function findClosestAdjacentPair(available, refX, spacing) {
    // 1.5x el espaciado real del layout (no un valor fijo): cada evento define su
    // propio spacing entre sillas, y un valor fijo quedaba corto en mapas con
    // espaciado más generoso, haciendo que NUNCA se detectara ninguna pareja junta.
    var THRESHOLD = (spacing || SEAT_RADIUS * 3) * 1.5;
    var candidates = [];

    for (var i = 0; i < available.length; i++) {
      for (var j = i + 1; j < available.length; j++) {
        var a = available[i], b = available[j];
        var dx = a.x - b.x, dy = a.y - b.y;
        var pairDist = Math.sqrt(dx * dx + dy * dy);
        if (pairDist > THRESHOLD) continue;

        candidates.push({
          pair: [a, b],
          midX: (a.x + b.x) / 2,
          midY: (a.y + b.y) / 2,
        });
      }
    }
    if (!candidates.length) return null;

    candidates.sort(function (p, q) {
      if (p.midY !== q.midY) return p.midY - q.midY; // fila más cerca del escenario primero
      return Math.abs(p.midX - refX) - Math.abs(q.midX - refX); // misma fila: más centrada
    });
    return candidates[0].pair;
  }

  function startSeatHint(seats, soldSet, reservedSet, saleMode, seatLayer, container, config) {
    if (saleMode !== 'seat') return;
    if (window.ssSeatingAjax && window.ssSeatingAjax.isPast) return;

    var available = seats.filter(function (s) {
      return !soldSet[s.id] && !reservedSet[s.id];
    });
    if (available.length < 1) return;

    // Punto de referencia horizontal (centrado): el centro del rect del escenario
    // si el layout lo tiene; si no, el centro geométrico de TODAS las sillas del
    // mapa (vendidas incluidas) — usar solo las disponibles sesga el centro hacia
    // donde queden huecos sueltos, en vez del centro real del venue.
    var refX;
    if (config.layout && config.layout.stage) {
      var esc = config.layout.stage;
      refX = esc.x + (esc.width || 0) / 2;
    } else {
      refX = seats.reduce(function (sum, s) { return sum + s.x; }, 0) / seats.length;
    }

    // Preferencia 1: la pareja de sillas contiguas (sin huecos entre ellas) más
    // cercana al escenario (fila más al frente, y centrada dentro de esa fila).
    // Preferencia 2 (fallback): si no quedan 2 juntas en ningún punto del mapa,
    // la silla disponible más al frente + la disponible más cercana a ella.
    var adjacentPair = findClosestAdjacentPair(available, refX, config.spacing);
    var hinted;
    if (adjacentPair) {
      hinted = adjacentPair;
    } else {
      available.sort(function (a, b) {
        if (a.y !== b.y) return a.y - b.y;
        return Math.abs(a.x - refX) - Math.abs(b.x - refX);
      });
      var anchor = available[0];
      var rest = available.slice(1).sort(function (a, b) {
        var da = Math.pow(a.x - anchor.x, 2) + Math.pow(a.y - anchor.y, 2);
        var db = Math.pow(b.x - anchor.x, 2) + Math.pow(b.y - anchor.y, 2);
        return da - db;
      });
      hinted = rest.length ? [anchor, rest[0]] : [anchor];
    }

    var rings = hinted.map(function (seat) {
      var ring = new Konva.Rect({
        x: seat.x - SEAT_W / 2 - 4, y: seat.y - SEAT_H / 2 - 4,
        width: SEAT_W + 8, height: SEAT_H + 8,
        cornerRadius: SEAT_CORNER + 2,
        stroke: '#ffffff',
        strokeWidth: 2,
        opacity: 0,
        listening: false,
      });
      ring.setAttr('cx', seat.x);
      ring.setAttr('cy', seat.y);
      seatLayer.add(ring);
      return ring;
    });
    seatLayer.batchDraw();

    var anim = new Konva.Animation(function (frame) {
      var cycle = (frame.time % 1400) / 1400;
      var wave  = Math.sin(cycle * Math.PI); // 0 → 1 → 0
      var grow  = 4 + wave * 5;
      rings.forEach(function (r) {
        r.opacity(0.15 + wave * 0.55);
        r.x(r.getAttr('cx') - SEAT_W / 2 - grow);
        r.y(r.getAttr('cy') - SEAT_H / 2 - grow);
        r.width(SEAT_W + grow * 2);
        r.height(SEAT_H + grow * 2);
      });
    }, seatLayer);
    anim.start();
    showSeatHintBanner();

    var stopped = false;
    function stop() {
      if (stopped) return;
      stopped = true;
      anim.stop();
      rings.forEach(function (r) { r.destroy(); });
      seatLayer.batchDraw();
      hideSeatHintBanner();
    }

    // Cualquier interacción real con el mapa (clic, touch, pan, zoom) lo apaga —
    // a nivel del contenedor DOM, no solo del click en una silla.
    container.addEventListener('mousedown', stop, { once: true });
    container.addEventListener('touchstart', stop, { once: true, passive: true });
    container.addEventListener('wheel', stop, { once: true, passive: true });
  }

  function showSeatHintBanner() {
    if (document.getElementById('ss-seat-hint-banner')) return;
    var mapContainer = document.querySelector('.seat-map-container');
    if (!mapContainer) return;
    var banner = document.createElement('div');
    banner.id = 'ss-seat-hint-banner';
    banner.className = 'ss-seat-hint-banner';
    banner.textContent = 'Elige tu silla';
    mapContainer.insertBefore(banner, mapContainer.firstChild);
  }

  function hideSeatHintBanner() {
    var banner = document.getElementById('ss-seat-hint-banner');
    if (!banner) return;
    banner.classList.add('is-hidden');
    setTimeout(function () {
      if (banner.parentNode) { banner.parentNode.removeChild(banner); }
    }, 300);
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

      node.circle.fill(SEAT_COLORS.selected.fill);
      node.circle.stroke(SEAT_COLORS.selected.stroke);
      node.label.fill(SEAT_COLORS.selected.text);
    } else {
      // ── Deselect ──
      selectedSeats.splice(idx, 1);
      if (selectedByType[zone]) {
        var ti = selectedByType[zone].indexOf(seatId);
        if (ti !== -1) { selectedByType[zone].splice(ti, 1); }
        if (!selectedByType[zone].length) { delete selectedByType[zone]; }
      }

      node.circle.fill(node.baseColor);
      node.circle.stroke(SEAT_COLORS.available.stroke);
      node.label.fill(SEAT_COLORS.available.text);
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

  /**
   * Cartel de piso/balcón dentro del mismo mapa continuo: un recuadro punteado
   * que encierra las sillas de esa sección, con el nombre en itálica centrado
   * ARRIBA del recuadro (no un banner relleno tapando sillas, como antes).
   * El alto/ancho del recuadro sale de la posición real de las sillas entre
   * este marcador y el siguiente (o el final del mapa) — no un tamaño fijo.
   */
  function drawFloorLabelBoxes(layer, rows, seats) {
    var markers = [];
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].type === 'floor-label') {
        markers.push({ y: rows[i].y, text: rows[i].text || 'PISO' });
      }
    }
    if (!markers.length) return;
    markers.sort(function (a, b) { return a.y - b.y; });

    var accent = SEAT_COLORS.selected.fill; // mismo violeta ya usado en la paleta
    var pad = 16;

    for (var m = 0; m < markers.length; m++) {
      var top    = markers[m].y;
      var bottom = (m + 1 < markers.length) ? markers[m + 1].y : Infinity;
      var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;

      for (var s = 0; s < seats.length; s++) {
        var seat = seats[s];
        if (seat.y < top || seat.y >= bottom) continue;
        if (seat.x - SEAT_W / 2 < minX) minX = seat.x - SEAT_W / 2;
        if (seat.x + SEAT_W / 2 > maxX) maxX = seat.x + SEAT_W / 2;
        if (seat.y - SEAT_H / 2 < minY) minY = seat.y - SEAT_H / 2;
        if (seat.y + SEAT_H / 2 > maxY) maxY = seat.y + SEAT_H / 2;
      }
      if (minX === Infinity) continue; // sin sillas en esta sección, nada que encerrar

      var boxX = minX - pad, boxY = minY - pad;
      var boxW = (maxX - minX) + pad * 2;
      var boxH = (maxY - minY) + pad * 2;

      var group = new Konva.Group({ x: boxX, y: boxY, listening: false });
      group.add(new Konva.Rect({
        width: boxW, height: boxH,
        stroke: accent, strokeWidth: 1.5, dash: [5, 4], cornerRadius: 6,
      }));
      group.add(new Konva.Text({
        x: 0, y: -27, width: boxW,
        text: markers[m].text, align: 'center',
        fontSize: 17, fontStyle: 'bold', fill: accent, fontFamily: 'sans-serif',
      }));
      layer.add(group);
    }
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
   * Draws a seat (rounded rect + label + línea de categoría opcional). Returns
   * a node descriptor for the click handler. El rect y el label cargan un
   * atributo `seatId` para la delegación de eventos.
   *
   * El relleno representa el ESTADO (disponible/vendida/reservada/seleccionada),
   * no la zona — la zona/categoría comercial se indica aparte con la línea
   * inferior, coloreada con lo que el admin le asignó a esa zona en el
   * builder (zoneColorMap, que sigue existiendo, solo que ya no se usa como
   * fill acá). Sin zonas hardcodeadas: si una zona no tiene color propio
   * asignado, simplemente no dibuja línea.
   */
  function drawSeat(group, seat, zoneColorMap, soldSet, reservedSet, currentSaleMode) {
    var isSold     = soldSet[seat.id]     === true;
    var isReserved = reservedSet[seat.id] === true;

    var state = isSold ? SEAT_COLORS.sold : (isReserved ? SEAT_COLORS.reserved : SEAT_COLORS.available);
    var interactive = true;

    // Zone mode: all seats are view-only (no interaction)
    if (currentSaleMode === 'zone') {
      interactive = false;
    }
    if (isSold || isReserved) {
      interactive = false;
    }

    var rectX = seat.x - SEAT_W / 2;
    var rectY = seat.y - SEAT_H / 2;

    var circle = new Konva.Rect({
      x:            rectX,
      y:            rectY,
      width:        SEAT_W,
      height:       SEAT_H,
      cornerRadius: SEAT_CORNER,
      fill:         state.fill,
      stroke:       state.stroke,
      strokeWidth:  1,
      listening:    interactive,
      seatId:       seat.id,
    });

    // Overlay blanco translúcido: se ilumina en hover (desktop) sin tener que
    // parsear el color base del estado.
    var hoverOverlay = new Konva.Rect({
      x:            rectX,
      y:            rectY,
      width:        SEAT_W,
      height:       SEAT_H,
      cornerRadius: SEAT_CORNER,
      fill:         '#ffffff',
      opacity:      0,
      listening:    false,
    });

    // Barra de categoría comercial — independiente del estado, ocupa todo el
    // ancho de la silla en una franja inferior (no una rayita fina, se perdía
    // de lejos). Sin nombres de zona hardcodeados: el color es el que el
    // admin le asignó a esa zona en el builder (zoneColorMap) — si no le
    // asignó ninguno, no se dibuja nada.
    // NOTA: se agrega al group DESPUÉS de circle/hoverOverlay (ver abajo), si
    // no, el rect opaco de la silla la tapa por completo.
    var accentColor = zoneColorMap[seat.zone] || null;
    var accentRect = null;
    if (accentColor) {
      var accentH = SEAT_H * 0.3;
      accentRect = new Konva.Rect({
        x:            rectX,
        y:            rectY + SEAT_H - accentH,
        width:        SEAT_W,
        height:       accentH,
        cornerRadius: [0, 0, SEAT_CORNER, SEAT_CORNER],
        fill:         accentColor,
        stroke:       'rgba(0,0,0,.35)',
        strokeWidth:  0.75,
        listening:    false,
      });
    }

    var label = new Konva.Text({
      x:         seat.x - SEAT_W / 2,
      y:         seat.y - 6,
      width:     SEAT_W,
      text:      seat.id,
      fontSize:  10,
      fontStyle: '500',
      fill:      state.text,
      // Vendida/reservada: número apagado — que no se confunda con disponible.
      opacity:   (isSold || isReserved) ? 0.5 : 1,
      align:     'center',
      listening: interactive,
      seatId:    seat.id,
    });

    // Pointer cursor + leve cambio de color en hover (mouseenter/mouseleave solo
    // disparan con mouse real, no con touch, así que esto ya queda limitado a PC)
    if (interactive) {
      circle.on('mouseenter', function () {
        circle.getStage().container().style.cursor = 'pointer';
        hoverOverlay.opacity(0.3);
        hoverOverlay.getLayer().batchDraw();
      });
      circle.on('mouseleave', function () {
        circle.getStage().container().style.cursor = '';
        hoverOverlay.opacity(0);
        hoverOverlay.getLayer().batchDraw();
      });
      label.on('mouseenter', function () {
        label.getStage().container().style.cursor = 'pointer';
        hoverOverlay.opacity(0.3);
        hoverOverlay.getLayer().batchDraw();
      });
      label.on('mouseleave', function () {
        label.getStage().container().style.cursor = '';
        hoverOverlay.opacity(0);
        hoverOverlay.getLayer().batchDraw();
      });
    }

    group.add(circle);
    group.add(hoverOverlay);
    if (accentRect) { group.add(accentRect); }
    group.add(label);

    return { circle: circle, label: label, zone: seat.zone, baseColor: SEAT_COLORS.available.fill };
  }

  // ═══════════════════════════════════════════════════════════════════
  //  VIEWPORT — Fit / Zoom / Pan  (mirrors builder logic)
  // ═══════════════════════════════════════════════════════════════════

  // Piso de escala para el fit inicial (NO para el botón "ver todo el mapa",
  // que pasa fitAll=true): en recintos grandes, meter TODO el venue de una
  // dejaba las sillas como puntos ilegibles. Mejor arrancar más cerca (aunque
  // no se vea completo) y que el usuario se mueva con los botones/pellizco —
  // igual al criterio de LABEL_VISIBILITY_SCALE_THRESHOLD.
  var FIT_MIN_SCALE = 0.75;

  function fitStageToContent(stage, seats, config, fitAll) {
    if (!seats.length) return;

    var minX =  Infinity, maxX = -Infinity;
    var minY =  Infinity, maxY = -Infinity;

    var halfW = SEAT_W / 2, halfH = SEAT_H / 2;
    for (var i = 0; i < seats.length; i++) {
      var s = seats[i];
      if (s.x - halfW < minX) minX = s.x - halfW;
      if (s.x + halfW > maxX) maxX = s.x + halfW;
      if (s.y - halfH < minY) minY = s.y - halfH;
      if (s.y + halfH > maxY) maxY = s.y + halfH;
    }

    if (config.layout && config.layout.stage) {
      var esc = config.layout.stage;
      if (esc.x < minX) minX = esc.x;
      if (esc.x + esc.width  > maxX) maxX = esc.x + esc.width;
      if (esc.y < minY) minY = esc.y;
      if (esc.y + esc.height > maxY) maxY = esc.y + esc.height;
    }

    // Margen extra para el texto en itálica que queda arriba del recuadro
    // punteado del cartel de piso (ver drawFloorLabelBoxes) — las sillas ya
    // están cubiertas por el loop de arriba, esto es solo para que el fit no
    // corte el texto.
    var flRows = config.rows || [];
    for (var fl = 0; fl < flRows.length; fl++) {
      if (flRows[fl].type !== 'floor-label') continue;
      var fy = flRows[fl].y;
      if (fy - 52 < minY) minY = fy - 52;
    }

    var contentW = maxX - minX;
    var contentH = maxY - minY;
    var padding  = 30;
    var stageW   = stage.width();
    var stageH   = stage.height();

    var scaleX   = (stageW - padding * 2) / contentW;
    var scaleY   = (stageH - padding * 2) / contentH;
    var newScale  = Math.min(scaleX, scaleY);
    var effectiveMin = fitAll ? ZOOM_MIN : Math.max(ZOOM_MIN, FIT_MIN_SCALE);
    newScale      = Math.max(effectiveMin, Math.min(ZOOM_MAX, newScale));

    stage.scale({ x: newScale, y: newScale });

    var centerX = (minX + maxX) / 2;
    // Cuando no entra todo el venue (fit parcial), centrar en el centro
    // geométrico de TODO el contenido quedaba "random" — a veces esa mitad
    // cae en medio de la nada. Mejor sesgar hacia el escenario/primeras
    // filas (arriba), que es lo que tiene sentido ver de entrada. El fit
    // completo ("ver todo") sí usa el centro real.
    var centerY = fitAll ? (minY + maxY) / 2 : (minY + (maxY - minY) * 0.35);
    stage.position({
      x: stageW / 2 - centerX * newScale,
      y: stageH / 2 - centerY * newScale,
    });

    stage.batchDraw();
  }

  function initViewport(stage, onScaleChange) {
    // Zoom on wheel
    stage.container().addEventListener('wheel', function (e) {
      e.preventDefault();
      _userInteractedWithMap = true;
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
      if (onScaleChange) onScaleChange(newScale);
    }, { passive: false });

    // Pan via drag on empty canvas
    var isPanning = false;
    var panMoved  = false;
    var lastPos   = { x: 0, y: 0 };

    stage.on('mousedown touchstart', function (e) {
      if (e.target !== stage) return;
      _userInteractedWithMap = true;
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
        _userInteractedWithMap = true;
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
      if (onScaleChange) onScaleChange(newScale);
    }, { passive: false });

    function getTouchDist(touches) {
      var dx = touches[0].clientX - touches[1].clientX;
      var dy = touches[0].clientY - touches[1].clientY;
      return Math.sqrt(dx * dx + dy * dy);
    }
  }

})();
