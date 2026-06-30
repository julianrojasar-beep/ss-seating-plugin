// ═══════════════════════════════════════════════════════════════════
//  SS Check-in Dashboard — QR scan + AJAX validation
//  Depends on: html5-qrcode (loaded by PHP)
//  Data from PHP: window.ssCheckin = { ajaxUrl, nonce }
// ═══════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  // ─── State ───────────────────────────────────────────────────────
  var scanner       = null;
  var isProcessing  = false;  // prevents duplicate scans while AJAX runs
  var lastScannedId = '';     // debounce: ignore same QR within cooldown
  var COOLDOWN_MS   = 3000;  // 3s before same QR can be scanned again

  // ─── DOM refs ────────────────────────────────────────────────────
  var feedbackEl, iconEl, messageEl, detailsEl, cameraStatusEl, historyBody;

  // ─── QR patterns ────────────────────────────────────────────────
  // Individual: SS-SEAT:{sha256_token}
  // Pedido: SS-TICKET:{sha256_token}
  // Legacy: https://domain.com/ss-checkin/{order_id}/{sha256_token}/
  var QR_SEAT   = /^SS-SEAT:([a-f0-9]{64})$/i;
  var QR_NEW    = /^SS-TICKET:([a-f0-9]{64})$/i;
  var QR_LEGACY = /\/ss-checkin\/(\d+)\/([a-f0-9]{64})\/?$/i;

  document.addEventListener('DOMContentLoaded', init);

  function init() {
    feedbackEl     = document.getElementById('ss-checkin-feedback');
    iconEl         = document.getElementById('ss-checkin-icon');
    messageEl      = document.getElementById('ss-checkin-message');
    detailsEl      = document.getElementById('ss-checkin-details');
    cameraStatusEl = document.getElementById('ss-checkin-camera-status');
    historyBody    = document.querySelector('#ss-checkin-history tbody');

    if (!feedbackEl || typeof Html5Qrcode === 'undefined') return;

    startScanner();
  }

  // ═══════════════════════════════════════════════════════════════════
  //  SCANNER
  // ═══════════════════════════════════════════════════════════════════

  function startScanner() {
    scanner = new Html5Qrcode('ss-qr-reader');

    var config = {
      fps:    10,
      qrbox:  { width: 260, height: 260 },
      aspectRatio: 1.0,
    };

    scanner.start(
      { facingMode: 'environment' },
      config,
      onScanSuccess,
      function () { /* ignore scan failures (no QR in frame) */ }
    ).then(function () {
      cameraStatusEl.textContent = 'Cámara activa — apunta al código QR.';
      cameraStatusEl.style.color = '#388e3c';
    }).catch(function (err) {
      cameraStatusEl.textContent = 'Error al iniciar cámara: ' + err;
      cameraStatusEl.style.color = '#d32f2f';
    });
  }

  // ═══════════════════════════════════════════════════════════════════
  //  SCAN HANDLER
  // ═══════════════════════════════════════════════════════════════════

  function onScanSuccess(decodedText) {
    if (isProcessing) return;

    // Intentar formatos: SS-SEAT:{token}, SS-TICKET:{token}, legacy URL
    var token     = '';
    var orderId   = '';
    var isSeatQr  = false;

    var matchSeat = decodedText.match(QR_SEAT);
    if (matchSeat) {
      token    = matchSeat[1];
      isSeatQr = true;
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

    if (!token) {
      showFeedback('invalid', 'QR no reconocido', 'El código escaneado no es un ticket válido.');
      return;
    }

    // Debounce: ignore same token within cooldown
    if (token === lastScannedId) return;
    lastScannedId = token;
    setTimeout(function () { lastScannedId = ''; }, COOLDOWN_MS);

    // Show loading state
    isProcessing = true;
    showFeedback('loading', 'Validando...', '');

    // AJAX call
    var data = new FormData();
    data.append('action',   'ss_validate_ticket_ajax');
    data.append('nonce',    ssCheckin.nonce);
    data.append('token',    token);
    if (isSeatQr) data.append('is_seat_qr', '1');
    if (orderId) data.append('order_id', orderId);

    fetch(ssCheckin.ajaxUrl, {
      method:      'POST',
      body:        data,
      credentials: 'same-origin',
    })
    .then(function (res) { return res.json(); })
    .then(function (response) {
      isProcessing = false;

      if (!response.success || !response.data) {
        showFeedback('invalid', 'Error de servidor', 'Respuesta inesperada.');
        return;
      }

      var d = response.data;

      switch (d.status) {
        case 'valid':
          showFeedback('valid', 'INGRESO REGISTRADO', buildDetailsHTML(d));
          playSound('success');
          addHistoryRow(d, 'valid');
          break;

        case 'already_used':
          showFeedback('already_used', 'YA INGRESADO', buildDetailsHTML(d));
          playSound('warn');
          addHistoryRow(d, 'already_used');
          break;

        default:
          showFeedback('invalid', 'INVÁLIDO', d.message || 'Ticket no válido.');
          playSound('error');
          addHistoryRow({ order_id: orderId, buyer: '—', ticket_type: 'general', ticket_qty: 0 }, 'invalid');
          break;
      }
    })
    .catch(function (err) {
      isProcessing = false;
      showFeedback('invalid', 'Error de red', err.message || 'No se pudo conectar.');
    });
  }

  // ═══════════════════════════════════════════════════════════════════
  //  FEEDBACK UI
  // ═══════════════════════════════════════════════════════════════════

  var STYLES = {
    valid: {
      bg:     '#e8f5e9',
      border: '#4caf50',
      icon:   '\u2705',   // white check mark
      color:  '#2e7d32',
    },
    already_used: {
      bg:     '#fff8e1',
      border: '#ff9800',
      icon:   '\u26A0\uFE0F',  // warning sign
      color:  '#e65100',
    },
    invalid: {
      bg:     '#ffebee',
      border: '#f44336',
      icon:   '\u274C',   // cross mark
      color:  '#c62828',
    },
    loading: {
      bg:     '#e3f2fd',
      border: '#2196f3',
      icon:   '\u23F3',   // hourglass
      color:  '#1565c0',
    },
  };

  function showFeedback(status, title, bodyHTML) {
    var s = STYLES[status] || STYLES.invalid;

    feedbackEl.style.background  = s.bg;
    feedbackEl.style.borderColor = s.border;
    iconEl.textContent           = s.icon;
    messageEl.textContent        = title;
    messageEl.style.color        = s.color;

    if (bodyHTML) {
      detailsEl.innerHTML   = bodyHTML;
      detailsEl.style.display = 'block';
    } else {
      detailsEl.style.display = 'none';
    }
  }

  function buildDetailsHTML(d) {
    var html = '<table style="margin:0 auto; text-align:left; font-size:14px; line-height:1.8;">';
    html += '<tr><td><strong>Pedido:</strong></td><td>#' + esc(d.order_id) + '</td></tr>';
    html += '<tr><td><strong>Comprador:</strong></td><td>' + esc(d.buyer) + '</td></tr>';
    if (d.event) {
      html += '<tr><td><strong>Evento:</strong></td><td>' + esc(d.event) + '</td></tr>';
    }

    if (d.ticket_type === 'seated') {
      if (d.zone) html += '<tr><td><strong>Zona:</strong></td><td>' + esc(d.zone) + '</td></tr>';
      var seatsStr = Array.isArray(d.seats) ? d.seats.join(', ') : '—';
      html += '<tr><td><strong>Sillas:</strong></td><td>' + esc(seatsStr) + '</td></tr>';
    } else {
      html += '<tr><td><strong>Tipo:</strong></td><td>Entrada General</td></tr>';
      if (d.zone) html += '<tr><td><strong>Zona:</strong></td><td>' + esc(d.zone) + '</td></tr>';
      html += '<tr><td><strong>Asiento:</strong></td><td>Sin asignar (orden de llegada)</td></tr>';
    }

    html += '<tr><td><strong>Cantidad:</strong></td><td>' + esc(d.ticket_qty) + '</td></tr>';
    if (d.checkin_time) {
      html += '<tr><td><strong>Hora ingreso:</strong></td><td>' + esc(d.checkin_time) + '</td></tr>';
    }
    html += '</table>';
    return html;
  }

  // ═══════════════════════════════════════════════════════════════════
  //  HISTORY TABLE
  // ═══════════════════════════════════════════════════════════════════

  function addHistoryRow(d, status) {
    if (!historyBody) return;

    var statusLabels = {
      valid:        '<span style="color:#2e7d32; font-weight:600;">Ingresado</span>',
      already_used: '<span style="color:#e65100; font-weight:600;">Ya usado</span>',
      invalid:      '<span style="color:#c62828; font-weight:600;">Inválido</span>',
    };

    var now = new Date();
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
      '<td>' + (statusLabels[status] || status) + '</td>';

    // Insert at top
    historyBody.insertBefore(tr, historyBody.firstChild);

    // Keep max 50 rows
    while (historyBody.children.length > 50) {
      historyBody.removeChild(historyBody.lastChild);
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  //  AUDIO FEEDBACK
  // ═══════════════════════════════════════════════════════════════════

  function playSound(type) {
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      gain.gain.value = 0.15;

      switch (type) {
        case 'success':
          osc.frequency.value = 880;
          osc.type = 'sine';
          osc.start();
          osc.stop(ctx.currentTime + 0.15);
          break;
        case 'warn':
          osc.frequency.value = 440;
          osc.type = 'triangle';
          osc.start();
          osc.stop(ctx.currentTime + 0.3);
          break;
        case 'error':
          osc.frequency.value = 220;
          osc.type = 'square';
          osc.start();
          osc.stop(ctx.currentTime + 0.4);
          break;
      }
    } catch (e) {
      // Web Audio not available — silent fallback
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  //  UTILS
  // ═══════════════════════════════════════════════════════════════════

  function esc(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str || '')));
    return div.innerHTML;
  }

  function pad(n) {
    return n < 10 ? '0' + n : '' + n;
  }

})();
