// ═══════════════════════════════════════════════════════════════════
//  STAGE SETUP — Two layers: zoneLayer (editable) + seatLayer (seats)
// ═══════════════════════════════════════════════════════════════════

var _containerEl = document.getElementById('container');
var stage = new Konva.Stage({
  container: 'container',
  width: _containerEl.clientWidth || 1200,
  height: _containerEl.clientHeight || 800,
});

var zoneLayer = new Konva.Layer();   // zones + escenario + transformer
var seatLayer = new Konva.Layer();   // seats only
stage.add(zoneLayer);
stage.add(seatLayer);

var seatRadius = 15;

// ═══════════════════════════════════════════════════════════════════
//  BUILDER STATE (runtime only — never serialized)
// ═══════════════════════════════════════════════════════════════════

var _builderMode = true;
var _zoneNodes = [];         // [{ group, bgRect, border, label }]
var _escenarioNode = null;   // { group, rect, text }
var _transformer = null;     // shared Konva.Transformer
var _expandedRows  = {};     // { [rowIndex]: true } — state for advanced panel visibility
var _activeFloorIdx = 0;   // runtime only — index into venueConfig.floors

// ─── Viewport State (zoom / pan) ────────────────────────────────
var _viewportInitialized = false;
var _zoneSystemInitialized = false;
var _isPanning = false;
var _panMoved = false;
var _panLastPos = { x: 0, y: 0 };
var ZOOM_MIN = 0.5;
var ZOOM_MAX = 3;
var ZOOM_STEP = 1.08;       // wheel granularity
var ZOOM_BTN_STEP = 1.2;    // button click granularity

// ═══════════════════════════════════════════════════════════════════
//  VENUE CONFIGURATION
// ═══════════════════════════════════════════════════════════════════

var venueConfig = {
  startX: 100,
  spacing: seatRadius * 3,

  layout: {
    stage: { x: 80, y: 40, width: 500, height: 40, text: "ESCENARIO" }
  },

  zones: [
    { id: "VIP", color: "gold" },
    { id: "GENERAL", color: "#4a90d9" }
  ],

  zoneRects: [
    { id: "VIP",     x: 80,  y: 100, width: 560, height: 60 },
    { id: "GENERAL", x: 80,  y: 160, width: 620, height: 60 }
  ],

  rows: [
    { label: "A", count: 12, y: 120, zone: "VIP",     gaps: [{ after: 3, size: 2 }, { after: 7, size: 2 }], removedSeats: [] },
    { label: "B", count: 14, y: 170, zone: "GENERAL",  gaps: [{ after: 3, size: 2 }, { after: 7, size: 2 }], removedSeats: [] }
  ],

  floors: null  // initialized by ss-admin-builder-init.js
};

// ═══════════════════════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════════════════════

function numberToLetters(num) {
  var letters = "";
  num++;
  while (num > 0) {
    var remainder = (num - 1) % 26;
    letters = String.fromCharCode(65 + remainder) + letters;
    num = Math.floor((num - 1) / 26);
  }
  return letters;
}

function _getZoneColor(zoneId) {
  for (var i = 0; i < venueConfig.zones.length; i++) {
    if (venueConfig.zones[i].id === zoneId) return venueConfig.zones[i].color;
  }
  return "#4a90d9";
}

// ═══════════════════════════════════════════════════════════════════
//  SEAT ENGINE (provided by seat-engine.js → window.SeatEngine)
// ═══════════════════════════════════════════════════════════════════

var detectSeatZone    = SeatEngine.detectSeatZone;
var generateRow       = SeatEngine.generateRow;
var buildSeatsFromConfig = SeatEngine.buildSeatsFromConfig;

// ═══════════════════════════════════════════════════════════════════
//  ZONE SYSTEM — createZoneRect / createEscenario
// ═══════════════════════════════════════════════════════════════════

function createZoneRect(zrConfig, index) {
  var baseColor = _getZoneColor(zrConfig.id);

  var group = new Konva.Group({
    x: zrConfig.x,
    y: zrConfig.y,
    draggable: _builderMode,
    listening: _builderMode,
    name: 'zoneRect',
  });

  var bgRect = new Konva.Rect({
    width: zrConfig.width,
    height: zrConfig.height,
    fill: baseColor,
    opacity: _builderMode ? 0.15 : 0,
    cornerRadius: 6,
  });

  var border = new Konva.Rect({
    width: zrConfig.width,
    height: zrConfig.height,
    stroke: baseColor,
    strokeWidth: _builderMode ? 2 : 0,
    cornerRadius: 6,
    dash: [6, 3],
    opacity: _builderMode ? 0.5 : 0,
    listening: false,
  });

  var label = new Konva.Text({
    x: 6,
    y: 4,
    text: zrConfig.id,
    fontSize: 11,
    fontStyle: 'bold',
    fill: baseColor,
    opacity: _builderMode ? 0.7 : 0,
    listening: false,
  });

  group.add(bgRect);
  group.add(border);
  group.add(label);

  // --- Drag → sync position ---
  group.on('dragend', function() {
    venueConfig.zoneRects[index].x = Math.round(group.x());
    venueConfig.zoneRects[index].y = Math.round(group.y());
    _redrawSeats();
    renderZoneRectsList();
  });

  // --- Transform → convert scale to width/height ---
  group.on('transform', function() {
    var sx = group.scaleX();
    var sy = group.scaleY();
    var newW = Math.max(30, Math.round(bgRect.width() * sx));
    var newH = Math.max(20, Math.round(bgRect.height() * sy));
    bgRect.width(newW);
    bgRect.height(newH);
    border.width(newW);
    border.height(newH);
    group.scaleX(1);
    group.scaleY(1);
  });

  group.on('transformend', function() {
    venueConfig.zoneRects[index].x = Math.round(group.x());
    venueConfig.zoneRects[index].y = Math.round(group.y());
    venueConfig.zoneRects[index].width = Math.round(bgRect.width());
    venueConfig.zoneRects[index].height = Math.round(bgRect.height());
    _redrawSeats();
    renderZoneRectsList();
  });

  // --- Click to select ---
  group.on('click tap', function(e) {
    if (!_builderMode) return;
    e.cancelBubble = true;
    if (_transformer) {
      _transformer.nodes([group]);
      zoneLayer.batchDraw();
    }
  });

  return { group: group, bgRect: bgRect, border: border, label: label };
}

// ─── Escenario (editable in builder, visible in production) ─────

