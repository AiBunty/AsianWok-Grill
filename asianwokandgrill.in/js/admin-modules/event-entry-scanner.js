import { adminApi } from './api-client.js';

const QUEUE_STORAGE_KEY = 'awg:event-scanner:queue:v2';

export async function renderEventEntryScanner(container) {
  container.innerHTML = `
    <section class="admin-module-card es-page">
      <header class="es-header">
        <h3>Event Entry Scanner</h3>
        <p>Scan multiple guest QRs and confirm gate entry in batches</p>
      </header>

      <section class="es-panel">
        <h4>Event Entry Scanner</h4>
        <p>Use the device camera for walk-in scanning, or paste QR text in bulk. Each scan is cached locally first, then the final save sends the batch to server.</p>
      </section>

      <section class="es-toolbar">
        <div class="es-toolbar-left">
          <select id="esEvent" class="admin-input"></select>
          <input id="esPasscode" type="password" placeholder="Entry passcode" autocomplete="off" />
        </div>
        <div class="es-toolbar-right">
          <span id="esEventBadge" class="admin-status-badge">-</span>
        </div>
      </section>

      <section class="es-grid">
        <aside class="es-panel es-camera-panel">
          <div class="es-panel-head-tight">
            <h4>Camera Scanner</h4>
            <span id="esCameraState" class="admin-status-badge">Idle</span>
          </div>
          <p class="es-subtle">Best for gate entry. Scans are captured locally with audible feedback.</p>
          <div id="esCameraPreview" class="es-camera-preview"></div>
          <select id="esCameraSelect" class="admin-input"></select>
          <div class="admin-form-actions" style="margin:0;">
            <button id="esStartCamera" class="admin-button" type="button">Start Scanner</button>
            <button id="esStopCamera" class="admin-button admin-button-secondary" type="button" disabled>Stop Scanner</button>
          </div>
          <div id="esCameraStatus" class="admin-form-status">Scanner idle.</div>

          <div class="es-mini-metrics">
            <article><span>Cached</span><strong id="esQueueCount">0</strong></article>
            <article><span>Ready</span><strong id="esReadyCount">0</strong></article>
            <article><span>Saved</span><strong id="esSavedCount">0</strong></article>
            <article><span>Issues</span><strong id="esIssueCount">0</strong></article>
          </div>
        </aside>

        <section class="es-panel es-queue-panel">
          <div class="es-panel-head-tight">
            <h4>Queue And Confirm</h4>
            <p>Scans are stored in browser cache immediately and survive accidental refresh.</p>
          </div>

          <label class="es-label">Single scan or scanner-wedge input
            <input id="esToken" type="text" placeholder="Paste QR URL/text here and press Enter" />
          </label>

          <label class="es-label">Bulk paste
            <textarea id="esBulkInput" rows="4" placeholder="Paste one QR value per line"></textarea>
          </label>

          <div class="es-button-row">
            <button id="esAddSingle" class="admin-button" type="button">Add Single Scan</button>
            <button id="esAddBulk" class="admin-button admin-button-secondary" type="button">Add Bulk Scans</button>
          </div>

          <div id="esStatus" class="admin-form-status">Session ready. Start scanning or paste QR codes.</div>

          <div class="es-button-row">
            <button id="esSaveSelected" class="admin-button" type="button">Save Selected</button>
            <button id="esSaveAll" class="admin-button admin-button-secondary" type="button">Save All Ready</button>
            <button id="esRemoveSelected" class="admin-button admin-button-secondary" type="button">Remove Selected</button>
            <button id="esClearQueue" class="admin-button admin-button-secondary" type="button">Clear Queue</button>
          </div>

          <div id="esQueueTable"></div>
        </section>
      </section>
    </section>
  `;

  const eventSelect = container.querySelector('#esEvent');
  const passcodeInput = container.querySelector('#esPasscode');
  const tokenInput = container.querySelector('#esToken');
  const bulkInput = container.querySelector('#esBulkInput');
  const eventBadge = container.querySelector('#esEventBadge');

  const queueCountNode = container.querySelector('#esQueueCount');
  const readyCountNode = container.querySelector('#esReadyCount');
  const savedCountNode = container.querySelector('#esSavedCount');
  const issueCountNode = container.querySelector('#esIssueCount');

  const statusNode = container.querySelector('#esStatus');
  const queueTableNode = container.querySelector('#esQueueTable');

  const cameraPreview = container.querySelector('#esCameraPreview');
  const cameraSelect = container.querySelector('#esCameraSelect');
  const cameraState = container.querySelector('#esCameraState');
  const cameraStatus = container.querySelector('#esCameraStatus');

  const startCameraBtn = container.querySelector('#esStartCamera');
  const stopCameraBtn = container.querySelector('#esStopCamera');
  const addSingleBtn = container.querySelector('#esAddSingle');
  const addBulkBtn = container.querySelector('#esAddBulk');
  const saveSelectedBtn = container.querySelector('#esSaveSelected');
  const saveAllBtn = container.querySelector('#esSaveAll');
  const removeSelectedBtn = container.querySelector('#esRemoveSelected');
  const clearQueueBtn = container.querySelector('#esClearQueue');

  let queue = loadQueue();
  let scanner = null;
  let scannerRunning = false;
  let lastScannedToken = '';
  let lastScanTime = 0;
  const scanCooldownMs = 1800;

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  function setCameraStatus(message, isError = false) {
    cameraStatus.textContent = message;
    cameraStatus.classList.toggle('error', Boolean(isError));
  }

  function updateCameraButtons() {
    startCameraBtn.disabled = scannerRunning;
    stopCameraBtn.disabled = !scannerRunning;
    cameraState.textContent = scannerRunning ? 'Scanning' : 'Idle';
  }

  function persistQueue() {
    localStorage.setItem(QUEUE_STORAGE_KEY, JSON.stringify(queue));
  }

  function refreshStats() {
    const ready = queue.filter((row) => row.status === 'ready').length;
    const saved = queue.filter((row) => row.status === 'saved').length;
    const issues = queue.filter((row) => row.status === 'error').length;

    queueCountNode.textContent = String(queue.length);
    readyCountNode.textContent = String(ready);
    savedCountNode.textContent = String(saved);
    issueCountNode.textContent = String(issues);
  }

  function redrawQueue() {
    refreshStats();
    if (!queue.length) {
      queueTableNode.innerHTML = '<div class="admin-module-empty">No QR scans queued yet.</div>';
      return;
    }

    queueTableNode.innerHTML = `
      <div class="admin-module-table-wrap">
        <table class="admin-table es-table">
          <thead>
            <tr>
              <th></th>
              <th>Select</th>
              <th>Status</th>
              <th>Guest / Ticket</th>
              <th>Event</th>
              <th>Type</th>
              <th>Source</th>
              <th>Arrived Now</th>
              <th>Local Check</th>
              <th>Message</th>
              <th>Scanned</th>
            </tr>
          </thead>
          <tbody>
            ${queue.map((row, index) => {
              const localCheck = row.status === 'saved' ? 'saved' : row.status === 'error' ? 'issue' : 'pending';
              const booking = row.preview && row.preview.booking ? row.preview.booking : {};
              const guest = booking.primaryGuest || row.previewName || '-';
              const ticket = booking.transactionId || row.tx || shortToken(row.token);
              const eventName = booking.eventTitle || row.eventTitle || '-';
              const type = row.preview && row.preview.booking && Number(row.preview.booking.quantity || 0) > 1 ? 'Group' : 'Single';
              const source = row.source || 'scanner';
              const scannedAt = formatDateTime(row.scannedAt);
              return `
                <tr>
                  <td>${index + 1}</td>
                  <td><input type="checkbox" data-es-select="${escapeHtml(row.id)}" ${row.selected ? 'checked' : ''} /></td>
                  <td><span class="admin-status-badge">${escapeHtml(statusLabel(row.status))}</span></td>
                  <td>${escapeHtml(guest)}<br><span class="es-subtle-inline">${escapeHtml(ticket)}</span></td>
                  <td>${escapeHtml(eventName)}</td>
                  <td>${escapeHtml(type)}</td>
                  <td>${escapeHtml(source)}</td>
                  <td><input type="number" min="1" max="9" value="${Number(row.arrivedNow || 1)}" data-es-arrived="${escapeHtml(row.id)}" class="es-arrived-input" /></td>
                  <td>${escapeHtml(localCheck)}</td>
                  <td>${escapeHtml(row.message || '-')}</td>
                  <td>${escapeHtml(scannedAt)}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;

    bindQueueControls();
  }

  function bindQueueControls() {
    Array.from(container.querySelectorAll('[data-es-select]')).forEach((node) => {
      node.addEventListener('change', () => {
        const id = String(node.getAttribute('data-es-select') || '');
        queue = queue.map((row) => row.id === id ? { ...row, selected: node.checked } : row);
        persistQueue();
      });
    });

    Array.from(container.querySelectorAll('[data-es-arrived]')).forEach((node) => {
      node.addEventListener('change', () => {
        const id = String(node.getAttribute('data-es-arrived') || '');
        const value = Math.max(1, Math.min(9, Number(node.value || 1)));
        queue = queue.map((row) => row.id === id ? { ...row, arrivedNow: value } : row);
        persistQueue();
      });
    });
  }

  function parseTokens(raw) {
    return String(raw || '')
      .split(/\r\n|\r|\n|,|;/)
      .map((item) => item.trim())
      .filter((item) => item !== '');
  }

  function buildQueueItem(token, source) {
    return {
      id: cryptoId(),
      token,
      source,
      status: 'queued',
      selected: true,
      arrivedNow: 1,
      message: 'Queued for preview',
      scannedAt: new Date().toISOString(),
      preview: null,
      previewName: '',
      eventTitle: '',
      tx: '',
    };
  }

  function addTokens(tokens, source) {
    const existing = new Set(queue.map((row) => row.token.toLowerCase()));
    const created = [];
    tokens.forEach((token) => {
      const key = token.toLowerCase();
      if (existing.has(key)) {
        return;
      }
      existing.add(key);
      created.push(buildQueueItem(token, source));
    });

    if (!created.length) {
      setStatus('No new tokens added. They may already exist in the queue.', true);
      return [];
    }

    queue = created.concat(queue);
    persistQueue();
    redrawQueue();
    setStatus(`${created.length} token(s) added to queue.`);
    return created;
  }

  async function loadEvents() {
    const payload = await adminApi('admin_list_events');
    const events = Array.isArray(payload.events) ? payload.events : [];
    if (!events.length) {
      eventSelect.innerHTML = '<option value="">No events</option>';
      eventBadge.textContent = '-';
      return;
    }

    const sorted = events
      .slice()
      .sort((a, b) => String(b.start_date || b.date || '').localeCompare(String(a.start_date || a.date || '')));

    eventSelect.innerHTML = sorted
      .map((event) => `<option value="${escapeHtml(String(event.id || event.event_id || ''))}">${escapeHtml(event.title || 'Event')}</option>`)
      .join('');

    eventBadge.textContent = shortEventLabel(sorted[0]);
  }

  function getPasscode() {
    return String(passcodeInput.value || '').trim().toUpperCase();
  }

  function ensureSessionReady() {
    if (!String(eventSelect.value || '').trim()) {
      setStatus('Select an event first.', true);
      return false;
    }
    if (!getPasscode()) {
      setStatus('Entry passcode is required.', true);
      return false;
    }
    return true;
  }

  async function previewQueueItem(item) {
    try {
      const previewPayload = await adminApi('admin_preview_event_qr', {
        method: 'POST',
        body: {
          eventId: eventSelect.value,
          guestToken: item.token,
          passcode: getPasscode(),
          previewOnly: 1,
        },
      });

      const booking = previewPayload.booking || {};
      return {
        ...item,
        status: 'ready',
        message: `Ready: ${booking.primaryGuest || booking.transactionId || 'ticket verified'}`,
        preview: previewPayload,
        previewName: booking.primaryGuest || '',
        eventTitle: booking.eventTitle || '',
        tx: booking.transactionId || '',
      };
    } catch (error) {
      return {
        ...item,
        status: 'error',
        message: error.message || 'Preview failed',
      };
    }
  }

  async function previewItemsByIds(ids) {
    if (!ids.length) {
      return;
    }

    if (!ensureSessionReady()) {
      return;
    }

    setStatus(`Previewing ${ids.length} ticket(s)...`);

    const updates = [];
    for (const id of ids) {
      const item = queue.find((row) => row.id === id);
      if (!item) {
        continue;
      }
      updates.push(await previewQueueItem(item));
    }

    queue = queue.map((row) => {
      const updated = updates.find((candidate) => candidate.id === row.id);
      return updated || row;
    });

    persistQueue();
    redrawQueue();

    const ready = updates.filter((row) => row.status === 'ready').length;
    const failed = updates.length - ready;
    setStatus(`Preview done. Ready: ${ready}, Failed: ${failed}.`, failed > 0);
  }

  function resolveGuestSelection(item) {
    const preview = item.preview || {};
    const names = Array.isArray(preview.remainingGuestNames)
      ? preview.remainingGuestNames
      : Array.isArray(preview.booking && preview.booking.remainingGuestNames)
        ? preview.booking.remainingGuestNames
        : [];

    const arrivedNow = Math.max(1, Number(item.arrivedNow || 1));
    if (!names.length) {
      return {
        selectedGuestNames: [],
        admittedCount: arrivedNow,
      };
    }

    return {
      selectedGuestNames: names.slice(0, Math.min(arrivedNow, names.length)),
      admittedCount: Math.min(arrivedNow, names.length),
    };
  }

  async function saveOne(item) {
    const selection = resolveGuestSelection(item);

    try {
      const payload = await adminApi('verify_event_qr', {
        method: 'POST',
        body: {
          eventId: eventSelect.value,
          guestToken: item.token,
          passcode: getPasscode(),
          selectedGuestNames: selection.selectedGuestNames,
          admittedCount: selection.admittedCount,
          source: 'scanner_batch',
        },
      });

      const booking = payload.booking || {};
      return {
        ...item,
        status: 'saved',
        message: payload.message || `Saved: ${booking.primaryGuest || booking.transactionId || 'entry confirmed'}`,
        preview: {
          ...item.preview,
          ...payload,
        },
        previewName: booking.primaryGuest || item.previewName,
        tx: booking.transactionId || item.tx,
      };
    } catch (error) {
      return {
        ...item,
        status: 'error',
        message: error.message || 'Save failed',
      };
    }
  }

  async function saveItems(ids) {
    if (!ids.length) {
      setStatus('No rows selected for save.', true);
      return;
    }

    if (!ensureSessionReady()) {
      return;
    }

    setStatus(`Saving ${ids.length} ticket(s)...`);

    const updates = [];
    for (const id of ids) {
      const item = queue.find((row) => row.id === id);
      if (!item) {
        continue;
      }
      updates.push(await saveOne(item));
    }

    queue = queue.map((row) => {
      const updated = updates.find((candidate) => candidate.id === row.id);
      return updated || row;
    });

    persistQueue();
    redrawQueue();

    const saved = updates.filter((row) => row.status === 'saved').length;
    const failed = updates.length - saved;
    setStatus(`Save completed. Success: ${saved}, Failed: ${failed}.`, failed > 0);
  }

  async function startScanner() {
    try {
      await ensureQrLibrary();
      if (!window.Html5Qrcode) {
        setCameraStatus('Unable to load QR camera library.', true);
        return;
      }

      if (scannerRunning) {
        return;
      }

      const selectedCameraId = String(cameraSelect.value || '').trim();
      if (!selectedCameraId) {
        setCameraStatus('No camera found for this device.', true);
        return;
      }

      scanner = new window.Html5Qrcode('esCameraPreview');
      await scanner.start(
        { deviceId: { exact: selectedCameraId } },
        {
          fps: 8,
          qrbox: { width: 220, height: 220 },
        },
        onScanSuccess,
        () => {}
      );

      scannerRunning = true;
      updateCameraButtons();
      setCameraStatus('Scanner active. Hold guest QR inside the frame.');
      setStatus('Scanner active.');
    } catch (error) {
      setCameraStatus(error.message || 'Unable to start scanner.', true);
    }
  }

  async function stopScanner() {
    if (!scanner || !scannerRunning) {
      return;
    }

    try {
      await scanner.stop();
    } catch (_) {
      // Keep UI responsive even when stop throws from stale stream.
    }

    scannerRunning = false;
    updateCameraButtons();
    setCameraStatus('Scanner stopped.');
  }

  async function setupCameraPicker() {
    await ensureQrLibrary();
    if (!window.Html5Qrcode || !window.Html5Qrcode.getCameras) {
      cameraSelect.innerHTML = '<option value="">Camera unavailable</option>';
      return;
    }

    const cameras = await window.Html5Qrcode.getCameras();
    if (!Array.isArray(cameras) || !cameras.length) {
      cameraSelect.innerHTML = '<option value="">No camera found</option>';
      return;
    }

    cameraSelect.innerHTML = cameras
      .map((camera) => `<option value="${escapeHtml(String(camera.id || ''))}">${escapeHtml(camera.label || camera.id || 'Camera')}</option>`)
      .join('');
  }

  async function onScanSuccess(decodedText) {
    const now = Date.now();
    if (decodedText === lastScannedToken && now - lastScanTime < scanCooldownMs) {
      return;
    }

    lastScannedToken = decodedText;
    lastScanTime = now;

    const token = String(decodedText || '').trim();
    if (!token) {
      return;
    }

    tokenInput.value = token;
    const created = addTokens([token], 'camera');
    if (created.length) {
      await previewItemsByIds(created.map((row) => row.id));
    }
  }

  eventSelect.addEventListener('change', () => {
    const label = eventSelect.options[eventSelect.selectedIndex] ? eventSelect.options[eventSelect.selectedIndex].textContent : '-';
    eventBadge.textContent = String(label || '-').slice(0, 24);
  });

  tokenInput.addEventListener('keydown', async (event) => {
    if (event.key !== 'Enter') {
      return;
    }
    event.preventDefault();
    const token = String(tokenInput.value || '').trim();
    if (!token) {
      return;
    }

    const created = addTokens([token], 'manual');
    tokenInput.value = '';
    if (created.length) {
      await previewItemsByIds(created.map((row) => row.id));
    }
  });

  startCameraBtn.addEventListener('click', startScanner);
  stopCameraBtn.addEventListener('click', stopScanner);

  addSingleBtn.addEventListener('click', async () => {
    const token = String(tokenInput.value || '').trim();
    if (!token) {
      setStatus('Paste one token first.', true);
      return;
    }

    const created = addTokens([token], 'manual');
    tokenInput.value = '';
    if (created.length) {
      await previewItemsByIds(created.map((row) => row.id));
    }
  });

  addBulkBtn.addEventListener('click', async () => {
    const tokens = parseTokens(bulkInput.value);
    if (!tokens.length) {
      setStatus('No bulk tokens found.', true);
      return;
    }

    const created = addTokens(tokens, 'bulk');
    if (created.length) {
      await previewItemsByIds(created.map((row) => row.id));
    }
  });

  saveSelectedBtn.addEventListener('click', async () => {
    const ids = queue.filter((row) => row.selected && row.status === 'ready').map((row) => row.id);
    await saveItems(ids);
  });

  saveAllBtn.addEventListener('click', async () => {
    const ids = queue.filter((row) => row.status === 'ready').map((row) => row.id);
    await saveItems(ids);
  });

  removeSelectedBtn.addEventListener('click', () => {
    const before = queue.length;
    queue = queue.filter((row) => !row.selected);
    persistQueue();
    redrawQueue();
    setStatus(`Removed ${before - queue.length} selected row(s).`);
  });

  clearQueueBtn.addEventListener('click', () => {
    queue = [];
    persistQueue();
    redrawQueue();
    setStatus('Queue cleared.');
  });

  try {
    await loadEvents();
    await setupCameraPicker();
    redrawQueue();
    updateCameraButtons();
    setStatus('Session ready. Start scanning or paste QR codes.');
  } catch (error) {
    setStatus(error.message || 'Failed to initialize Event Entry Scanner.', true);
  }
}

