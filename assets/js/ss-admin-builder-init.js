// ═══════════════════════════════════════════════════════════════════
//  SS Admin Builder Init — Adapter between main.js and WP admin
//  Runs AFTER main.js to:
//    1) Load saved layout from hidden input into venueConfig
//    2) Patch exportVenue to write to hidden input instead of #output
//    3) Populate rowType <select> with zones
//    4) Auto-save layout into hidden input on WP post save
//    5) Remap seat IDs when numbering flags change on locked events
// ═══════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  var data    = window.ssBuilderData || {};
  var locked  = !!data.locked;
  var eventId = data.eventId || 0;

  // ─── 1) Load saved layout ───────────────────────────────────────

  var hiddenInput = document.getElementById('ss_layout_hidden');
  if (!hiddenInput) return;

  var savedJson = hiddenInput.value;
  var _savedLayout = null; // keep original for remap diff

  if (savedJson && savedJson.trim() !== '') {
    try {
      var saved = JSON.parse(savedJson);
      if (saved && typeof saved === 'object') {
        _savedLayout = JSON.parse(savedJson); // deep copy for remap

        // Normalize legacy format (rows[]) → multi-floor format (floors[])
        if (!saved.floors) {
          saved.floors = [{
            id:        'piso-1',
            label:     'Principal',
            rows:      saved.rows      || [],
            zoneRects: saved.zoneRects || [],
            layout:    saved.layout    || venueConfig.layout
          }];
        }

        // Load global settings
        if (saved.zones)   venueConfig.zones   = saved.zones;
        if (saved.startX   !== undefined) venueConfig.startX  = saved.startX;
        if (saved.spacing  !== undefined) venueConfig.spacing = saved.spacing;

        // Initialize floors and load first floor
        venueConfig.floors = saved.floors;
        var f0 = saved.floors[0] || {};
        venueConfig.rows      = f0.rows      || [];
        venueConfig.zoneRects = f0.zoneRects || [];
        venueConfig.layout    = f0.layout    || venueConfig.layout;

        redrawVenue();
        renderFloorTabs();
        renderZonesList();
        _populateRowTypeSelect();
        renderRowsList();
        renderZoneRectsList();
        fitStageToContent();
      }
    } catch (e) {
      console.warn('[ss-builder] Error parsing saved layout:', e);
    }
  }

  // Ensure floors always initialized (handles new/empty events)
  if (!venueConfig.floors) {
    venueConfig.floors = [{
      id:    'piso-1',
      label: 'Principal',
      rows:      venueConfig.rows,
      zoneRects: venueConfig.zoneRects,
      layout:    venueConfig.layout
    }];
    renderFloorTabs();
  }

  // ─── 2) Patch export button — write to hidden input ────────────

  var exportBtn = document.getElementById('exportVenue');
  if (exportBtn) {
    var newBtn = exportBtn.cloneNode(true);
    exportBtn.parentNode.replaceChild(newBtn, exportBtn);

    newBtn.addEventListener('click', function () {
      var clean = serializeLayout();
      var json  = JSON.stringify(clean);
      hiddenInput.value = json;

      var outputEl = document.getElementById('output');
      if (outputEl) {
        outputEl.textContent = JSON.stringify(clean, null, 2);
      }

      var origText = newBtn.textContent;
      newBtn.textContent = 'Layout guardado ✓';
      newBtn.disabled = true;
      setTimeout(function () {
        newBtn.textContent = origText;
        newBtn.disabled = false;
      }, 1500);
    });
  }

  // ─── 3) Auto-save layout into hidden input on WP form submit ──

  var postForm = document.getElementById('post');
  if (postForm) {
    postForm.addEventListener('submit', function () {
      try {
        var clean = serializeLayout();
        hiddenInput.value = JSON.stringify(clean);
      } catch (e) {
        console.warn('[ss-builder] Error serializing on submit:', e);
      }
    });
  }

  // ─── 4) Auto-compute remap on submit (only when event is locked) ──
  // No separate "remap" button anymore: any normal save (Actualizar) that
  // changes seat numbering now computes the {oldId:newId} diff itself and
  // sends it along with the layout, so ss_seating_save_metabox() can
  // re-key existing orders/ledger atomically in the same request. The
  // server has the final say — it blocks the save if a removed seat still
  // has a real sale/reservation attached (see ss-seating-plugin.php).

  if (!locked || !eventId) return;

  function flattenRows(layout) {
    if (!layout) return [];
    if (Array.isArray(layout.floors)) {
      var rows = [];
      for (var f = 0; f < layout.floors.length; f++) {
        rows = rows.concat((layout.floors[f] && layout.floors[f].rows) || []);
      }
      return rows;
    }
    return layout.rows || [];
  }

  // Los elementos nuevos van DESPUÉS de #ss-admin-builder-wrapper, no dentro:
  // ese wrapper es un contenedor flex de 2 columnas (canvas + sidebar) y
  // agregarle hijos de más ahí adentro (aunque sean <p>/<details>) rompe el
  // layout y el canvas de Konva termina con ancho 0 (invisible).
  var wrapperEl = document.getElementById('ss-admin-builder-wrapper') || hiddenInput.parentNode;
  var anchorParent = wrapperEl.parentNode;
  var anchorAfter  = wrapperEl;

  function insertAfterAnchor(el) {
    anchorParent.insertBefore(el, anchorAfter.nextSibling);
    anchorAfter = el;
  }

  var remapInput = document.createElement('input');
  remapInput.type = 'hidden';
  remapInput.name = 'ss_layout_remap';
  remapInput.id   = 'ss_layout_remap_hidden';
  insertAfterAnchor(remapInput);

  var removedInput = document.createElement('input');
  removedInput.type = 'hidden';
  removedInput.name = 'ss_layout_removed';
  removedInput.id   = 'ss_layout_removed_hidden';
  insertAfterAnchor(removedInput);

  var lockedNotice = document.createElement('p');
  lockedNotice.style.cssText = 'color:#6b7280;font-size:12px;margin:8px 0 0;';
  lockedNotice.textContent = 'Este evento tiene ventas — al guardar, las sillas cuyo número cambie se re-etiquetan automáticamente en pedidos, ledger y reservas existentes.';
  insertAfterAnchor(lockedNotice);

  if (postForm) {
    postForm.addEventListener('submit', function () {
      if (!_savedLayout) return; // nada guardado aún contra qué comparar

      var currentConfig = serializeLayout();
      var oldRows = flattenRows(_savedLayout);
      var newRows = flattenRows(currentConfig);

      var oldByLabel = {};
      for (var i = 0; i < oldRows.length; i++) {
        if (oldRows[i].label) oldByLabel[oldRows[i].label] = oldRows[i];
      }

      var fullRemap = {};
      var fullRemoved = [];
      for (var j = 0; j < newRows.length; j++) {
        var newRow = newRows[j];
        if (!newRow.label) continue;
        var oldRow = oldByLabel[newRow.label];
        if (!oldRow) continue;

        var result = SeatEngine.computeRowRemap(
          oldRow, newRow,
          currentConfig.startX || 100,
          currentConfig.spacing || 45,
          []
        );
        for (var k in result.map) {
          if (result.map.hasOwnProperty(k)) fullRemap[k] = result.map[k];
        }
        fullRemoved = fullRemoved.concat(result.removed);
      }

      remapInput.value   = JSON.stringify(fullRemap);
      removedInput.value = JSON.stringify(fullRemoved);
    });
  }

  // ─── Repair tool (patch ledger + meta without touching orders) ──
  // Lista de pares "silla actual → silla nueva" con filas que se agregan de
  // a una (sin JSON a mano). Solo actualiza ledger y reservas, útil cuando
  // el remap automático no cubrió todo por un _savedLayout desincronizado.

  var repairDetails = document.createElement('details');
  repairDetails.style.cssText = 'margin-top:10px;font-size:12px;';
  repairDetails.innerHTML =
    '<summary style="cursor:pointer;color:#6b7280;">Reparación manual de IDs</summary>' +
    '<div style="margin-top:6px;padding:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;">' +
      '<p style="margin:0 0 8px;color:#374151;">Para sillas que el remap automático no ajustó bien ' +
      '(solo actualiza ledger y reservas, <strong>no</strong> toca pedidos):</p>' +
      '<div id="ss-patch-rows"></div>' +
      '<button type="button" id="ss-patch-add-row" style="margin-top:4px;background:#fff;color:#374151;border:1px solid #d1d5db;padding:4px 10px;border-radius:4px;cursor:pointer;">+ Agregar par</button>' +
      '<br>' +
      '<button type="button" id="ss-patch-btn" style="margin-top:8px;background:#7c3aed;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;">Aplicar parche</button>' +
      '<span id="ss-patch-msg" style="margin-left:8px;font-size:11px;"></span>' +
    '</div>';

  insertAfterAnchor(repairDetails);

  var patchRowsEl = document.getElementById('ss-patch-rows');

  function addPatchRow() {
    var row = document.createElement('div');
    row.style.cssText = 'margin-bottom:4px;';
    row.innerHTML =
      '<input type="text" class="ss-patch-old" size="6" placeholder="D1"> → ' +
      '<input type="text" class="ss-patch-new" size="6" placeholder="D11"> ' +
      '<button type="button" class="ss-patch-row-remove" style="color:#dc2626;background:none;border:none;cursor:pointer;">✕</button>';
    row.querySelector('.ss-patch-row-remove').addEventListener('click', function () {
      row.remove();
    });
    patchRowsEl.appendChild(row);
  }
  addPatchRow();
  document.getElementById('ss-patch-add-row').addEventListener('click', addPatchRow);

  document.getElementById('ss-patch-btn').addEventListener('click', function () {
    var patchMsg = document.getElementById('ss-patch-msg');
    var patchBtn = document.getElementById('ss-patch-btn');

    var patchRemap = {};
    var oldInputs = patchRowsEl.querySelectorAll('.ss-patch-old');
    var newInputs = patchRowsEl.querySelectorAll('.ss-patch-new');
    for (var i = 0; i < oldInputs.length; i++) {
      var oldVal = oldInputs[i].value.trim();
      var newVal = newInputs[i].value.trim();
      if (oldVal && newVal) { patchRemap[oldVal] = newVal; }
    }

    var keys = Object.keys(patchRemap);
    if (keys.length === 0) { patchMsg.textContent = 'Completá al menos un par.'; return; }

    if (!confirm('Se aplicará el parche a ' + keys.length + ' asiento(s) en el ledger y las metas de reservas.\n\n' +
        keys.slice(0, 10).map(function(o){ return o + ' → ' + patchRemap[o]; }).join('\n') +
        (keys.length > 10 ? '\n...y ' + (keys.length - 10) + ' más' : '') +
        '\n\n¿Continuar?')) { return; }

    patchBtn.disabled = true;
    patchMsg.textContent = 'Aplicando...';

    var xhr2 = new XMLHttpRequest();
    xhr2.open('POST', data.ajaxUrl, true);
    xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr2.onload = function () {
      var resp;
      try { resp = JSON.parse(xhr2.responseText); } catch(e) { resp = null; }
      if (resp && resp.success) {
        patchMsg.style.color = '#059669';
        patchMsg.textContent = 'Parche aplicado ✓ (' + JSON.stringify(resp.data) + ')';
      } else {
        patchMsg.style.color = '#dc2626';
        patchMsg.textContent = 'Error: ' + ((resp && resp.data) ? resp.data : 'desconocido');
        patchBtn.disabled = false;
      }
    };
    xhr2.onerror = function () {
      patchMsg.style.color = '#dc2626';
      patchMsg.textContent = 'Error de red.';
      patchBtn.disabled = false;
    };
    xhr2.send(
      'action=ss_patch_seats' +
      '&nonce=' + encodeURIComponent(data.nonce) +
      '&event_id=' + encodeURIComponent(eventId) +
      '&patch_remap=' + encodeURIComponent(JSON.stringify(patchRemap))
    );
  });

  // ─── Renombrar una silla individual ──────────────────────────────
  // Para casos que no encajan con removedSeats/renumber (ej. una silla
  // suelta con un tag distinto al que le tocaría por posición). Usa el
  // mismo motor que el remap automático (ss_remap_event_seats), solo que
  // con un único par {old:new} en vez de uno calculado por fila.

  var renameDetails = document.createElement('details');
  renameDetails.style.cssText = 'margin-top:10px;font-size:12px;';
  renameDetails.innerHTML =
    '<summary style="cursor:pointer;color:#6b7280;">Renombrar una silla individual</summary>' +
    '<div style="margin-top:6px;padding:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;">' +
      '<label style="margin-right:8px;">Silla actual: <input type="text" id="ss-rename-old" size="6" placeholder="F5"></label>' +
      '<label style="margin-right:8px;">Nuevo tag: <input type="text" id="ss-rename-new" size="6" placeholder="F5B"></label>' +
      '<button type="button" id="ss-rename-btn" style="margin-top:4px;background:#7c3aed;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;">Renombrar</button>' +
      '<span id="ss-rename-msg" style="margin-left:8px;font-size:11px;"></span>' +
    '</div>';

  insertAfterAnchor(renameDetails);

  document.getElementById('ss-rename-btn').addEventListener('click', function () {
    var oldId = (document.getElementById('ss-rename-old').value || '').trim();
    var newId = (document.getElementById('ss-rename-new').value || '').trim();
    var renameMsg = document.getElementById('ss-rename-msg');
    var renameBtn = document.getElementById('ss-rename-btn');

    if (!oldId || !newId) { renameMsg.textContent = 'Completá ambos campos.'; return; }
    if (oldId === newId) { renameMsg.textContent = 'Los IDs son iguales.'; return; }

    if (!confirm('Se va a renombrar el asiento "' + oldId + '" a "' + newId + '" en ledger, pedidos, check-ins y reservas.\n\n¿Continuar?')) return;

    renameBtn.disabled = true;
    renameMsg.textContent = 'Aplicando...';

    var xhr3 = new XMLHttpRequest();
    xhr3.open('POST', data.ajaxUrl, true);
    xhr3.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr3.onload = function () {
      var resp;
      try { resp = JSON.parse(xhr3.responseText); } catch(e) { resp = null; }
      if (resp && resp.success) {
        renameMsg.style.color = '#059669';
        renameMsg.textContent = 'Listo ✓ (' + (resp.data.updated || 0) + ' pedidos actualizados)';
      } else {
        renameMsg.style.color = '#dc2626';
        renameMsg.textContent = 'Error: ' + ((resp && resp.data) ? resp.data : 'desconocido');
      }
      renameBtn.disabled = false;
    };
    xhr3.onerror = function () {
      renameMsg.style.color = '#dc2626';
      renameMsg.textContent = 'Error de red.';
      renameBtn.disabled = false;
    };
    xhr3.send(
      'action=ss_rename_single_seat' +
      '&nonce=' + encodeURIComponent(data.nonce) +
      '&event_id=' + encodeURIComponent(eventId) +
      '&old_id=' + encodeURIComponent(oldId) +
      '&new_id=' + encodeURIComponent(newId)
    );
  });

})();