function createEscenario() {
  var cfg = venueConfig.layout.stage;

  var group = new Konva.Group({
    x: cfg.x,
    y: cfg.y,
    draggable: _builderMode,
    name: 'escenario',
  });

  var rect = new Konva.Rect({
    width: cfg.width,
    height: cfg.height,
    fill: '#333',
    cornerRadius: 5,
  });

  var text = new Konva.Text({
    y: Math.max(0, (cfg.height - 18) / 2),
    width: cfg.width,
    text: cfg.text,
    fontSize: 18,
    fill: 'white',
    align: 'center',
    listening: false,
  });

  group.add(rect);
  group.add(text);

  // --- Drag ---
  group.on('dragend', function() {
    venueConfig.layout.stage.x = Math.round(group.x());
    venueConfig.layout.stage.y = Math.round(group.y());
  });

  // --- Transform ---
  group.on('transform', function() {
    var sx = group.scaleX();
    var sy = group.scaleY();
    var newW = Math.max(60, Math.round(rect.width() * sx));
    var newH = Math.max(20, Math.round(rect.height() * sy));
    rect.width(newW);
    rect.height(newH);
    text.width(newW);
    text.y(Math.max(0, (newH - 18) / 2));
    group.scaleX(1);
    group.scaleY(1);
  });

  group.on('transformend', function() {
    venueConfig.layout.stage.x = Math.round(group.x());
    venueConfig.layout.stage.y = Math.round(group.y());
    venueConfig.layout.stage.width = Math.round(rect.width());
    venueConfig.layout.stage.height = Math.round(rect.height());
  });

  // --- Dblclick to edit text ---
  group.on('dblclick dbltap', function() {
    if (!_builderMode) return;
    var newText = prompt('Texto del escenario:', venueConfig.layout.stage.text);
    if (newText !== null && newText !== '') {
      venueConfig.layout.stage.text = newText;
      text.text(newText);
      zoneLayer.batchDraw();
    }
  });

  // --- Click to select ---
  group.on('click tap', function(e) {
    if (!_builderMode) return;
    e.cancelBubble = true;
    if (_transformer) {
      _transformer.nodes([group]);
      zoneLayer.batchDraw();
    }
  });

  return { group: group, rect: rect, text: text };
}

// ═══════════════════════════════════════════════════════════════════
//  ZONE SYSTEM CONTROL
// ═══════════════════════════════════════════════════════════════════

function initZoneSystem() {
  if (_zoneSystemInitialized) return;
  _zoneSystemInitialized = true;

  if (!venueConfig.layout) {
    venueConfig.layout = {
      stage: { x: 80, y: 40, width: 500, height: 40, text: "ESCENARIO" }
    };
  }

  // Click empty canvas to deselect transformer (skip if we just panned)
  stage.on('click tap', function(e) {
    if (_panMoved) { _panMoved = false; return; }
    if (e.target === stage) {
      if (_transformer) {
        _transformer.nodes([]);
        zoneLayer.batchDraw();
      }
    }
  });

  // Seat click delegation — toggle removed / gap
  seatLayer.on('click', function(e) {
    if (!_builderMode) return;
    if (_panMoved) return;
    var target = e.target;
    if (!(target instanceof Konva.Circle)) return;
    var rowIdx = target.getAttr('rowIndex');
    var slot   = target.getAttr('physicalSlot');
    if (rowIdx === undefined || rowIdx === null || slot === undefined || slot === null) return;
    if (e.evt && e.evt.shiftKey && !target.getAttr('isRemoved')) {
      _toggleGapAfter(rowIdx, slot);
    } else {
      _toggleRemovedSeat(rowIdx, slot);
    }
  });
}

function enableZoneEditing(enable) {
  for (var i = 0; i < _zoneNodes.length; i++) {
    var n = _zoneNodes[i];
    n.group.draggable(enable);
    n.group.listening(enable);
    n.bgRect.opacity(enable ? 0.15 : 0);
    n.border.opacity(enable ? 0.5 : 0);
    n.border.strokeWidth(enable ? 2 : 0);
    n.label.opacity(enable ? 0.7 : 0);
  }

  if (_escenarioNode) {
    _escenarioNode.group.draggable(enable);
  }

  if (_transformer) {
    _transformer.nodes([]);
    _transformer.visible(enable);
  }

  zoneLayer.batchDraw();
}

function toggleBuilderMode(isBuilder) {
  _builderMode = !!isBuilder;
  enableZoneEditing(_builderMode);

  // Toggle HTML builder panels
  var panels = document.querySelectorAll('.builder-panel');
  for (var i = 0; i < panels.length; i++) {
    panels[i].style.display = _builderMode ? '' : 'none';
  }

  var btn = document.getElementById('toggleBuilder');
  if (btn) {
    btn.textContent = _builderMode ? 'Modo Producción' : 'Modo Builder';
  }
}

function serializeLayout() {
  // 1. Sync live Konva positions → active floor data
  if (_escenarioNode) {
    var eg = _escenarioNode.group;
    var er = _escenarioNode.rect;
    venueConfig.layout.stage.x      = Math.round(eg.x());
    venueConfig.layout.stage.y      = Math.round(eg.y());
    venueConfig.layout.stage.width  = Math.round(er.width()  * eg.scaleX());
    venueConfig.layout.stage.height = Math.round(er.height() * eg.scaleY());
  }
  for (var i = 0; i < _zoneNodes.length && i < venueConfig.zoneRects.length; i++) {
    var zg = _zoneNodes[i].group;
    var zr = _zoneNodes[i].bgRect;
    venueConfig.zoneRects[i].x      = Math.round(zg.x());
    venueConfig.zoneRects[i].y      = Math.round(zg.y());
    venueConfig.zoneRects[i].width  = Math.round(zr.width()  * zg.scaleX());
    venueConfig.zoneRects[i].height = Math.round(zr.height() * zg.scaleY());
  }

  // 2. Flush active floor data into floors[]
  _saveCurrentFloor();

  // 3. Build clean serializable object (floors format)
  var floors = (venueConfig.floors || []).map(function(f) {
    return {
      id:        f.id    || '',
      label:     f.label || '',
      rows:      JSON.parse(JSON.stringify(f.rows || [])),
      zoneRects: (f.zoneRects || []).map(function(c) {
        return { id: c.id, x: c.x, y: c.y, width: c.width, height: c.height };
      }),
      layout: JSON.parse(JSON.stringify(f.layout || {}))
    };
  });

  return {
    floors:  floors,
    zones:   JSON.parse(JSON.stringify(venueConfig.zones   || [])),
    startX:  venueConfig.startX,
    spacing: venueConfig.spacing
  };
}

// ═══════════════════════════════════════════════════════════════════
//  VIEWPORT CONTROLS — Zoom + Pan
// ═══════════════════════════════════════════════════════════════════