function statusLabel(status) {
  if (status === 'ready') return 'Ready';
  if (status === 'saved') return 'Saved';
  if (status === 'error') return 'Issue';
  return 'Queued';
}

function shortToken(token) {
  const value = String(token || '');
  if (value.length <= 28) {
    return value;
  }
  return `${value.slice(0, 16)}...${value.slice(-8)}`;
}

function shortEventLabel(event) {
  const title = String(event && event.title ? event.title : '-');
  return title.length > 24 ? `${title.slice(0, 24)}...` : title;
}

function formatDateTime(value) {
  const text = String(value || '').trim();
  if (!text) {
    return '-';
  }

  const parsed = new Date(text.replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) {
    return text;
  }

  return parsed.toLocaleString('en-IN', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  });
}

function loadQueue() {
  try {
    const raw = localStorage.getItem(QUEUE_STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) {
      return [];
    }

    return parsed
      .map((row) => ({
        id: String(row.id || cryptoId()),
        token: String(row.token || '').trim(),
        source: String(row.source || 'scanner'),
        status: String(row.status || 'queued'),
        selected: row.selected !== false,
        arrivedNow: Math.max(1, Number(row.arrivedNow || 1)),
        message: String(row.message || 'Queued for preview'),
        scannedAt: String(row.scannedAt || new Date().toISOString()),
        preview: row.preview || null,
        previewName: String(row.previewName || ''),
        eventTitle: String(row.eventTitle || ''),
        tx: String(row.tx || ''),
      }))
      .filter((row) => row.token !== '');
  } catch (_) {
    return [];
  }
}

function cryptoId() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }
  return `id_${Date.now()}_${Math.random().toString(16).slice(2)}`;
}

async function ensureQrLibrary() {
  if (window.Html5Qrcode) {
    return;
  }

  await new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
