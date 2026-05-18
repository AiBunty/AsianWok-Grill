import { adminApi } from './api-client.js';

export async function renderLandingRouting(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Routing & QR Center</h3>
      <p class="admin-module-empty" style="margin-bottom:10px;">Control blocker placement, stable QR redirects, and printable QR previews from one workspace.</p>
      <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:12px;">
        <div class="admin-module-item"><strong id="lrStatRecords">-</strong><div>Total QR Records</div></div>
        <div class="admin-module-item"><strong id="lrStatActive">-</strong><div>Active QRs</div></div>
        <div class="admin-module-item"><strong id="lrStatScans">-</strong><div>Total Scans</div></div>
        <div class="admin-module-item"><strong id="lrStatMilestone">-</strong><div>Next Milestone</div></div>
      </div>

      <form id="lrBlockerForm" class="admin-form-grid" style="margin-bottom:12px;">
        <h4>Menu Blocker Placement</h4>
        <div class="admin-form-grid admin-form-grid-inline">
          <label class="admin-checkbox"><input id="lrPageHome" type="checkbox" /> Home Page</label>
          <label class="admin-checkbox"><input id="lrPageMenu" type="checkbox" /> Food Menu</label>
          <label class="admin-checkbox"><input id="lrPageCocktail" type="checkbox" /> Cocktail Menu</label>
        </div>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Save Blocker Placement</button>
          <button id="lrReportBtn" class="admin-button admin-button-secondary" type="button">Open Full Scan Report</button>
        </div>
      </form>

      <form id="lrSettingsForm" class="admin-form-grid" style="margin-bottom:12px;">
        <h4>Routing Defaults</h4>
        <input id="lrDefaultTarget" type="text" placeholder="Default QR target URL" />
        <input id="lrFallbackSlug" type="text" placeholder="Fallback slug" />
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Save Routing Defaults</button>
          <button id="lrApplyMenuPreset" class="admin-button admin-button-secondary" type="button">Preset: Menu Card</button>
          <button id="lrApplyEventPreset" class="admin-button admin-button-secondary" type="button">Preset: Event Page</button>
                  <button id="lrApplyScannerPreset" class="admin-button admin-button-secondary" type="button">Preset: QR Scanner</button>
        </div>
      </form>

      <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:12px;align-items:start;">
        <section>
          <h4>QR Registry</h4>
          <div class="admin-form-actions" style="margin-bottom:8px;">
            <button id="lrNewQr" class="admin-button" type="button">Generate New QR</button>
            <button id="lrReload" class="admin-button admin-button-secondary" type="button">Reload</button>
          </div>
          <div id="lrRedirects"></div>
        </section>

        <section>
          <h4>QR Editor</h4>
          <form id="lrRedirectForm" class="admin-form-grid">
            <input id="lrRedirectId" type="hidden" />
            <input id="lrTitle" type="text" placeholder="QR name" required />
            <input id="lrSlug" type="text" placeholder="Redirect slug" required />
            <select id="lrMode">
              <option value="manual">Manual Target URL</option>
              <option value="preset">Preset Page</option>
            </select>
            <input id="lrTargetUrl" type="text" placeholder="https://..." required />
            <select id="lrPreset" hidden>
              <option value="">Loading presets...</option>
            </select>
            <label class="admin-checkbox"><input id="lrActive" type="checkbox" checked /> Active</label>
            <div id="lrPublicUrl" class="admin-module-empty">Select or save a QR record to view stable public URL.</div>
            <div style="border:1px dashed rgba(0,0,0,.18);border-radius:10px;padding:10px;text-align:center;">
              <img id="lrQrImage" alt="QR preview" style="width:180px;height:180px;object-fit:contain;display:none;margin:0 auto 8px;" />
              <div id="lrQrMeta" class="admin-module-empty">QR preview updates after save/select.</div>
            </div>
            <div class="admin-form-actions">
              <button class="admin-button" type="submit">Save QR</button>
              <button id="lrCopy" class="admin-button admin-button-secondary" type="button">Copy URL</button>
              <button id="lrOpen" class="admin-button admin-button-secondary" type="button">Open Link</button>
              <button id="lrDownload" class="admin-button admin-button-secondary" type="button">Download QR</button>
              <button id="lrDownloadDesigner" class="admin-button admin-button-secondary" type="button">Download Designer PNG</button>
              <button id="lrToggle" class="admin-button admin-button-secondary" type="button">Toggle Active</button>
              <button id="lrDelete" class="admin-button admin-button-secondary" type="button">Delete QR</button>
              <button id="lrReset" class="admin-button admin-button-secondary" type="button">Reset</button>
            </div>
          </form>
        </section>
      </div>

      <div id="lrStatus" class="admin-form-status">Loading routing center...</div>
    </section>
  `;

  const blockerForm = container.querySelector('#lrBlockerForm');
  const settingsForm = container.querySelector('#lrSettingsForm');
  const redirectForm = container.querySelector('#lrRedirectForm');
  const statusNode = container.querySelector('#lrStatus');
  const redirectsNode = container.querySelector('#lrRedirects');
  const modeInput = container.querySelector('#lrMode');
  const presetInput = container.querySelector('#lrPreset');
  const qrImage = container.querySelector('#lrQrImage');
  const qrMeta = container.querySelector('#lrQrMeta');
  const publicUrlNode = container.querySelector('#lrPublicUrl');
  const statRecords = container.querySelector('#lrStatRecords');
  const statActive = container.querySelector('#lrStatActive');
  const statScans = container.querySelector('#lrStatScans');
  const statMilestone = container.querySelector('#lrStatMilestone');
  const pageHome = container.querySelector('#lrPageHome');
  const pageMenu = container.querySelector('#lrPageMenu');
  const pageCocktail = container.querySelector('#lrPageCocktail');

  const defaultTargetInput = container.querySelector('#lrDefaultTarget');
  const fallbackSlugInput = container.querySelector('#lrFallbackSlug');

  const redirectIdInput = container.querySelector('#lrRedirectId');
  const slugInput = container.querySelector('#lrSlug');
  const titleInput = container.querySelector('#lrTitle');
  const targetInput = container.querySelector('#lrTargetUrl');
  const activeInput = container.querySelector('#lrActive');

  const toggleButton = container.querySelector('#lrToggle');
  const deleteButton = container.querySelector('#lrDelete');
  const downloadDesignerButton = container.querySelector('#lrDownloadDesigner');

  let redirects = [];
  const hiddenSystemSlugs = new Set(); // system QRs (guest-login, admin-login) are always shown — backend protects them
  let selectedId = 0;
  let appSettings = {};
  let presetCatalog = {};
  let manualDraftUrl = '';

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  function resetRedirectForm() {
    redirectForm.reset();
    redirectIdInput.value = '';
    selectedId = 0;
    manualDraftUrl = '';
    activeInput.checked = true;
    modeInput.value = 'preset';
    presetInput.hidden = false;
    targetInput.hidden = true;
    presetInput.value = presetInput.value || 'menu';
    targetInput.value = resolvePresetUrl(presetInput.value || 'menu');
    slugInput.readOnly = false;
    slugInput.style.opacity = '';
    publicUrlNode.textContent = 'Select or save a QR record to view stable public URL.';
    qrImage.style.display = 'none';
    qrImage.removeAttribute('src');
    qrMeta.textContent = 'QR preview updates after save/select.';
    toggleButton.disabled = true;
    deleteButton.disabled = true;
  }

  function slugify(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  function buildPublicUrl(row) {
    const direct = String(row.public_url || row.publicUrl || '').trim();
    if (direct) {
      return direct;
    }
    const slug = String(row.slug || '').trim();
    if (!slug) {
      return '';
    }
    return `${window.location.origin}/qr/${encodeURIComponent(slug)}`;
  }

  function downloadBlob(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
  }

  function loadImage(src) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.crossOrigin = 'anonymous';
      image.onload = () => resolve(image);
      image.onerror = reject;
      image.src = src;
    });
  }

  function deriveDesignerTitle(row) {
    const slug = String(row?.slug || '').toLowerCase();
    const title = String(row?.title || '').toLowerCase();
    if (slug.includes('admin') || title.includes('admin')) {
      return 'Admin QR';
    }
    return 'Guest QR';
  }

  async function renderDesignerQrBlob(row) {
    const publicUrl = buildPublicUrl(row);
    if (!publicUrl) {
      throw new Error('Save the QR first so its public URL is available.');
    }

    const qrImage = await loadImage(`/?action=event_qr_image&data=${encodeURIComponent(publicUrl)}`);
    const canvas = document.createElement('canvas');
    canvas.width = 1400;
    canvas.height = 1900;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      throw new Error('Unable to render designer canvas.');
    }

    const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
    gradient.addColorStop(0, '#fcf9f2');
    gradient.addColorStop(1, '#ebe1d0');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.fillStyle = '#94592b';
    ctx.fillRect(84, 84, canvas.width - 168, canvas.height - 168);
    ctx.fillStyle = '#fffdf8';
    ctx.fillRect(114, 114, canvas.width - 228, canvas.height - 228);

    ctx.fillStyle = '#2f241b';
    ctx.textAlign = 'center';
    ctx.font = '700 58px Georgia';
    ctx.fillText('Asian Wok & Grill', canvas.width / 2, 250);

    ctx.fillStyle = '#7d6a59';
    ctx.font = '600 34px Segoe UI';
    ctx.fillText('Scan to Open', canvas.width / 2, 340);

    const qrCardX = 250;
    const qrCardY = 460;
    const qrCardSize = 900;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(qrCardX, qrCardY, qrCardSize, qrCardSize);
    ctx.strokeStyle = '#b67b45';
    ctx.lineWidth = 16;
    ctx.strokeRect(qrCardX, qrCardY, qrCardSize, qrCardSize);
    ctx.drawImage(qrImage, qrCardX + 60, qrCardY + 60, qrCardSize - 120, qrCardSize - 120);

    ctx.fillStyle = '#2f241b';
    ctx.font = '700 44px Segoe UI';
    ctx.fillText(String(row?.title || deriveDesignerTitle(row)), canvas.width / 2, 1520);

    ctx.fillStyle = '#7d6a59';
    ctx.font = '600 28px Segoe UI';
    ctx.fillText(deriveDesignerTitle(row), canvas.width / 2, 1590);

    ctx.fillStyle = '#94592b';
    ctx.font = '500 22px Segoe UI';
    ctx.fillText(publicUrl, canvas.width / 2, 1685);

    return new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), 'image/png');
    });
  }

  function updatePreview(url, label = '') {
    if (!url) {
      qrImage.style.display = 'none';
      qrImage.removeAttribute('src');
      qrMeta.textContent = 'QR preview updates after save/select.';
      return;
    }

    qrImage.src = `/?action=event_qr_image&data=${encodeURIComponent(url)}`;
    qrImage.style.display = 'block';
    qrMeta.textContent = label ? `${label} -> ${url}` : url;
  }

  function normalizePresetKey(value) {
    const raw = String(value || '').trim();
    if (!raw) {
      return '';
    }
    if (raw.startsWith('/') || raw.endsWith('.html')) {
      return raw.replace(/^\//, '').replace(/\.html$/i, '');
    }
    return raw;
  }

  function resolvePresetUrl(key) {
    const normalized = normalizePresetKey(key);
    if (!normalized) {
      return '';
    }

    const preset = presetCatalog[normalized];
    if (preset && preset.url) {
      return String(preset.url);
    }

    return `/${normalized}.html`;
  }

  function populatePresetOptions(catalog, selectedKey = '') {
    const normalizedCatalog = { ...(catalog || {}) };
    if (!normalizedCatalog.home) {
      normalizedCatalog.home = { label: 'Home Page', url: '/home.html' };
    }
    if (!normalizedCatalog.menu) {
      normalizedCatalog.menu = { label: 'AWG Menu', url: '/menu.html' };
    }
    if (!normalizedCatalog.namastemenu) {
      normalizedCatalog.namastemenu = { label: 'Namaste Menu', url: '/namastemenu.html' };
    }
    if (!normalizedCatalog.events) {
      normalizedCatalog.events = { label: 'Active Events Page', url: '/events.html' };
    }
    if (!normalizedCatalog.admin) {
      normalizedCatalog.admin = { label: 'Admin Portal', url: '/admin/admin-portal.html' };
    }
    if (!normalizedCatalog.scanner) {
      normalizedCatalog.scanner = { label: 'QR Scanner Exclusive Page', url: '/qr.html' };
    }
    if (!normalizedCatalog['scanner-exclusive']) {
      normalizedCatalog['scanner-exclusive'] = { label: 'QR Scanner Exclusive Page', url: '/qr.html' };
    }

    const entries = Object.entries(normalizedCatalog);
    if (!entries.length) {
      presetInput.innerHTML = '<option value="menu">AWG Menu</option>';
      presetInput.value = 'menu';
      presetCatalog = {
        menu: { label: 'AWG Menu', url: '/menu.html' },
        namastemenu: { label: 'Namaste Menu', url: '/namastemenu.html' },
        admin: { label: 'Admin Portal', url: '/admin/admin-portal.html' },
        scanner: { label: 'QR Scanner Exclusive Page', url: '/qr.html' },
        'scanner-exclusive': { label: 'QR Scanner Exclusive Page', url: '/qr.html' },
      };
      return;
    }

    presetCatalog = normalizedCatalog;

    const options = entries
      .map(([key, row]) => `<option value="${escapeHtml(key)}">${escapeHtml(row?.label || key)}</option>`)
      .join('');

    presetInput.innerHTML = options;

    const normalized = normalizePresetKey(selectedKey);
    if (normalized && normalizedCatalog[normalized]) {
      presetInput.value = normalized;
      return;
    }

    const firstKey = entries[0][0];
    presetInput.value = firstKey;
  }

  function applyBlockerSettings() {
    const pages = appSettings.menuBlockerPages || {};
    pageHome.checked = Boolean(pages.home);
    pageMenu.checked = Boolean(pages.menu);
    pageCocktail.checked = Boolean(pages.cocktail);
  }

  function applyMode() {
    const isManual = modeInput.value === 'manual';
    targetInput.hidden = !isManual;
    presetInput.hidden = isManual;
    if (isManual) {
      if (manualDraftUrl) {
        targetInput.value = manualDraftUrl;
      }
    } else {
      targetInput.value = resolvePresetUrl(presetInput.value);
    }
  }

  function renderRedirects() {
    statRecords.textContent = String(redirects.length);
    statActive.textContent = String(redirects.filter((row) => Number(row.is_active || row.isActive || 0) === 1).length);

    if (!redirects.length) {
      redirectsNode.innerHTML = '<div class="admin-module-empty">No routing redirects configured.</div>';
      return;
    }

    redirectsNode.innerHTML = redirects.map((row) => {
      const id = Number(row.id || 0);
      const isActive = Number(row.is_active || row.isActive || 0) === 1;
      const rowPublicUrl = buildPublicUrl(row);
      const isSelected = id === selectedId;
      return `
        <article class="admin-module-item${isSelected ? ' is-active' : ''}" style="margin-bottom:8px;cursor:pointer;" data-lr-select="${id}">
          <div>
            <strong>${escapeHtml(row.name || row.title || row.slug || `QR ${id}`)}</strong>
            <div>${escapeHtml(row.slug || '-')}</div>
            <div>${escapeHtml(String(row.redirect_mode === 'manual' ? (row.manual_url || '-') : (row.preset_key || '-')))}</div>
            <div class="admin-module-empty">${escapeHtml(rowPublicUrl || row.target_url || row.targetUrl || '-')}</div>
          </div>
          <div>${isActive ? 'Active' : 'Inactive'}</div>
        </article>
      `;
    }).join('');

    bindTableActions();
  }

  function bindTableActions() {
    const map = new Map(redirects.map((row) => [String(row.id || ''), row]));

    Array.from(container.querySelectorAll('[data-lr-select]')).forEach((button) => {
      button.addEventListener('click', () => {
        const id = String(button.dataset.lrSelect || '');
        const row = map.get(id);
        if (!row) {
          return;
        }

        selectedId = Number(row.id || 0);
        redirectIdInput.value = String(row.id || '');
        slugInput.value = String(row.slug || '');
        titleInput.value = String(row.name || row.title || '');
        modeInput.value = String(row.redirect_mode || 'preset');
        targetInput.value = String(row.manual_url || row.target_url || row.targetUrl || '');
        manualDraftUrl = String(row.manual_url || '');
        const rowPresetKey = normalizePresetKey(row.preset_key || 'menu');
        if (rowPresetKey && !presetCatalog[rowPresetKey]) {
          presetCatalog[rowPresetKey] = {
            label: `Preset: ${rowPresetKey}`,
            url: resolvePresetUrl(rowPresetKey),
          };
          populatePresetOptions(presetCatalog, rowPresetKey);
        } else {
          presetInput.value = rowPresetKey || 'menu';
        }
        applyMode();
        activeInput.checked = Number(row.is_active || row.isActive || 0) === 1;
        const rowPublicUrl = buildPublicUrl(row);
        publicUrlNode.textContent = rowPublicUrl || 'No public URL available for this record.';
        updatePreview(rowPublicUrl, row.title || row.slug || 'QR preview');
        const isSystem = Number(row.is_system || row.isSystem || 0) === 1;
        toggleButton.disabled = isSystem;
        deleteButton.disabled = isSystem;
        slugInput.readOnly = isSystem;
        slugInput.style.opacity = isSystem ? '0.5' : '';
        if (isSystem) {
          setStatus('Guest/Admin system QR rows stay active and cannot be deleted. Slug is locked but target URL can be changed.', false);
        } else {
          setStatus(`Editing redirect ${row.slug || id}.`, false);
        }
        renderRedirects();
      });
    });
  }

  async function load() {
    const [appSettingsRes, settingsRes, redirectsRes, reportRes] = await Promise.allSettled([
      adminApi('auth_get_app_settings', { method: 'POST' }),
      adminApi('auth_get_qr_redirect_settings'),
      adminApi('auth_list_qr_redirects'),
      adminApi('qr_report'),
    ]);

    const appSettingsPayload = appSettingsRes.status === 'fulfilled' ? appSettingsRes.value : { settings: {} };
    const settingsPayload = settingsRes.status === 'fulfilled' ? settingsRes.value : { settings: {}, presetCatalog: {} };
    const redirectsPayload = redirectsRes.status === 'fulfilled' ? redirectsRes.value : { redirects: [] };
    const reportPayload = reportRes.status === 'fulfilled' ? reportRes.value : { totalScans: 0 };

    appSettings = appSettingsPayload.settings || {};
    applyBlockerSettings();

    const settings = settingsPayload.settings || {};
    presetCatalog = settingsPayload.presetCatalog || {};
    populatePresetOptions(presetCatalog, 'menu');
    defaultTargetInput.value = String(settings.defaultTargetUrl || '');
    fallbackSlugInput.value = String(settings.fallbackSlug || '');

    const totalScans = Number(reportPayload.totalScans || 0);
    statScans.textContent = String(totalScans);
    statMilestone.textContent = String(Math.ceil((totalScans + 1) / 100) * 100);

    const rawRedirects = Array.isArray(redirectsPayload.redirects) ? redirectsPayload.redirects : [];
    redirects = rawRedirects.filter((row) => {
      const slug = String(row?.slug || '').trim().toLowerCase();
      return !hiddenSystemSlugs.has(slug);
    });

    // Add active event presets even when backend catalog is stale.
    try {
      const eventsPayload = await fetch('/?action=public_live_events', {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      }).then((response) => response.json());

      const activeEvents = Array.isArray(eventsPayload?.events) ? eventsPayload.events : [];
      activeEvents.forEach((event) => {
        const eventId = String(event?.id || '').trim();
        if (!eventId) {
          return;
        }
        const key = `event-active:${eventId}`;
        if (!presetCatalog[key]) {
          presetCatalog[key] = {
            label: `Active Event: ${String(event?.title || eventId)}`,
            url: `/events.html?eventId=${encodeURIComponent(eventId)}`,
          };
        }
      });
      populatePresetOptions(presetCatalog, presetInput.value || 'menu');
    } catch (_error) {
      // Ignore optional preset enrichment failures.
    }

    renderRedirects();

    const loadErrors = [];
    if (settingsRes.status === 'rejected') loadErrors.push('presets');
    if (redirectsRes.status === 'rejected') loadErrors.push('QR registry');
    if (appSettingsRes.status === 'rejected') loadErrors.push('blocker placement');
    if (loadErrors.length) {
      setStatus(`Loaded with partial data. Failed: ${loadErrors.join(', ')}.`, true);
    }
  }

  blockerForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
      await adminApi('auth_update_menu_blocker_settings', {
        method: 'POST',
        body: {
          settings: {
            menuBlockerPages: {
              home: pageHome.checked,
              menu: pageMenu.checked,
              cocktail: pageCocktail.checked,
            },
          },
        },
      });

      setStatus('Menu blocker placement saved.', false);
      await load();
    } catch (error) {
      setStatus(error.message || 'Failed to save blocker placement.', true);
    }
  });

  settingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
      await adminApi('auth_set_qr_redirect_settings', {
        method: 'POST',
        body: {
          settings: {
            defaultTargetUrl: defaultTargetInput.value,
            fallbackSlug: fallbackSlugInput.value,
          },
        },
      });
      setStatus('QR settings saved successfully.', false);
    } catch (error) {
      setStatus(error.message || 'Failed to save QR settings.', true);
    }
  });

  redirectForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const normalizedTitle = String(titleInput.value || '').trim();
      const normalizedSlug = slugify(slugInput.value || normalizedTitle);
      if (!normalizedTitle || normalizedSlug.length < 2) {
        setStatus('Enter a valid QR name/slug.', true);
        return;
      }

      const currentMode = modeInput.value === 'preset' ? 'preset' : 'manual';
      const resolvedTarget = currentMode === 'manual' ? targetInput.value : resolvePresetUrl(presetInput.value);
      await adminApi('auth_save_qr_redirect', {
        method: 'POST',
        body: {
          id: Number(redirectIdInput.value || 0),
          slug: normalizedSlug,
          title: normalizedTitle,
          redirectMode: currentMode,
          presetKey: currentMode === 'preset' ? (presetInput.value || 'menu') : '',
          targetUrl: resolvedTarget,
          isActive: activeInput.checked,
        },
      });

      resetRedirectForm();
      await load();
      setStatus('Redirect saved.', false);
    } catch (error) {
      setStatus(error.message || 'Failed to save redirect.', true);
    }
  });

  container.querySelector('#lrReset').addEventListener('click', () => {
    resetRedirectForm();
  });

  container.querySelector('#lrNewQr').addEventListener('click', () => {
    resetRedirectForm();
    titleInput.focus();
    setStatus('Creating new QR record.', false);
  });

  container.querySelector('#lrReload').addEventListener('click', async () => {
    try {
      await load();
      setStatus('Routing center reloaded.', false);
    } catch (error) {
      setStatus(error.message || 'Reload failed.', true);
    }
  });

  modeInput.addEventListener('change', () => {
    if (modeInput.value === 'preset') {
      manualDraftUrl = String(targetInput.value || '').trim();
    }
    applyMode();
  });
  presetInput.addEventListener('change', () => {
    if (modeInput.value === 'preset') {
      targetInput.value = resolvePresetUrl(presetInput.value);
    }
  });
  targetInput.addEventListener('input', () => {
    if (modeInput.value === 'manual') {
      manualDraftUrl = String(targetInput.value || '').trim();
    }
  });

  container.querySelector('#lrCopy').addEventListener('click', async () => {
    const text = publicUrlNode.textContent || '';
    if (!text || text.startsWith('Select or save')) {
      setStatus('No QR URL available to copy.', true);
      return;
    }

    try {
      await navigator.clipboard.writeText(text);
      setStatus('QR URL copied to clipboard.', false);
    } catch (_error) {
      setStatus('Clipboard copy failed. Copy manually from the field.', true);
    }
  });

  container.querySelector('#lrOpen').addEventListener('click', () => {
    const text = publicUrlNode.textContent || '';
    if (!text || text.startsWith('Select or save')) {
      setStatus('No QR URL available to open.', true);
      return;
    }
    window.open(text, '_blank', 'noopener');
  });

  container.querySelector('#lrDownload').addEventListener('click', () => {
    const text = publicUrlNode.textContent || '';
    if (!text || text.startsWith('Select or save')) {
      setStatus('No QR URL available to download.', true);
      return;
    }

    const a = document.createElement('a');
    a.href = `/?action=event_qr_image&data=${encodeURIComponent(text)}`;
    a.download = `qr-${(slugInput.value || 'redirect').trim()}.png`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setStatus('QR download triggered.', false);
  });

  downloadDesignerButton.addEventListener('click', async () => {
    const id = Number(redirectIdInput.value || 0);
    if (!id) {
      setStatus('Select a QR record first.', true);
      return;
    }

    const row = redirects.find((item) => Number(item.id || 0) === id);
    if (!row) {
      setStatus('Unable to find selected QR record.', true);
      return;
    }

    try {
      setStatus('Rendering designer PNG...', false);
      const blob = await renderDesignerQrBlob(row);
      if (!blob) {
        throw new Error('Unable to generate PNG.');
      }
      downloadBlob(blob, `qr-${(row.slug || 'redirect')}-designer.png`);
      setStatus('Designer QR PNG downloaded.', false);
    } catch (error) {
      setStatus(error.message || 'Failed to render designer PNG.', true);
    }
  });

  container.querySelector('#lrToggle').addEventListener('click', async () => {
    const id = Number(redirectIdInput.value || 0);
    if (!id) {
      setStatus('Select a QR record first.', true);
      return;
    }

    try {
      await adminApi('auth_set_qr_redirect_active', {
        method: 'POST',
        body: {
          id,
          isActive: !activeInput.checked,
        },
      });

      await load();
      setStatus('Redirect status updated.', false);
    } catch (error) {
      setStatus(error.message || 'Failed to toggle redirect status.', true);
    }
  });

  container.querySelector('#lrDelete').addEventListener('click', async () => {
    const id = Number(redirectIdInput.value || 0);
    if (!id) {
      setStatus('Select a QR record first.', true);
      return;
    }
    if (!window.confirm('Delete this redirect?')) {
      return;
    }

    try {
      await adminApi('auth_delete_qr_redirect', {
        method: 'POST',
        body: { id },
      });

      resetRedirectForm();
      await load();
      setStatus('Redirect deleted.', false);
    } catch (error) {
      setStatus(error.message || 'Failed to delete redirect.', true);
    }
  });

  container.querySelector('#lrReportBtn').addEventListener('click', () => {
    window.open('/admin/qr-report.html', '_blank', 'noopener');
  });

  container.querySelector('#lrApplyMenuPreset').addEventListener('click', () => {
    defaultTargetInput.value = '/menu.html';
    fallbackSlugInput.value = 'menu';
    setStatus('Menu preset applied. Save to confirm.', false);
  });

  container.querySelector('#lrApplyEventPreset').addEventListener('click', () => {
    defaultTargetInput.value = '/reservation.html';
    fallbackSlugInput.value = 'events';
    setStatus('Event preset applied. Save to confirm.', false);
  });

  container.querySelector('#lrApplyScannerPreset').addEventListener('click', () => {
    defaultTargetInput.value = '/qr.html';
    fallbackSlugInput.value = 'scanner';
    setStatus('QR Scanner preset applied. Save to confirm.', false);
  });

  try {
    applyMode();
    resetRedirectForm();
    await load();
    setStatus('Routing & QR Center ready.', false);
  } catch (error) {
    setStatus(error.message || 'Failed to initialize Routing & QR Center.', true);
  }
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