function initViewportControls() {
  if (_viewportInitialized) return;
  _viewportInitialized = true;

  // --- Zoom centered on cursor (wheel) ---
  // Using DOM listener with passive:false to reliably prevent page scroll
  stage.container().addEventListener('wheel', function(e) {
    e.preventDefault();

    var pointer = stage.getPointerPosition();
    if (!pointer) return;

    var oldScale = stage.scaleX();

    // Point under cursor in unscaled stage coordinates
    var mousePointTo = {
      x: (pointer.x - stage.x()) / oldScale,
      y: (pointer.y - stage.y()) / oldScale,
    };

    var newScale = e.deltaY > 0
      ? oldScale / ZOOM_STEP
      : oldScale * ZOOM_STEP;
    newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

    stage.scale({ x: newScale, y: newScale });

    // Reposition so the point under cursor stays fixed
    stage.position({
      x: pointer.x - mousePointTo.x * newScale,
      y: pointer.y - mousePointTo.y * newScale,
    });

    stage.batchDraw();
  }, { passive: false });

  // --- Pan via left-click drag on empty canvas ---
  stage.on('mousedown', function(e) {
    // Only pan when clicking directly on stage background
    if (e.target !== stage) return;
    _isPanning = true;
    _panMoved = false;
    _panLastPos = { x: e.evt.clientX, y: e.evt.clientY };
    stage.container().style.cursor = 'grabbing';
  });

  // Window-level move/up so pan works even if cursor leaves the canvas
  window.addEventListener('mousemove', function(e) {
    if (!_isPanning) return;
    _panMoved = true;

    var dx = e.clientX - _panLastPos.x;
    var dy = e.clientY - _panLastPos.y;
    _panLastPos = { x: e.clientX, y: e.clientY };

    stage.x(stage.x() + dx);
    stage.y(stage.y() + dy);
    stage.batchDraw();
  });

  window.addEventListener('mouseup', function() {
    if (!_isPanning) return;
    _isPanning = false;
    stage.container().style.cursor = '';
  });
}

// ─── Zoom via buttons (+, −, Fit) ───────────────────────────────

function zoomStage(direction) {
  var oldScale = stage.scaleX();
  var newScale = direction > 0
    ? oldScale * ZOOM_BTN_STEP
    : oldScale / ZOOM_BTN_STEP;
  newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));
  stage.scale({ x: newScale, y: newScale });
  stage.batchDraw();
}

function fitStageToContent() {
  var seats = buildSeatsFromConfig(venueConfig);
  if (seats.length === 0) return;

  // Bounding box of all seats
  var minX = Infinity, maxX = -Infinity;
  var minY = Infinity, maxY = -Infinity;
  for (var i = 0; i < seats.length; i++) {
    var s = seats[i];
    if (s.x - seatRadius < minX) minX = s.x - seatRadius;
    if (s.x + seatRadius > maxX) maxX = s.x + seatRadius;
    if (s.y - seatRadius < minY) minY = s.y - seatRadius;
    if (s.y + seatRadius > maxY) maxY = s.y + seatRadius;
  }

  // Include escenario
  var esc = venueConfig.layout.stage;
  if (esc.x < minX) minX = esc.x;
  if (esc.x + esc.width > maxX) maxX = esc.x + esc.width;
  if (esc.y < minY) minY = esc.y;
  if (esc.y + esc.height > maxY) maxY = esc.y + esc.height;

  var contentW = maxX - minX;
  var contentH = maxY - minY;
  var padding = 40;
  var stageW = stage.width();
  var stageH = stage.height();

  // Scale to fit, clamped to zoom limits
  var scaleX = (stageW - padding * 2) / contentW;
  var scaleY = (stageH - padding * 2) / contentH;
  var newScale = Math.min(scaleX, scaleY);
  newScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, newScale));

  stage.scale({ x: newScale, y: newScale });

  // Center the content in the viewport
  var centerX = (minX + maxX) / 2;
  var centerY = (minY + maxY) / 2;
  stage.position({
    x: stageW / 2 - centerX * newScale,
    y: stageH / 2 - centerY * newScale,
  });

  stage.batchDraw();
}

// ═══════════════════════════════════════════════════════════════════
//  RENDER PIPELINE
// ═══════════════════════════════════════════════════════════════════

function _redrawZoneLayer() {
  zoneLayer.destroyChildren();
  _zoneNodes = [];
  _escenarioNode = null;
  _transformer = null;

  // Escenario
  _escenarioNode = createEscenario();
  zoneLayer.add(_escenarioNode.group);

  // Zone rects
  for (var i = 0; i < venueConfig.zoneRects.length; i++) {
    var node = createZoneRect(venueConfig.zoneRects[i], i);
    _zoneNodes.push(node);
    zoneLayer.add(node.group);
  }

  // Floor-label banners
  for (var fl = 0; fl < venueConfig.rows.length; fl++) {
    var flRow = venueConfig.rows[fl];
    if (flRow.type !== 'floor-label') continue;
    var flGroup = new Konva.Group({ x: venueConfig.startX, y: flRow.y - 14 });
    flGroup.add(new Konva.Rect({
      x: 0, y: 0, width: 500, height: 22,
      fill: '#1e293b', opacity: 0.85, cornerRadius: 4
    }));
    flGroup.add(new Konva.Text({
      x: 8, y: 4,
      text: flRow.text || 'PISO',
      fontSize: 13, fontStyle: 'bold',
      fill: '#fbbf24', fontFamily: 'sans-serif'
    }));
    zoneLayer.add(flGroup);
  }

  // Shared transformer (builder mode only)
  _transformer = new Konva.Transformer({
    nodes: [],
    rotateEnabled: false,
    keepRatio: false,
    enabledAnchors: [
      'top-left', 'top-center', 'top-right',
      'middle-left', 'middle-right',
      'bottom-left', 'bottom-center', 'bottom-right'
    ],
    borderStroke: '#0084ff',
    borderStrokeWidth: 1,
    anchorStroke: '#0084ff',
    anchorFill: '#fff',
    anchorSize: 8,
    visible: _builderMode,
    boundBoxFunc: function(oldBox, newBox) {
      if (newBox.width < 30) newBox.width = 30;
      if (newBox.height < 20) newBox.height = 20;
      return newBox;
    }
  });
  zoneLayer.add(_transformer);

  zoneLayer.batchDraw();
}

function _contrastText(hexColor) {
  var hex = (hexColor || '#888').replace('#', '');
  if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
  var r = parseInt(hex.substr(0,2),16) || 0;
  var g = parseInt(hex.substr(2,2),16) || 0;
  var b = parseInt(hex.substr(4,2),16) || 0;
  return (0.299*r + 0.587*g + 0.114*b) / 255 > 0.6 ? '#1a1a1a' : '#ffffff';
}

