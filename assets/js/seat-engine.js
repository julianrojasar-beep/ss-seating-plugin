// ═══════════════════════════════════════════════════════════════════
//  SEAT ENGINE — Reusable layout engine (no Konva dependency)
// ═══════════════════════════════════════════════════════════════════

// ─── Gaps helpers — "2:2, 5:1" ↔ [{after:2,size:2},{after:5,size:1}] ───

function parseGapsString(str) {
  if (!str || !str.trim()) return [];
  var parts = str.split(',');
  var result = [];
  for (var i = 0; i < parts.length; i++) {
    var pair = parts[i].split(':');
    var after = parseInt(pair[0]);
    var size = pair.length > 1 ? parseInt(pair[1]) : 1;
    if (!isNaN(after) && after >= 0 && !isNaN(size) && size > 0) {
      result.push({ after: after, size: size });
    }
  }
  return result;
}

function gapsToString(gaps) {
  if (!gaps || !gaps.length) return '';
  var parts = [];
  for (var i = 0; i < gaps.length; i++) {
    parts.push(gaps[i].after + ':' + gaps[i].size);
  }
  return parts.join(', ');
}

function parseRemovedSeats(str) {
  if (!str || !str.trim()) return [];
  return str.split(',')
    .map(function(s) { return parseInt(s.trim()); })
    .filter(function(n) { return !isNaN(n) && n > 0; });
}

function removedSeatsToString(arr) {
  if (!arr || !arr.length) return '';
  return arr.join(', ');
}

// ─── Zone auto-detection ─────────────────────────────────────────

function detectSeatZone(x, y, zoneRects) {
  for (var i = 0; i < zoneRects.length; i++) {
    var zr = zoneRects[i];
    if (x >= zr.x && x <= zr.x + zr.width &&
        y >= zr.y && y <= zr.y + zr.height) {
      return zr.id;
    }
  }
  return null;
}

// ─── Seat generation ─────────────────────────────────────────────
// reverse  — number seats right-to-left (highest number on the left)
// renumber — skip removed positions; visible seats get consecutive numbers

function generateRow(rowLabel, count, startX, startY, spacing, zone, gaps, removedSeats, zoneRects, reverse, renumber) {
  // O(1) lookups
  var gapMap = {};
  for (var g = 0; g < gaps.length; g++) {
    gapMap[gaps[g].after] = gaps[g].size;
  }
  var removedSet = {};
  for (var r = 0; r < removedSeats.length; r++) {
    removedSet[removedSeats[r]] = true;
  }

  // Pre-count visible seats (needed for reverse+renumber combo)
  var totalVisible = 0;
  if (renumber) {
    for (var k = 1; k <= count; k++) {
      if (!removedSet[k]) totalVisible++;
    }
  }

  var seats = [];
  var cursorX = startX;
  var visibleIndex = 0;

  for (var i = 1; i <= count; i++) {
    if (removedSet[i]) {
      cursorX += spacing;
      continue;
    }
    visibleIndex++;

    var seatNum;
    if (renumber) {
      seatNum = reverse ? (totalVisible - visibleIndex + 1) : visibleIndex;
    } else {
      seatNum = reverse ? (count - i + 1) : i;
    }

    var detectedZone = detectSeatZone(cursorX, startY, zoneRects) || zone;
    seats.push({ id: rowLabel + seatNum, x: cursorX, y: startY, zone: detectedZone, physicalSlot: i });
    cursorX += spacing;
    if (gapMap[i] !== undefined) {
      cursorX += gapMap[i] * spacing;
    }
  }
  return seats;
}

function buildSeatsFromConfig(config) {
  var zoneRects = config.zoneRects || [];
  var allSeats = [];
  for (var r = 0; r < config.rows.length; r++) {
    var row = config.rows[r];
    if (row.type === 'empty' || row.type === 'floor-label') continue;
    var rowSeats = generateRow(
      row.label, row.count, config.startX, row.y,
      config.spacing, row.zone, row.gaps || [], row.removedSeats || [],
      zoneRects,
      row.reverse  || false,
      row.renumber || false
    );
    for (var s = 0; s < rowSeats.length; s++) { rowSeats[s].rowIndex = r; }
    allSeats.push.apply(allSeats, rowSeats);
  }
  return allSeats;
}

// ─── Remap computation ────────────────────────────────────────────
// Returns a map {oldId: newId} for seats whose ID changes between
// two row configurations (matched by physical position index).

function computeRowRemap(oldRow, newRow, startX, spacing, zoneRects) {
  var oldSeats = generateRow(
    oldRow.label, oldRow.count, startX, oldRow.y, spacing,
    oldRow.zone, oldRow.gaps || [], oldRow.removedSeats || [], zoneRects,
    oldRow.reverse  || false, oldRow.renumber || false
  );
  var newSeats = generateRow(
    newRow.label, newRow.count, startX, newRow.y, spacing,
    newRow.zone, newRow.gaps || [], newRow.removedSeats || [], zoneRects,
    newRow.reverse  || false, newRow.renumber || false
  );
  var map = {};
  var len = Math.min(oldSeats.length, newSeats.length);
  for (var i = 0; i < len; i++) {
    if (oldSeats[i].id !== newSeats[i].id) {
      map[oldSeats[i].id] = newSeats[i].id;
    }
  }
  return map;
}

// ─── Public API ──────────────────────────────────────────────────

window.SeatEngine = {
  detectSeatZone:    detectSeatZone,
  generateRow:       generateRow,
  buildSeatsFromConfig: buildSeatsFromConfig,
  computeRowRemap:   computeRowRemap
};
