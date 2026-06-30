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

  // ─── 4) Remap button (only when event is locked) ─────────────

  if (!locked || !eventId) return;

  // Inject the remap button after the export button
  var exportArea = document.getElementById('exportVenue') || document.querySelector('[id="exportVenue"]');
  var remapBtn = document.createElement('button');
  remapBtn.type = 'button';
  remapBtn.id   = 'ss-remap-btn';
  remapBtn.textContent = 'Cambiar numeración (remap)';
  remapBtn.style.cssText = 'margin-left:8px;background:#d97706;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:13px;';
  remapBtn.title = 'Recalcula y actualiza los IDs de las sillas vendidas según la nueva configuración de numeración';

  if (exportArea && exportArea.parentNode) {
    exportArea.parentNode.insertBefore(remapBtn, exportArea.nextSibling);
  }

  // Also inject info notice
  var notice = document.createElement('p');
  notice.style.cssText = 'color:#b45309;font-size:12px;margin:4px 0 0;';
  notice.textContent = 'Este evento tiene ventas. Cambios de numeración (Rev./Renum.) requieren el botón "Cambiar numeración".';
  if (remapBtn.parentNode) {
    remapBtn.parentNode.insertBefore(notice, remapBtn.nextSibling);
  }

  remapBtn.addEventListener('click', function () {
    if (!_savedLayout) {
      alert('No se pudo cargar el layout original. Recargá la página.');
      return;
    }

    var currentConfig = serializeLayout();
    var zoneRects = currentConfig.zoneRects || [];

    // Compute full remap across all rows
    var fullRemap = {};
    var oldRows = _savedLayout.rows || [];
    var newRows = currentConfig.rows || [];

    // Match rows by label (skip floor-label / empty rows)
    var oldByLabel = {};
    for (var i = 0; i < oldRows.length; i++) {
      if (oldRows[i].label) oldByLabel[oldRows[i].label] = oldRows[i];
    }

    for (var j = 0; j < newRows.length; j++) {
      var newRow = newRows[j];
      if (!newRow.label) continue;
      var oldRow = oldByLabel[newRow.label];
      if (!oldRow) continue;

      var rowMap = SeatEngine.computeRowRemap(
        oldRow, newRow,
        currentConfig.startX || 100,
        currentConfig.spacing || 45,
        zoneRects
      );
      for (var k in rowMap) {
        if (rowMap.hasOwnProperty(k)) {
          fullRemap[k] = rowMap[k];
        }
      }
    }

    if (Object.keys(fullRemap).length === 0) {
      alert('No hay cambios de numeración detectados entre el layout guardado y el actual.');
      return;
    }

    // Build preview HTML
    var rows = Object.keys(fullRemap).map(function(old) {
      return '<tr><td style="padding:2px 8px;">' + old + '</td><td style="padding:2px 8px;">→</td><td style="padding:2px 8px;font-weight:600;">' + fullRemap[old] + '</td></tr>';
    });

    var total = rows.length;
    var preview = rows.slice(0, 20).join('');
    if (total > 20) {
      preview += '<tr><td colspan="3" style="color:#666;padding:4px 8px;">...y ' + (total - 20) + ' más</td></tr>';
    }

    var confirmed = confirm(
      'Se renombrarán ' + total + ' asiento(s) en pedidos, ledger y reservas:\n\n' +
      Object.keys(fullRemap).slice(0, 10).map(function(o){ return o + ' → ' + fullRemap[o]; }).join('\n') +
      (total > 10 ? '\n...y ' + (total - 10) + ' más' : '') +
      '\n\n¿Continuar? Esta operación NO puede deshacerse.'
    );

    if (!confirmed) return;

    remapBtn.disabled = true;
    remapBtn.textContent = 'Aplicando...';

    var newLayoutJson = JSON.stringify(currentConfig);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', data.ajaxUrl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
      var resp;
      try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }
      if (resp && resp.success) {
        // Update hidden input with new layout
        hiddenInput.value = newLayoutJson;
        _savedLayout = JSON.parse(newLayoutJson);

        remapBtn.textContent = 'Remap aplicado ✓ (' + (resp.data.updated || 0) + ' pedidos)';
        remapBtn.style.background = '#059669';
        notice.textContent = 'Remap completado. Guarda el evento para confirmar el nuevo layout.';
        notice.style.color = '#065f46';
      } else {
        var msg = (resp && resp.data) ? resp.data : 'Error desconocido';
        alert('Error al aplicar el remap: ' + msg);
        remapBtn.disabled = false;
        remapBtn.textContent = 'Cambiar numeración (remap)';
      }
    };
    xhr.onerror = function () {
      alert('Error de red al aplicar el remap.');
      remapBtn.disabled = false;
      remapBtn.textContent = 'Cambiar numeración (remap)';
    };

    var params = 'action=ss_remap_seats' +
      '&nonce=' + encodeURIComponent(data.nonce) +
      '&event_id=' + encodeURIComponent(eventId) +
      '&remap=' + encodeURIComponent(JSON.stringify(fullRemap)) +
      '&new_layout=' + encodeURIComponent(newLayoutJson);

    xhr.send(params);
  });

  // ─── Repair tool (patch ledger + meta without touching orders) ──
  // Shows a collapsible section where admin can paste a raw remap JSON
  // and apply it to only the ledger and reserved-seat metas. Useful when
  // the automatic remap missed some seats due to a stale _savedLayout.

  var repairDetails = document.createElement('details');
  repairDetails.style.cssText = 'margin-top:10px;font-size:12px;';
  repairDetails.innerHTML =
    '<summary style="cursor:pointer;color:#6b7280;">Reparación manual de IDs</summary>' +
    '<div style="margin-top:6px;padding:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;">' +
      '<p style="margin:0 0 6px;color:#374151;">Pega aquí el JSON del remap que no se aplicó correctamente ' +
      '(solo actualiza ledger y reservas, <strong>no</strong> toca pedidos):</p>' +
      '<textarea id="ss-patch-json" rows="4" style="width:100%;font-size:11px;font-family:monospace;" ' +
      'placeholder=\'{"D1":"D11","D2":"D10"}\'></textarea>' +
      '<button type="button" id="ss-patch-btn" style="margin-top:4px;background:#7c3aed;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;">Aplicar parche</button>' +
      '<span id="ss-patch-msg" style="margin-left:8px;font-size:11px;"></span>' +
    '</div>';

  if (remapBtn.parentNode) {
    remapBtn.parentNode.insertBefore(repairDetails, notice.nextSibling || null);
  }

  document.getElementById('ss-patch-btn').addEventListener('click', function () {
    var raw = (document.getElementById('ss-patch-json').value || '').trim();
    var patchMsg = document.getElementById('ss-patch-msg');
    var patchBtn = document.getElementById('ss-patch-btn');
    if (!raw) { patchMsg.textContent = 'El campo está vacío.'; return; }

    var patchRemap;
    try { patchRemap = JSON.parse(raw); } catch(e) { patchMsg.textContent = 'JSON inválido.'; return; }
    if (typeof patchRemap !== 'object' || Array.isArray(patchRemap)) {
      patchMsg.textContent = 'Debe ser un objeto {"old":"new",...}'; return;
    }

    var keys = Object.keys(patchRemap);
    if (keys.length === 0) { patchMsg.textContent = 'El remap está vacío.'; return; }

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

})();