function _redrawSeats() {
  seatLayer.destroyChildren();

  var seatsGroup = new Konva.Group();
  var seats = buildSeatsFromConfig(venueConfig);

  // O(1) color lookup
  var zoneColorMap = {};
  for (var z = 0; z < venueConfig.zones.length; z++) {
    zoneColorMap[venueConfig.zones[z].id] = venueConfig.zones[z].color;
  }

  for (var i = 0; i < seats.length; i++) {
    var seat = seats[i];
    var fillColor = zoneColorMap[seat.zone] || '#888888';

    var circle = new Konva.Circle({
      x: seat.x,
      y: seat.y,
      radius: seatRadius,
      fill: fillColor,
      stroke: '#00000033',
      strokeWidth: 1,
    });
    circle.setAttr('origFill', fillColor);
    circle.setAttr('rowIndex', seat.rowIndex);
    circle.setAttr('physicalSlot', seat.physicalSlot);
    circle.setAttr('isRemoved', false);
    if (_builderMode) {
      circle.on('mouseenter', function() { stage.container().style.cursor = 'pointer'; });
      circle.on('mouseleave', function() { stage.container().style.cursor = ''; });
    }

    var textLabel = new Konva.Text({
      x: seat.x - seatRadius,
      y: seat.y - 5,
      width: seatRadius * 2,
      text: seat.id,
      fontSize: 8,
      fontStyle: 'bold',
      fill: _contrastText(fillColor),
      align: 'center',
      listening: false,
    });

    seatsGroup.add(circle);
    seatsGroup.add(textLabel);
  }

  // Ghost seats for removed slots — builder only
  if (_builderMode) {
    for (var ri = 0; ri < venueConfig.rows.length; ri++) {
      var row = venueConfig.rows[ri];
      if (!row.label || !row.count || row.type === 'empty' || row.type === 'floor-label') continue;
      var removed = row.removedSeats || [];
      if (removed.length === 0) continue;

      var removedSet = {};
      for (var rs = 0; rs < removed.length; rs++) { removedSet[removed[rs]] = true; }

      var gapMap = {};
      for (var gm = 0; gm < (row.gaps || []).length; gm++) {
        gapMap[row.gaps[gm].after] = row.gaps[gm].size;
      }

      var cursorX = venueConfig.startX;
      for (var slot = 1; slot <= row.count; slot++) {
        var slotX = cursorX;

        if (removedSet[slot]) {
          cursorX += venueConfig.spacing;

          var ghost = new Konva.Circle({
            x: slotX, y: row.y,
            radius: seatRadius,
            fill: '#e5e7eb',
            stroke: '#9ca3af',
            strokeWidth: 1.5,
            dash: [3, 3],
            opacity: 0.75,
          });
          ghost.setAttr('rowIndex', ri);
          ghost.setAttr('physicalSlot', slot);
          ghost.setAttr('isRemoved', true);

          var ghostText = new Konva.Text({
            x: slotX - seatRadius, y: row.y - 5,
            width: seatRadius * 2,
            text: '✕', fontSize: 9, fontStyle: 'bold',
            fill: '#6b7280', align: 'center',
            listening: false,
          });

          ghost.on('mouseenter', function() { stage.container().style.cursor = 'pointer'; });
          ghost.on('mouseleave', function() { stage.container().style.cursor = ''; });

          seatsGroup.add(ghost);
          seatsGroup.add(ghostText);
        } else {
          cursorX += venueConfig.spacing;
          if (gapMap[slot] !== undefined) {
            cursorX += gapMap[slot] * venueConfig.spacing;
          }
        }
      }
    }
  }

  seatLayer.add(seatsGroup);
  seatLayer.batchDraw();
}

// Public entry point — maintains existing API
function redrawVenue() {
  _redrawZoneLayer();
  _redrawSeats();
}

// ═══════════════════════════════════════════════════════════════════
//  MULTI-FLOOR MANAGEMENT
// ═══════════════════════════════════════════════════════════════════

function _saveCurrentFloor() {
  if (!venueConfig.floors || !venueConfig.floors[_activeFloorIdx]) return;
  venueConfig.floors[_activeFloorIdx].rows      = venueConfig.rows;
  venueConfig.floors[_activeFloorIdx].zoneRects = venueConfig.zoneRects;
  venueConfig.floors[_activeFloorIdx].layout    = venueConfig.layout;
}

function _loadFloor(idx) {
  _saveCurrentFloor();
  _activeFloorIdx = idx;
  var floor = venueConfig.floors[idx];
  venueConfig.rows      = floor.rows      || [];
  venueConfig.zoneRects = floor.zoneRects || [];
  venueConfig.layout    = floor.layout    || { stage: { x: 80, y: 40, width: 500, height: 40, text: 'ESCENARIO' } };
  renderFloorTabs();
  redrawVenue();
  renderRowsList();
  renderZoneRectsList();
  fitStageToContent();
}

function renderFloorTabs() {
  var container = document.getElementById('ss-floor-tabs');
  if (!container || !venueConfig.floors) return;
  container.innerHTML = '';

  for (var i = 0; i < venueConfig.floors.length; i++) {
    (function(idx) {
      var floor = venueConfig.floors[idx];
      var isActive = idx === _activeFloorIdx;

      var tab = document.createElement('div');
      tab.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:600;border:1px solid ' + (isActive ? '#2271b1' : '#c3c4c7') + ';background:' + (isActive ? '#2271b1' : '#f6f7f7') + ';color:' + (isActive ? '#fff' : '#50575e') + ';user-select:none;';

      var labelSpan = document.createElement('span');
      labelSpan.textContent = floor.label || ('Piso ' + (idx + 1));
      labelSpan.title = 'Doble clic para renombrar';
      labelSpan.addEventListener('dblclick', function(e) {
        e.stopPropagation();
        var cur = venueConfig.floors[idx].label || ('Piso ' + (idx + 1));
        var next = prompt('Nombre del piso:', cur);
        if (next !== null && next.trim() !== '') {
          venueConfig.floors[idx].label = next.trim();
          renderFloorTabs();
        }
      });

      tab.addEventListener('click', function() {
        if (_activeFloorIdx !== idx) { _loadFloor(idx); }
      });

      tab.appendChild(labelSpan);

      if (venueConfig.floors.length > 1) {
        var delBtn = document.createElement('span');
        delBtn.textContent = '×';
        delBtn.title = 'Eliminar este piso';
        delBtn.style.cssText = 'font-size:14px;line-height:1;opacity:0.6;cursor:pointer;margin-left:2px;';
        delBtn.addEventListener('click', function(e) {
          e.stopPropagation();
          var floorLabel = venueConfig.floors[idx].label || ('Piso ' + (idx + 1));
          if (!confirm('¿Eliminar "' + floorLabel + '" y todas sus filas?')) return;
          venueConfig.floors.splice(idx, 1);
          _activeFloorIdx = Math.min(_activeFloorIdx, venueConfig.floors.length - 1);
          var f = venueConfig.floors[_activeFloorIdx];
          venueConfig.rows      = f.rows      || [];
          venueConfig.zoneRects = f.zoneRects || [];
          venueConfig.layout    = f.layout    || { stage: { x: 80, y: 40, width: 500, height: 40, text: 'ESCENARIO' } };
          renderFloorTabs();
          redrawVenue();
          renderRowsList();
          renderZoneRectsList();
        });
        tab.appendChild(delBtn);
      }

      container.appendChild(tab);
    })(i);
  }

  var addBtn = document.createElement('button');
  addBtn.type = 'button';
  addBtn.textContent = '+ Piso';
  addBtn.className = 'button button-small';
  addBtn.style.cssText = 'font-size:12px;';
  addBtn.title = 'Agregar un nuevo piso o sección al venue';
  addBtn.addEventListener('click', function() {
    var newLabel = prompt('Nombre del nuevo piso:', 'Piso ' + (venueConfig.floors.length + 1));
    if (newLabel === null) return;
    newLabel = newLabel.trim() || ('Piso ' + (venueConfig.floors.length + 1));
    _saveCurrentFloor();
    venueConfig.floors.push({
      id:        'piso-' + Date.now(),
      label:     newLabel,
      rows:      [],
      zoneRects: [],
      layout:    { stage: { x: 80, y: 40, width: 500, height: 40, text: 'ESCENARIO' } }
    });
    _loadFloor(venueConfig.floors.length - 1);
  });
  container.appendChild(addBtn);
}

