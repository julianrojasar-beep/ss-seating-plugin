// ═══════════════════════════════════════════════════════════════════
//  SS Control de Ingreso — Frontend QR scanner + AJAX validation
//  Depends on: html5-qrcode (loaded inline)
//  Data: window.ssCheckinFrontend = { ajaxUrl, nonce, eventId }
// ═══════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  var scanner      = null;
  var isProcessing = false;
  var lastScanKey  = '';
  var COOLDOWN_MS  = 3000;

  // QR patterns: SS-SEAT:{token} (asiento individual), SS-ZONETICKET:{token} (ticket zona individual),
  //               SS-TICKET:{token} (pedido), legacy URL
  var QR_SEAT       = /^SS-SEAT:([a-f0-9]{64})$/i;
  var QR_ZONETICKET = /^SS-ZONETICKET:([a-f0-9]{64})$/i;
  var QR_NEW        = /^SS-TICKET:([a-f0-9]{64})$/i;
  var QR_LEGACY     = /\/ss-checkin\/(\d+)\/([a-f0-9]{64})\/?$/i;

  // DOM refs
  var feedbackEl, iconEl, msgEl, detailsEl, cameraStatus, historyBody, counterEl;

  document.addEventListener('DOMContentLoaded', init);

  function init() {
    if (typeof Html5Qrcode === 'undefined' || !window.ssCheckinFrontend) return;

    feedbackEl   = document.getElementById('ci-feedback');
    iconEl       = document.getElementById('ci-icon');
    msgEl        = document.getElementById('ci-msg');
    detailsEl    = document.getElementById('ci-details');
    cameraStatus = document.getElementById('ci-camera-status');
    historyBody  = document.getElementById('ci-history-body');
    counterEl    = document.getElementById('ci-count');

    if (!feedbackEl) return;

    startScanner();
    initStatsPolling();
  }

  // ═══════════════════════════════════════════════════════════════════
  //  SCANNER
  // ═══════════════════════════════════════════════════════════════════

  function startScanner() {
    scanner = new Html5Qrcode('ci-qr-reader');

    scanner.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 260, height: 260 }, aspectRatio: 1.0 },
      onScanSuccess,
      function () {}
    ).then(function () {
      cameraStatus.textContent = 'Cámara activa — apunta al código QR.';
      cameraStatus.style.color = '#4caf50';
    }).catch(function (err) {
      cameraStatus.textContent = 'Error al iniciar cámara: ' + err;
      cameraStatus.style.color = '#f44336';
    });
  }

  // ═══════════════════════════════════════════════════════════════════
  //  SCAN HANDLER
  // ═══════════════════════════════════════════════════════════════════

  function onScanSuccess(decodedText) {
    if (isProcessing) return;

    // Intentar formatos: SS-SEAT:{token}, SS-ZONETICKET:{token}, SS-TICKET:{token}, legacy URL
    var token       = '';
    var orderId     = '';
    var isSeatQr    = false;
    var isTicketQr  = false;

    var matchSeat = decodedText.match(QR_SEAT);
    if (matchSeat) {
      token    = matchSeat[1];
      isSeatQr = true;
    } else {
      var matchZoneTicket = decodedText.match(QR_ZONETICKET);
      if (matchZoneTicket) {
        token      = matchZoneTicket[1];
        isTicketQr = true;
      } else {
        var matchNew = decodedText.match(QR_NEW);
        if (matchNew) {
          token = matchNew[1];
        } else {
          var matchLegacy = decodedText.match(QR_LEGACY);
          if (matchLegacy) {
            orderId = matchLegacy[1];
            token   = matchLegacy[2];
          }
        }
      }
    }

    if (!token) {
      showFeedback('invalid', 'QR no reconocido', 'El código escaneado no corresponde a un ticket válido.');
      return;
    }

    // Debounce same token
    if (token === lastScanKey) return;
    lastScanKey = token;
    setTimeout(function () { lastScanKey = ''; }, COOLDOWN_MS);

    isProcessing = true;
    showFeedback('loading', 'Validando...', '');

    var cfg  = window.ssCheckinFrontend;
    var data = new FormData();
    data.append('action',   'ss_validate_ticket_ajax');
    data.append('nonce',    cfg.nonce);
    data.append('token',    token);
    if (isSeatQr)   data.append('is_seat_qr', '1');
    if (isTicketQr) data.append('is_ticket_qr', '1');
    if (orderId)    data.append('order_id', orderId);
    data.append('event_id', cfg.eventId);

    fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (response) {
        isProcessing = false;

        if (!response.success && response.data && response.data.message) {
          showFeedback('invalid', 'Error', response.data.message);
          return;
        }
        if (!response.data) {
          showFeedback('invalid', 'Error', 'Respuesta inesperada del servidor.');
          return;
        }

        var d = response.data;

        // Update counter
        if (typeof d.checkin_count !== 'undefined' && counterEl) {
          counterEl.textContent = d.checkin_count;
        }

        switch (d.status) {
          case 'valid':
            showFeedback('valid', 'INGRESO REGISTRADO', buildDetails(d));
            playSound('success');
            addHistory(d, 'valid');
            break;

          case 'already_used':
            showFeedback('already', 'YA INGRESADO', buildDetails(d));
            playSound('warn');
            addHistory(d, 'already_used');
            break;

          default:
            showFeedback('invalid', 'INVÁLIDO', d.message || 'Ticket no válido.');
            playSound('error');
            addHistory({ order_id: orderId, buyer: '—', ticket_type: 'general', ticket_qty: 0 }, 'invalid');
            break;
        }
      })
      .catch(function (err) {
        isProcessing = false;
        showFeedback('invalid', 'Error de red', err.message || 'Sin conexión.');
      });
  }

  // ═══════════════════════════════════════════════════════════════════
  //  FEEDBACK
  // ═══════════════════════════════════════════════════════════════════

  function showFeedback(status, title, bodyHTML) {
    var icons = { valid: '\u2705', already: '\u26A0\uFE0F', invalid: '\u274C', loading: '\u23F3' };

    feedbackEl.className = 'ci-feedback ci-' + status;
    iconEl.textContent   = icons[status] || icons.invalid;
    msgEl.textContent    = title;

    if (bodyHTML) {
      detailsEl.innerHTML     = bodyHTML;
      detailsEl.style.display = 'block';
    } else {
      detailsEl.style.display = 'none';
    }
  }

  function buildDetails(d) {
    var html = '<table>';
    html += '<tr><td>Pedido:</td><td>#' + esc(d.order_id) + '</td></tr>';
    html += '<tr><td>Comprador:</td><td>' + esc(d.buyer) + '</td></tr>';
    if (d.event) html += '<tr><td>Evento:</td><td>' + esc(d.event) + '</td></tr>';

    if (d.ticket_type === 'seated') {
      // Seated ticket
      if (d.zone) html += '<tr><td>Zona:</td><td>' + esc(d.zone) + '</td></tr>';
      var seatsStr = Array.isArray(d.seats) ? d.seats.join(', ') : '—';
      html += '<tr><td>Sillas:</td><td><strong>' + esc(seatsStr) + '</strong></td></tr>';
    } else {
      // General ticket
      html += '<tr><td>Tipo:</td><td><strong>Entrada General</strong></td></tr>';
      if (d.zone) html += '<tr><td>Zona:</td><td>' + esc(d.zone) + '</td></tr>';
      html += '<tr><td>Asiento:</td><td>Sin asignar (orden de llegada)</td></tr>';
    }

    html += '<tr><td>Cantidad:</td><td><strong>' + esc(d.ticket_qty) + '</strong></td></tr>';
    if (d.checkin_time) html += '<tr><td>Hora:</td><td>' + esc(d.checkin_time) + '</td></tr>';
    html += '</table>';
    return html;
  }

  // ═══════════════════════════════════════════════════════════════════
  //  HISTORY TABLE
  // ═══════════════════════════════════════════════════════════════════

  function addHistory(d, status) {
    if (!historyBody) return;

    var badges = {
      valid:        '<span class="ci-badge ci-badge-ok">Ingresado</span>',
      already_used: '<span class="ci-badge ci-badge-used">Ya usado</span>',
      invalid:      '<span class="ci-badge ci-badge-bad">Inválido</span>',
    };

    var now  = new Date();
    var time = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

    var typeLabel = d.ticket_type === 'seated' ? 'SEAT' : 'GENERAL';
    var detail = '';
    if (d.ticket_type === 'seated' && Array.isArray(d.seats)) {
      detail = d.seats.join(', ');
    } else {
      detail = (d.ticket_qty || 0) + ' entrada' + ((d.ticket_qty || 0) !== 1 ? 's' : '');
    }

    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td>' + time + '</td>' +
      '<td>#' + esc(d.order_id || '—') + '</td>' +
      '<td>' + esc(d.buyer || '—') + '</td>' +
      '<td>' + esc(typeLabel) + '</td>' +
      '<td>' + esc(detail) + '</td>' +
      '<td>' + (badges[status] || status) + '</td>';

    historyBody.insertBefore(tr, historyBody.firstChild);

    while (historyBody.children.length > 50) {
      historyBody.removeChild(historyBody.lastChild);
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  //  AUDIO
  // ═══════════════════════════════════════════════════════════════════

  function playSound(type) {
    try {
      var ctx  = new (window.AudioContext || window.webkitAudioContext)();
      var osc  = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      gain.gain.value = 0.15;

      if (type === 'success') {
        osc.frequency.value = 880; osc.type = 'sine';
        osc.start(); osc.stop(ctx.currentTime + 0.15);
      } else if (type === 'warn') {
        osc.frequency.value = 440; osc.type = 'triangle';
        osc.start(); osc.stop(ctx.currentTime + 0.3);
      } else {
        osc.frequency.value = 220; osc.type = 'square';
        osc.start(); osc.stop(ctx.currentTime + 0.4);
      }
    } catch (e) { /* silent */ }
  }

  // ═══════════════════════════════════════════════════════════════════
  //  UTILS
  // ═══════════════════════════════════════════════════════════════════

  function esc(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s || '')));
    return d.innerHTML;
  }

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  // ═══════════════════════════════════════════════════════════════════
  //  STATS PANEL — polling cada 5 segundos
  // ═══════════════════════════════════════════════════════════════════

  function initStatsPolling() {
    var cfg = window.ssCheckinFrontend;
    if (!cfg || !cfg.eventId) return;

    var statsGrid  = document.getElementById('ci-stats-grid');
    var statsTotal = document.getElementById('ci-stats-total');
    if (!statsGrid) return;

    function fetchStats() {
      var data = new FormData();
      data.append('action', 'ss_get_event_checkin_stats');
      data.append('nonce', cfg.nonce);
      data.append('event_id', cfg.eventId);

      fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (response) {
          if (!response.success || !response.data) return;

          var d = response.data;
          var zones = d.zones || {};
          var html = '';

          var zoneNames = Object.keys(zones);
          for (var i = 0; i < zoneNames.length; i++) {
            var name = zoneNames[i];
            var z = zones[name];
            var pct = z.capacity > 0 ? Math.round(z.entered / z.capacity * 100) : 0;
            var barColor = pct >= 90 ? '#e94560' : pct >= 70 ? '#ff9800' : '#4caf50';

            html += '<div class="ci-zone-card">'
                 +    '<div class="ci-zone-name">' + esc(name) + '</div>'
                 +    '<div class="ci-zone-numbers">' + z.entered
                 +      ' <span class="ci-zone-cap">/ ' + z.capacity + '</span>'
                 +    '</div>'
                 +    '<div class="ci-zone-bar">'
                 +      '<div class="ci-zone-bar-fill" style="width:' + Math.min(pct, 100) + '%;background:' + barColor + ';"></div>'
                 +    '</div>'
                 +  '</div>';
          }

          statsGrid.innerHTML = html;

          if (statsTotal) {
            statsTotal.textContent = 'TOTAL INGRESADOS: ' + (d.total_entered || 0);
          }

          // Actualizar contador del header también
          if (counterEl && typeof d.total_entered !== 'undefined') {
            counterEl.textContent = d.total_entered;
          }
        })
        .catch(function () { /* silencioso */ });
    }

    // Fetch inmediato + cada 5 segundos
    fetchStats();
    setInterval(fetchStats, 5000);
  }

})();