// ═══════════════════════════════════════════════════════════════════
//  BUILDER UI — Row list
// ═══════════════════════════════════════════════════════════════════

// Convert removedSeats between physical slots and displayed numbers.
// When reverse=true, displayed number n = count - physicalSlot + 1 (symmetric).
// The UI always shows/accepts displayed numbers; storage always uses physical slots.
// Conversión de posiciones físicas ↔ números mostrados (simétricas cuando reverse=true)
function _removedToDisplay(physicalArr, count, reverse) {
  if (!reverse || !count) return physicalArr;
  return physicalArr.map(function(n) { return count - n + 1; }).sort(function(a,b){return a-b;});
}
function _removedToPhysical(displayArr, count, reverse) {
  if (!reverse || !count) return displayArr;
  return displayArr.map(function(n) { return count - n + 1; }).sort(function(a,b){return a-b;});
}
// Ídem para gaps: convierte el campo `after` entre posición física y número mostrado
function _gapsToDisplay(gaps, count, reverse) {
  if (!reverse || !count) return gaps;
  return gaps.map(function(g) { return { after: count - g.after + 1, size: g.size }; })
             .sort(function(a,b){ return a.after - b.after; });
}
function _gapsToPhysical(gaps, count, reverse) {
  if (!reverse || !count) return gaps;
  return gaps.map(function(g) { return { after: count - g.after + 1, size: g.size }; })
             .sort(function(a,b){ return a.after - b.after; });
}

function _rowSeatRange(row) {
  if (!row.label || !row.count) return '';
  var removed = row.removedSeats || [];
  var visible = row.renumber ? (row.count - removed.length) : row.count;
  if (visible < 1) visible = row.count;
  var first = row.reverse ? visible : 1;
  var last  = row.reverse ? 1 : visible;
  return row.label + first + '–' + row.label + last;
}

function renderRowsList() {
  var container = document.getElementById('rowsList');
  if (!container) return;
  container.innerHTML = '';

  // Sync global-button active state
  var seatRowsAll = venueConfig.rows.filter(function(r){ return r.label; });
  var revBtn = document.getElementById('reverseAllRows');
  var renBtn = document.getElementById('renumberAllRows');
  if (revBtn) {
    var allRev = seatRowsAll.length > 0 && seatRowsAll.every(function(r){ return r.reverse; });
    revBtn.classList.toggle('is-active', allRev);
  }
  if (renBtn) {
    var allRen = seatRowsAll.length > 0 && seatRowsAll.every(function(r){ return r.renumber; });
    renBtn.classList.toggle('is-active', allRen);
  }

  for (var i = 0; i < venueConfig.rows.length; i++) {
    var row = venueConfig.rows[i];
    var card = document.createElement('div');
    card.className = 'ss-row-card';

    if (row.type === 'empty') {
      card.innerHTML =
        '<div class="ss-row-card__head">' +
          '<span style="color:#9ca3af;font-size:11px;flex:1;">— espacio —</span>' +
          '<input data-index="' + i + '" data-field="height" type="number" value="' + (row.height || 30) + '" class="ss-row-card__count" title="Altura (px)" /> ' +
          '<button type="button" data-index="' + i + '" data-action="delete" class="ss-row-card__delete" title="Eliminar">✕</button>' +
        '</div>';

    } else if (row.type === 'floor-label') {
      card.innerHTML =
        '<div class="ss-row-card__head">' +
          '<span style="color:#f59e0b;font-size:11px;flex-shrink:0;">🏢</span>' +
          '<input data-index="' + i + '" data-field="text" value="' + (row.text || 'PISO 2') + '" style="flex:1;font-size:12px;" placeholder="Nombre del piso" />' +
          '<button type="button" data-index="' + i + '" data-action="delete" class="ss-row-card__delete" title="Eliminar">✕</button>' +
        '</div>';

    } else {
      // Zone color dot
      var zoneColor = '#888';
      for (var z = 0; z < venueConfig.zones.length; z++) {
        if (venueConfig.zones[z].id === row.zone) { zoneColor = venueConfig.zones[z].color; break; }
      }

      // Zone select
      var selectHtml = '<select data-index="' + i + '" data-field="zone" class="ss-row-card__zone">';
      for (var zs = 0; zs < venueConfig.zones.length; zs++) {
        var zid = venueConfig.zones[zs].id;
        selectHtml += '<option value="' + zid + '"' + (row.zone === zid ? ' selected' : '') + '>' + zid + '</option>';
      }
      selectHtml += '</select>';

      var isExpanded = !!_expandedRows[i];
      var range = _rowSeatRange(row);

      card.innerHTML =
        '<div class="ss-row-card__head">' +
          '<span class="ss-row-card__dot" style="background:' + zoneColor + ';"></span>' +
          '<input data-index="' + i + '" data-field="label" value="' + row.label + '" class="ss-row-card__label" title="Letra de la fila" />' +
          '<input data-index="' + i + '" data-field="count" type="number" value="' + row.count + '" class="ss-row-card__count" title="Cantidad de sillas" />' +
          '<span class="ss-row-card__range">' + range + '</span>' +
          selectHtml +
          '<button type="button" data-index="' + i + '" data-action="toggle-adv" class="ss-row-card__adv-btn' + (isExpanded ? ' open' : '') + '" title="Opciones avanzadas">▾</button>' +
          '<button type="button" data-index="' + i + '" data-action="delete" class="ss-row-card__delete" title="Eliminar">✕</button>' +
        '</div>' +
        '<div class="ss-row-card__adv"' + (isExpanded ? '' : ' style="display:none;"') + '>' +
          '<label title="El asiento 1 queda a la derecha del todo. Útil cuando el escenario está a la izquierda."><input type="checkbox" data-index="' + i + '" data-field="reverse"' + (row.reverse ? ' checked' : '') + '> Numeración invertida (↔ derecha a izquierda)</label>' +
          '<label title="Los números van seguidos aunque haya huecos. Ej: si quitas el asiento 3, el siguiente sigue siendo 4 (no 4 con un hueco entre medio)."><input type="checkbox" data-index="' + i + '" data-field="renumber"' + (row.renumber ? ' checked' : '') + '> Renumerar sin huecos</label>' +
          '<div class="ss-row-card__adv-field">' +
            '<span>Huecos (silla:tamaño): <span class="ss-help" title="Espacio vacío después de la silla N. Formato: N:tamaño, separados por coma. Ej: \'5:1, 10:2\' → hueco de 1 espacio después de la silla 5, y de 2 después de la 10. También puedes usar Shift+Click en un asiento del canvas.">?</span></span>' +
            '<input data-index="' + i + '" data-field="gaps" value="' + gapsToString(_gapsToDisplay(row.gaps || [], row.count, row.reverse)) + '" placeholder="ej: 5:1, 10:2" />' +
          '</div>' +
          '<div class="ss-row-card__adv-field">' +
            '<span>Quitar asientos: <span class="ss-help" title="Números de asiento que no existen físicamente (columna estructural, pasillo fijo, etc.). Separados por coma. Ej: \'3, 7\'. También puedes hacer clic directamente en el asiento del canvas.">?</span></span>' +
            '<input data-index="' + i + '" data-field="removedSeats" value="' + removedSeatsToString(_removedToDisplay(row.removedSeats || [], row.count, row.reverse)) + '" placeholder="ej: 3, 7" />' +
          '</div>' +
        '</div>';
    }

    container.appendChild(card);
  }
}

// ═══════════════════════════════════════════════════════════════════
//  BUILDER UI — Zone rects list
// ═══════════════════════════════════════════════════════════════════

function renderZoneRectsList() {
  var container = document.getElementById('zoneRectsList');
  if (!container) return;
  container.innerHTML = '';

  for (var i = 0; i < venueConfig.zoneRects.length; i++) {
    var zr = venueConfig.zoneRects[i];
    var div = document.createElement('div');
    div.style.marginBottom = '4px';

    var selectHtml = '<select data-zr-index="' + i + '" data-zr-field="id">';
    for (var z = 0; z < venueConfig.zones.length; z++) {
      var zid = venueConfig.zones[z].id;
      selectHtml += '<option value="' + zid + '"' + (zr.id === zid ? ' selected' : '') + '>' + zid + '</option>';
    }
    selectHtml += '</select>';

    div.innerHTML =
      selectHtml + ' ' +
      'x:<input data-zr-index="' + i + '" data-zr-field="x" type="number" value="' + zr.x + '" style="width:55px;" /> ' +
      'y:<input data-zr-index="' + i + '" data-zr-field="y" type="number" value="' + zr.y + '" style="width:55px;" /> ' +
      'w:<input data-zr-index="' + i + '" data-zr-field="width" type="number" value="' + zr.width + '" style="width:55px;" /> ' +
      'h:<input data-zr-index="' + i + '" data-zr-field="height" type="number" value="' + zr.height + '" style="width:55px;" /> ' +
      '<button data-zr-index="' + i + '" data-zr-action="delete">Eliminar</button>';

    container.appendChild(div);
  }
}

// ═══════════════════════════════════════════════════════════════════
//  BUILDER UI — Zones list (dynamic zone management)
// ═══════════════════════════════════════════════════════════════════

function renderZonesList() {
  var container = document.getElementById('zonesList');
  if (!container) return;
  container.innerHTML = '';

  for (var i = 0; i < venueConfig.zones.length; i++) {
    var zone = venueConfig.zones[i];
    var div = document.createElement('div');
    div.style.marginBottom = '4px';
    div.style.display = 'flex';
    div.style.gap = '4px';
    div.style.alignItems = 'center';

    div.innerHTML =
      '<input data-zone-index="' + i + '" data-zone-field="id" value="' + zone.id + '" style="width:100px;" placeholder="Nombre" />' +
      '<input data-zone-index="' + i + '" data-zone-field="color" type="color" value="' + _colorToHex(zone.color) + '" style="width:40px; height:28px; padding:0; border:1px solid #ccc; cursor:pointer;" />' +
      '<button data-zone-index="' + i + '" data-zone-action="delete" style="color:#c62828; cursor:pointer;">✕</button>';

    container.appendChild(div);
  }
}

function _colorToHex(color) {
  if (color.charAt(0) === '#') return color;
  var canvas = document.createElement('canvas');
  canvas.width = 1;
  canvas.height = 1;
  var ctx = canvas.getContext('2d');
  ctx.fillStyle = color;
  ctx.fillRect(0, 0, 1, 1);
  var data = ctx.getImageData(0, 0, 1, 1).data;
  return '#' + ((1 << 24) + (data[0] << 16) + (data[1] << 8) + data[2]).toString(16).slice(1);
}

function _populateRowTypeSelect() {
  var sel = document.getElementById('rowType');
  if (!sel) return;
  sel.innerHTML = '';
  for (var i = 0; i < venueConfig.zones.length; i++) {
    var opt = document.createElement('option');
    opt.value = venueConfig.zones[i].id;
    opt.textContent = venueConfig.zones[i].id;
    sel.appendChild(opt);
  }
}

// ═══════════════════════════════════════════════════════════════════
//  INTERACTIVE SEAT EDITING
// ═══════════════════════════════════════════════════════════════════

// Click on a normal seat → add to removedSeats (and vice versa for ghost)
function _toggleRemovedSeat(rowIdx, physicalSlot) {
  var row = venueConfig.rows[rowIdx];
  if (!row) return;
  var removed = (row.removedSeats || []).slice();
  var idx = removed.indexOf(physicalSlot);
  if (idx === -1) {
    removed.push(physicalSlot);
    removed.sort(function(a, b) { return a - b; });
  } else {
    removed.splice(idx, 1);
  }
  row.removedSeats = removed;
  renderRowsList();
  _redrawSeats();
}

// Shift+Click on a normal seat → add/increment gap after that physical slot
function _toggleGapAfter(rowIdx, physicalSlot) {
  var row = venueConfig.rows[rowIdx];
  if (!row) return;
  var gaps = (row.gaps || []).slice();
  var found = false;
  for (var g = 0; g < gaps.length; g++) {
    if (gaps[g].after === physicalSlot) {
      gaps[g] = { after: gaps[g].after, size: gaps[g].size + 1 };
      found = true;
      break;
    }
  }
  if (!found) {
    gaps.push({ after: physicalSlot, size: 1 });
    gaps.sort(function(a, b) { return a.after - b.after; });
  }
  row.gaps = gaps;
  renderRowsList();
  _redrawSeats();
}

// ═══════════════════════════════════════════════════════════════════
//  EVENT LISTENERS
// ═══════════════════════════════════════════════════════════════════

// --- Zones list events (delegated) ---
function _handleZoneChange(e) {
  var target = e.target;
  var index = parseInt(target.getAttribute('data-zone-index'));
  var field = target.getAttribute('data-zone-field');
  if (isNaN(index) || !field) return;
  if (index < 0 || index >= venueConfig.zones.length) return;

  if (field === 'id') {
    var oldName = venueConfig.zones[index].id;
    var newName = target.value.trim();
    if (newName === '' || newName === oldName) {
      target.value = oldName;
      return;
    }

    // Check for duplicates
    for (var d = 0; d < venueConfig.zones.length; d++) {
      if (d !== index && venueConfig.zones[d].id === newName) {
        target.value = oldName;
        return;
      }
    }

    // Update zone name
    venueConfig.zones[index].id = newName;

    // Cascade: update rows that had oldName
    for (var r = 0; r < venueConfig.rows.length; r++) {
      if (venueConfig.rows[r].zone === oldName) {
        venueConfig.rows[r].zone = newName;
      }
    }

    // Cascade: update zoneRects that had oldName
    for (var z = 0; z < venueConfig.zoneRects.length; z++) {
      if (venueConfig.zoneRects[z].id === oldName) {
        venueConfig.zoneRects[z].id = newName;
      }
    }

    _populateRowTypeSelect();
    renderRowsList();
    renderZoneRectsList();
    redrawVenue();
  }

  if (field === 'color') {
    venueConfig.zones[index].color = target.value;
    redrawVenue();
  }
}

function _handleZoneColorInput(e) {
  var target = e.target;
  var index = parseInt(target.getAttribute('data-zone-index'));
  var field = target.getAttribute('data-zone-field');
  if (isNaN(index) || !field) return;
  if (index < 0 || index >= venueConfig.zones.length) return;

  if (field === 'color') {
    venueConfig.zones[index].color = target.value;
    redrawVenue();
  }
}

function _handleZoneDelete(e) {
  if (e.target.getAttribute('data-zone-action') !== 'delete') return;
  var index = parseInt(e.target.getAttribute('data-zone-index'));
  if (index < 0 || index >= venueConfig.zones.length) return;

  // Must keep at least one zone
  if (venueConfig.zones.length <= 1) return;

  var removedName = venueConfig.zones[index].id;
  venueConfig.zones.splice(index, 1);

  // Fallback: reassign orphaned rows to first available zone
  var fallback = venueConfig.zones[0].id;
  for (var r = 0; r < venueConfig.rows.length; r++) {
    if (venueConfig.rows[r].zone === removedName) {
      venueConfig.rows[r].zone = fallback;
    }
  }

  // Reassign orphaned zoneRects
  for (var z = 0; z < venueConfig.zoneRects.length; z++) {
    if (venueConfig.zoneRects[z].id === removedName) {
      venueConfig.zoneRects[z].id = fallback;
    }
  }

  _populateRowTypeSelect();
  renderZonesList();
  renderRowsList();
  renderZoneRectsList();
  redrawVenue();
}

function _handleAddZone() {
  var newId = 'ZONA_' + (venueConfig.zones.length + 1);
  venueConfig.zones.push({ id: newId, color: '#888888' });
  _populateRowTypeSelect();
  renderZonesList();
}

function _initZoneListeners() {
  var zList = document.getElementById('zonesList');
  if (zList) {
    zList.addEventListener('change', _handleZoneChange);
    zList.addEventListener('input', _handleZoneColorInput);
    zList.addEventListener('click', _handleZoneDelete);
  }

  var addBtn = document.getElementById('addZone');
  if (addBtn) {
    addBtn.addEventListener('click', _handleAddZone);
  }
}

// --- Builder mode toggle ---
document.getElementById('toggleBuilder').addEventListener('click', function() {
  toggleBuilderMode(!_builderMode);
});

// --- Zoom buttons ---
document.getElementById('zoomIn').addEventListener('click', function() { zoomStage(1); });
document.getElementById('zoomOut').addEventListener('click', function() { zoomStage(-1); });
document.getElementById('zoomReset').addEventListener('click', function() {
  fitStageToContent();
});

// --- Export JSON ---
document.getElementById('exportVenue').addEventListener('click', function() {
  var clean = serializeLayout();
  document.getElementById('output').textContent = JSON.stringify(clean, null, 2);
});

// --- Helper: compute next Y position ---
function _getNextY() {
  if (!venueConfig.rows.length) return 120;
  var last = venueConfig.rows[venueConfig.rows.length - 1];
  if (last.type === 'empty') return last.y + (last.height || 30);
  return last.y + 50;
}

// --- Add Row ---
document.getElementById('addRow').addEventListener('click', function() {
  var count = parseInt(document.getElementById('rowCount').value);
  var zone = document.getElementById('rowType').value;
  if (!count) return;

  var newIndex = venueConfig.rows.length;
  var autoLabel = numberToLetters(newIndex);

  venueConfig.rows.push({
    label: autoLabel,
    count: count,
    y: _getNextY(),
    zone: zone,
    gaps: [],
    removedSeats: []
  });

  redrawVenue();
  renderRowsList();
});

// --- Add Empty row (vertical spacer) ---
document.getElementById('addEmpty').addEventListener('click', function() {
  venueConfig.rows.push({
    type: 'empty',
    y: _getNextY(),
    height: 30
  });

  redrawVenue();
  renderRowsList();
});

// --- Add Floor Label ---
document.getElementById('addFloorLabel').addEventListener('click', function() {
  venueConfig.rows.push({
    type: 'floor-label',
    text: 'BALCÓN',
    y: _getNextY()
  });

  redrawVenue();
  renderRowsList();
});

// --- Edit rows (input delegation — live typing, no DOM rebuild) ---
document.getElementById('rowsList').addEventListener('input', function(e) {
  var target = e.target;
  var index = parseInt(target.getAttribute('data-index'));
  var field = target.getAttribute('data-field');
  if (isNaN(index) || !field) return;
  if (index < 0 || index >= venueConfig.rows.length) return;

  var row = venueConfig.rows[index];
  if (field === "count" || field === "height") {
    row[field] = parseInt(target.value) || 0;
  } else if (field === "gaps") {
    row[field] = _gapsToPhysical(parseGapsString(target.value), row.count, row.reverse);
  } else if (field === "removedSeats") {
    row[field] = _removedToPhysical(parseRemovedSeats(target.value), row.count, row.reverse);
  } else if (field === "reverse" || field === "renumber") {
    row[field] = target.checked;
  } else {
    row[field] = target.value;
  }

  redrawVenue();
});

// --- Change event for rows (select + input blur) ---
document.getElementById('rowsList').addEventListener('change', function(e) {
  var target = e.target;
  var index = parseInt(target.getAttribute('data-index'));
  var field = target.getAttribute('data-field');
  if (isNaN(index) || !field) return;
  if (index < 0 || index >= venueConfig.rows.length) return;

  var row = venueConfig.rows[index];
  if (field === "count" || field === "height") {
    row[field] = parseInt(target.value) || 0;
  } else if (field === "gaps") {
    row[field] = _gapsToPhysical(parseGapsString(target.value), row.count, row.reverse);
  } else if (field === "removedSeats") {
    row[field] = _removedToPhysical(parseRemovedSeats(target.value), row.count, row.reverse);
  } else if (field === "reverse" || field === "renumber") {
    row[field] = target.checked;
  } else {
    row[field] = target.value;
  }

  redrawVenue();
  renderRowsList();
});

// --- Row click: delete + toggle-adv ---
document.getElementById('rowsList').addEventListener('click', function(e) {
  var action = e.target.getAttribute('data-action');
  var index  = parseInt(e.target.getAttribute('data-index'));
  if (isNaN(index)) return;

  if (action === 'delete') {
    if (index < 0 || index >= venueConfig.rows.length) return;
    venueConfig.rows.splice(index, 1);
    delete _expandedRows[index];
    redrawVenue();
    renderRowsList();
  } else if (action === 'toggle-adv') {
    _expandedRows[index] = !_expandedRows[index];
    var card = e.target.closest('.ss-row-card');
    if (card) {
      var adv = card.querySelector('.ss-row-card__adv');
      if (adv) adv.style.display = _expandedRows[index] ? '' : 'none';
      e.target.classList.toggle('open', !!_expandedRows[index]);
    }
  }
});

// --- Botones globales: Reversar / Renumerar todas las filas ---
document.getElementById('reverseAllRows').addEventListener('click', function () {
  var seatRows = venueConfig.rows.filter(function(r) { return r.label; });
  var allReversed = seatRows.length > 0 && seatRows.every(function(r) { return r.reverse; });
  seatRows.forEach(function(r) { r.reverse = !allReversed; });
  redrawVenue();
  renderRowsList();
});

document.getElementById('renumberAllRows').addEventListener('click', function () {
  var seatRows = venueConfig.rows.filter(function(r) { return r.label; });
  var allRenumbered = seatRows.length > 0 && seatRows.every(function(r) { return r.renumber; });
  seatRows.forEach(function(r) { r.renumber = !allRenumbered; });
  redrawVenue();
  renderRowsList();
});

// --- Zone Rects form events ---
(function() {
  var zrList = document.getElementById('zoneRectsList');
  if (!zrList) return;

  zrList.addEventListener('input', function(e) {
    var target = e.target;
    var index = parseInt(target.getAttribute('data-zr-index'));
    var field = target.getAttribute('data-zr-field');
    if (isNaN(index) || !field) return;
    if (index < 0 || index >= venueConfig.zoneRects.length) return;

    if (field === 'x' || field === 'y' || field === 'width' || field === 'height') {
      venueConfig.zoneRects[index][field] = parseInt(target.value) || 0;
    } else {
      venueConfig.zoneRects[index][field] = target.value;
    }

    redrawVenue();
  });

  zrList.addEventListener('change', function(e) {
    var target = e.target;
    var index = parseInt(target.getAttribute('data-zr-index'));
    var field = target.getAttribute('data-zr-field');
    if (isNaN(index) || !field) return;
    if (index < 0 || index >= venueConfig.zoneRects.length) return;

    if (field === 'x' || field === 'y' || field === 'width' || field === 'height') {
      venueConfig.zoneRects[index][field] = parseInt(target.value) || 0;
    } else {
      venueConfig.zoneRects[index][field] = target.value;
    }

    redrawVenue();
    renderZoneRectsList();
  });

  zrList.addEventListener('click', function(e) {
    if (e.target.getAttribute('data-zr-action') === 'delete') {
      var index = parseInt(e.target.getAttribute('data-zr-index'));
      if (index < 0 || index >= venueConfig.zoneRects.length) return;
      venueConfig.zoneRects.splice(index, 1);
      redrawVenue();
      renderZoneRectsList();
    }
  });
})();

// --- Add zone rect ---
(function() {
  var btn = document.getElementById('addZoneRect');
  if (!btn) return;

  btn.addEventListener('click', function() {
    var lastY = 100;
    if (venueConfig.zoneRects.length > 0) {
      var last = venueConfig.zoneRects[venueConfig.zoneRects.length - 1];
      lastY = last.y + last.height + 10;
    }

    var defaultZone = venueConfig.zones[0] || { id: "GENERAL" };

    venueConfig.zoneRects.push({
      id: defaultZone.id,
      x: 80,
      y: lastY,
      width: 500,
      height: 60,
    });

    redrawVenue();
    renderZoneRectsList();
  });
})();

// ═══════════════════════════════════════════════════════════════════
//  RESPONSIVE: Resize stage when container size changes
// ═══════════════════════════════════════════════════════════════════

(function () {
  if (typeof ResizeObserver === 'undefined' || !_containerEl) return;

  var _resizeTimer = null;
  var ro = new ResizeObserver(function (entries) {
    // Debounce to avoid excessive redraws
    clearTimeout(_resizeTimer);
    _resizeTimer = setTimeout(function () {
      var newW = _containerEl.clientWidth;
      var newH = _containerEl.clientHeight;
      if (newW > 0 && newH > 0) {
        stage.width(newW);
        stage.height(newH);
        stage.batchDraw();
      }
    }, 100);
  });

  ro.observe(_containerEl);
})();

// ═══════════════════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════════════════

initZoneSystem();
initViewportControls();
_initZoneListeners();
redrawVenue();
renderZonesList();
_populateRowTypeSelect();
renderRowsList();
renderZoneRectsList();
