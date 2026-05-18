(function (NK) {
  'use strict';

  NK.MODULES = NK.MODULES || {};
  NK.MODULES['landing-routing'] = {
    _container: null,
    _authClient: null,
    _statsInterval: null,
    _records: [],
    _presetOptions: [],
    _selectedId: 0,

    init: function (container, authClient) {
      this._container = container;
      this._authClient = authClient;
      this._statsInterval = null;
      this._records = [];
      this._presetOptions = [];
      this._selectedId = 0;
      var module = this;
      var APPS_SCRIPT_URL = (
        (window.NK_DATA_API && window.NK_DATA_API.appsScriptUrl) ||
        window.APPS_SCRIPT_URL ||
        ''
      ).trim();

      container.innerHTML = ''
        + '<style>'
        + '.lrm-shell{max-width:1180px;margin:0 auto;padding:20px;font-family:"Segoe UI",Tahoma,sans-serif;color:#2d241c}'
        + '.lrm-hero{background:linear-gradient(135deg,#fff7eb,#f0e2cf);border:1px solid rgba(147,106,69,.18);border-radius:24px;padding:24px 26px;box-shadow:0 16px 38px rgba(80,57,36,.08)}'
        + '.lrm-hero h2{margin:0 0 8px;font-size:1.8rem}'
        + '.lrm-hero p{margin:0;color:#6b5848;line-height:1.6}'
        + '.lrm-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px}'
        + '.lrm-stat{background:rgba(255,253,248,.9);border:1px solid rgba(147,106,69,.12);border-radius:16px;padding:14px 16px}'
        + '.lrm-stat-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#816c59;font-weight:700}'
        + '.lrm-stat-value{margin-top:8px;font-size:1.35rem;font-weight:800;color:#2d241c}'
        + '.lrm-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:18px;margin-top:18px}'
        + '.lrm-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:18px}'
        + '.lrm-card{background:#fff;border:1px solid rgba(147,106,69,.15);border-radius:20px;padding:20px;box-shadow:0 10px 24px rgba(80,57,36,.06)}'
        + '.lrm-card h3{margin:0 0 8px;font-size:1.05rem}'
        + '.lrm-muted{color:#6b5848;font-size:.92rem;line-height:1.6}'
        + '.lrm-preview{display:grid;justify-items:center;gap:12px;padding:16px;border:1px dashed rgba(147,106,69,.24);border-radius:18px;background:linear-gradient(180deg,#fffdf8,#f7efe3);margin-top:16px}'
        + '.lrm-preview-frame{width:min(100%,320px);aspect-ratio:1;border-radius:18px;background:#fff;border:1px solid rgba(147,106,69,.16);display:grid;place-items:center;overflow:hidden;box-shadow:0 10px 24px rgba(80,57,36,.06)}'
        + '.lrm-preview-frame img{width:100%;height:100%;object-fit:contain;display:block}'
        + '.lrm-preview-empty{padding:22px;text-align:center;color:#816c59;font-size:.9rem;line-height:1.6}'
        + '.lrm-preview-meta{font-size:.82rem;color:#6b5848;text-align:center;line-height:1.6;word-break:break-word}'
        + '.lrm-checks{display:grid;gap:12px;margin-top:16px}'
        + '.lrm-check{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid rgba(147,106,69,.12);border-radius:14px;background:#fffaf4}'
        + '.lrm-check input{width:18px;height:18px}'
        + '.lrm-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}'
        + '.lrm-btn{border:none;border-radius:14px;padding:11px 16px;font:inherit;font-weight:700;cursor:pointer}'
        + '.lrm-btn-primary{background:#9a5f34;color:#fff}'
        + '.lrm-btn-secondary{background:#efe4d5;color:#5d4735}'
        + '.lrm-btn-danger{background:#f9e2dd;color:#8a3a2c}'
        + '.lrm-list{display:grid;gap:10px;margin-top:16px;max-height:720px;overflow:auto;padding-right:2px}'
        + '.lrm-item{border:1px solid rgba(147,106,69,.14);border-radius:16px;padding:14px;background:#fffaf4;cursor:pointer}'
        + '.lrm-item.active{border-color:#9a5f34;box-shadow:0 0 0 2px rgba(154,95,52,.12)}'
        + '.lrm-item-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}'
        + '.lrm-item-name{font-weight:700}'
        + '.lrm-item-slug{margin-top:4px;font-size:.82rem;color:#816c59;word-break:break-all}'
        + '.lrm-badges{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}'
        + '.lrm-badge{padding:4px 8px;border-radius:999px;font-size:.72rem;font-weight:700}'
        + '.lrm-badge-system{background:#efe4d5;color:#6d513c}'
        + '.lrm-badge-active{background:#d9f3e1;color:#216c41}'
        + '.lrm-badge-off{background:#f9e2dd;color:#8a3a2c}'
        + '.lrm-item-meta{margin-top:10px;font-size:.84rem;color:#5d4735;line-height:1.5}'
        + '.lrm-field,.lrm-select,.lrm-textarea{width:100%;padding:12px 14px;border:1px solid rgba(147,106,69,.2);border-radius:14px;font:inherit;background:#fff;margin-top:12px}'
        + '.lrm-textarea{min-height:92px;resize:vertical}'
        + '.lrm-label{display:block;margin-top:16px;font-size:.86rem;font-weight:700;color:#5d4735}'
        + '.lrm-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}'
        + '.lrm-code{margin-top:12px;padding:12px 14px;border-radius:14px;background:#2d241c;color:#f8efe4;font-family:"Courier New",monospace;word-break:break-all}'
        + '.lrm-mini{margin-top:10px;font-size:.86rem;color:#5d4735;line-height:1.5}'
        + '.lrm-status{min-height:22px;margin-top:14px;color:#6b5848;white-space:pre-line}'
        + '.lrm-hidden{display:none !important}'
        + '@media(max-width:900px){.lrm-summary,.lrm-grid,.lrm-row{grid-template-columns:1fr}}'
        + '</style>'
        + '<div class="lrm-shell">'
        + '  <div class="lrm-hero">'
        + '    <h2>Routing & QR Center</h2>'
        + '    <p>Control blocker placement, review scan activity, and manage reusable QR redirects from one place. Each QR gets a stable slug URL, and each slug can point to a core page, an active event page, or a manual target URL.</p>'
        + '    <div class="lrm-summary">'
        + '      <div class="lrm-stat"><div class="lrm-stat-label">Saved QR Records</div><div class="lrm-stat-value" id="lrmStatRecords">—</div></div>'
        + '      <div class="lrm-stat"><div class="lrm-stat-label">Active QRs</div><div class="lrm-stat-value" id="lrmStatActive">—</div></div>'
        + '      <div class="lrm-stat"><div class="lrm-stat-label">Total Scans</div><div class="lrm-stat-value" id="lrmStatScans">—</div></div>'
        + '      <div class="lrm-stat"><div class="lrm-stat-label">Next Email Milestone</div><div class="lrm-stat-value" id="lrmStatMilestone">—</div></div>'
        + '    </div>'
        + '  </div>'
        + '  <div class="lrm-layout">'
        + '    <section class="lrm-card">'
        + '      <h3>Menu Blocker Placement</h3>'
        + '      <p class="lrm-muted">Choose the public pages where the blocker should appear.</p>'
        + '      <div class="lrm-checks">'
        + '        <label class="lrm-check"><input type="checkbox" id="lrmPageHome"> <span>Home Page</span></label>'
        + '        <label class="lrm-check"><input type="checkbox" id="lrmPageMenu"> <span>Food Menu</span></label>'
        + '        <label class="lrm-check"><input type="checkbox" id="lrmPageCocktail"> <span>Cocktail Menu</span></label>'
        + '      </div>'
        + '      <div class="lrm-toolbar">'
        + '        <button type="button" class="lrm-btn lrm-btn-primary" id="lrmSaveBlockerBtn">Save Blocker Placement</button>'
        + '        <button type="button" class="lrm-btn lrm-btn-secondary" id="lrmReportBtn">Open Full Scan Report</button>'
        + '      </div>'
        + '    </section>'
        + '    <div class="lrm-grid">'
        + '      <section class="lrm-card">'
        + '        <h3>QR Registry</h3>'
        + '        <p class="lrm-muted">Create a new QR once, get a stable slug URL, and keep editing the destination later without regenerating code.</p>'
        + '        <div class="lrm-toolbar">'
        + '          <button type="button" class="lrm-btn lrm-btn-primary" id="lrmNewQrBtn">Generate New QR</button>'
        + '          <button type="button" class="lrm-btn lrm-btn-secondary" id="lrmReloadBtn">Reload</button>'
        + '        </div>'
        + '        <div class="lrm-list" id="lrmQrList"></div>'
        + '      </section>'
        + '      <section class="lrm-card">'
        + '        <h3>QR Editor</h3>'
        + '        <p class="lrm-muted">Enter a target URL directly, or choose a preset page. The slug URL stays stable even if you change the target later.</p>'
        + '        <input type="hidden" id="lrmQrId">'
        + '        <label class="lrm-label" for="lrmQrName">QR Name</label>'
        + '        <input id="lrmQrName" class="lrm-field" type="text" placeholder="Festival poster QR">'
        + '        <div class="lrm-row">'
        + '          <div>'
        + '            <label class="lrm-label" for="lrmQrSlug">Slug</label>'
        + '            <input id="lrmQrSlug" class="lrm-field" type="text" placeholder="festival-poster">'
        + '          </div>'
        + '          <div>'
        + '            <label class="lrm-label" for="lrmQrStatus">Status</label>'
        + '            <select id="lrmQrStatus" class="lrm-select">'
        + '              <option value="1">Active</option>'
        + '              <option value="0">Inactive</option>'
        + '            </select>'
        + '          </div>'
        + '        </div>'
        + '        <label class="lrm-label" for="lrmQrMode">Destination Type</label>'
        + '        <select id="lrmQrMode" class="lrm-select">'
        + '          <option value="preset">Preset Page</option>'
        + '          <option value="manual">Manual Target URL</option>'
        + '        </select>'
        + '        <div id="lrmPresetWrap">'
        + '          <label class="lrm-label" for="lrmQrPreset">Preset Destination</label>'
        + '          <select id="lrmQrPreset" class="lrm-select"></select>'
        + '        </div>'
        + '        <div id="lrmManualWrap" class="lrm-hidden">'
        + '          <label class="lrm-label" for="lrmQrManual">Target URL</label>'
        + '          <input id="lrmQrManual" class="lrm-field" type="url" placeholder="https://example.com/campaign or http://localhost:3000/public/menu/">'
        + '        </div>'
        + '        <label class="lrm-label" for="lrmQrNotes">Notes</label>'
        + '        <textarea id="lrmQrNotes" class="lrm-textarea" placeholder="Poster location, campaign name, or print notes"></textarea>'
        + '        <div class="lrm-code" id="lrmQrPublicUrl">Select or create a QR to see its public slug URL.</div>'
        + '        <div class="lrm-mini" id="lrmQrResolved">Currently resolves to: -</div>'
        + '        <div class="lrm-mini" id="lrmQrUpdated">Last updated: -</div>'
        + '        <div class="lrm-preview">'
        + '          <div class="lrm-preview-frame" id="lrmQrPreviewFrame"><div class="lrm-preview-empty" id="lrmQrPreviewEmpty">Save or select a QR to preview the printable code here.</div><img id="lrmQrPreviewImg" alt="Selected QR preview" class="lrm-hidden"></div>'
        + '          <div class="lrm-preview-meta" id="lrmQrPreviewMeta">The preview updates whenever you select a QR record.</div>'
        + '        </div>'
        + '        <div class="lrm-toolbar">'
        + '          <button type="button" class="lrm-btn lrm-btn-primary" id="lrmSaveQrBtn">Save QR</button>'
        + '          <button type="button" class="lrm-btn lrm-btn-secondary" id="lrmCopyQrBtn">Copy QR URL</button>'
        + '          <button type="button" class="lrm-btn lrm-btn-secondary" id="lrmDownloadQrBtn">Download QR PNG</button>'
        + '          <button type="button" class="lrm-btn lrm-btn-secondary" id="lrmDownloadDesignerBtn">Download Designer PNG</button>'
        + '          <button type="button" class="lrm-btn lrm-btn-secondary" id="lrmOpenQrBtn">Open QR Link</button>'
        + '          <button type="button" class="lrm-btn lrm-btn-danger" id="lrmToggleQrBtn">Toggle Active</button>'
        + '          <button type="button" class="lrm-btn lrm-btn-danger" id="lrmDeleteQrBtn">Delete QR</button>'
        + '        </div>'
        + '      </section>'
        + '    </div>'
        + '  </div>'
        + '  <div class="lrm-status" id="lrmStatus"></div>'
        + '</div>';

      var homeEl = container.querySelector('#lrmPageHome');
      var menuEl = container.querySelector('#lrmPageMenu');
      var cocktailEl = container.querySelector('#lrmPageCocktail');
      var saveBlockerBtn = container.querySelector('#lrmSaveBlockerBtn');
      var reportBtn = container.querySelector('#lrmReportBtn');
      var newQrBtn = container.querySelector('#lrmNewQrBtn');
      var reloadBtn = container.querySelector('#lrmReloadBtn');
      var qrListEl = container.querySelector('#lrmQrList');
      var recordsStatEl = container.querySelector('#lrmStatRecords');
      var activeStatEl = container.querySelector('#lrmStatActive');
      var scansStatEl = container.querySelector('#lrmStatScans');
      var milestoneStatEl = container.querySelector('#lrmStatMilestone');
      var qrIdEl = container.querySelector('#lrmQrId');
      var qrNameEl = container.querySelector('#lrmQrName');
      var qrSlugEl = container.querySelector('#lrmQrSlug');
      var qrStatusEl = container.querySelector('#lrmQrStatus');
      var qrModeEl = container.querySelector('#lrmQrMode');
      var qrPresetEl = container.querySelector('#lrmQrPreset');
      var qrManualEl = container.querySelector('#lrmQrManual');
      var qrNotesEl = container.querySelector('#lrmQrNotes');
      var qrPublicUrlEl = container.querySelector('#lrmQrPublicUrl');
      var qrResolvedEl = container.querySelector('#lrmQrResolved');
      var qrUpdatedEl = container.querySelector('#lrmQrUpdated');
      var qrPreviewImgEl = container.querySelector('#lrmQrPreviewImg');
      var qrPreviewEmptyEl = container.querySelector('#lrmQrPreviewEmpty');
      var qrPreviewMetaEl = container.querySelector('#lrmQrPreviewMeta');
      var presetWrapEl = container.querySelector('#lrmPresetWrap');
      var manualWrapEl = container.querySelector('#lrmManualWrap');
      var saveQrBtn = container.querySelector('#lrmSaveQrBtn');
      var copyQrBtn = container.querySelector('#lrmCopyQrBtn');
      var downloadQrBtn = container.querySelector('#lrmDownloadQrBtn');
      var downloadDesignerBtn = container.querySelector('#lrmDownloadDesignerBtn');
      var openQrBtn = container.querySelector('#lrmOpenQrBtn');
      var toggleQrBtn = container.querySelector('#lrmToggleQrBtn');
      var deleteQrBtn = container.querySelector('#lrmDeleteQrBtn');
      var statusEl = container.querySelector('#lrmStatus');

      var designerTheme = {
        bg: '#f5efe3',
        bgSoft: '#ebe1d0',
        canvas: '#fcf9f2',
        ink: '#2f241b',
        muted: '#7d6a59',
        accent: '#b67b45',
        accentStrong: '#94592b',
        accentSoft: '#ead7bf',
        sage: '#dbe6df'
      };

      function setStatus(message, isError) {
        statusEl.textContent = message || '';
        statusEl.style.color = isError ? '#9f3b2f' : '#6b5848';
      }

      function slugify(value) {
        return String(value || '')
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '')
          .slice(0, 160);
      }

      function toggleModeFields() {
        var isManual = qrModeEl.value === 'manual';
        manualWrapEl.classList.toggle('lrm-hidden', !isManual);
        presetWrapEl.classList.toggle('lrm-hidden', isManual);
      }

      function formatUpdatedText(item) {
        var updatedAt = item && item.updatedAt ? item.updatedAt : '';
        var updatedBy = item && item.updatedBy ? item.updatedBy : '';
        if (!updatedAt) return 'Last updated: -';
        return 'Last updated: ' + updatedAt + (updatedBy ? ' by ' + updatedBy : '');
      }

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function applyAppSettings(data) {
        var settings = data && data.settings ? data.settings : {};
        var pages = settings.menuBlockerPages || {};
        homeEl.checked = !!pages.home;
        menuEl.checked = !!pages.menu;
        cocktailEl.checked = !!pages.cocktail;
      }

      function getDefaultPresetKey() {
        var keys = module._presetOptions.map(function (option) { return option.key; });
        if (keys.indexOf('menu') !== -1) return 'menu';
        return keys[0] || '';
      }

      function renderPresetOptions(selectedKey) {
        var groups = {};
        if (!module._presetOptions.length) {
          qrPresetEl.innerHTML = '<option value="">No preset pages available</option>';
          qrPresetEl.disabled = true;
          return;
        }

        module._presetOptions.forEach(function (option) {
          var group = option.group || 'Pages';
          groups[group] = groups[group] || [];
          groups[group].push(option);
        });

        qrPresetEl.innerHTML = Object.keys(groups).map(function (groupName) {
          return '<optgroup label="' + escapeHtml(groupName) + '">' + groups[groupName].map(function (option) {
            return '<option value="' + escapeHtml(option.key) + '">' + escapeHtml(option.label) + '</option>';
          }).join('') + '</optgroup>';
        }).join('');

        qrPresetEl.disabled = false;
        qrPresetEl.value = selectedKey && module._presetOptions.some(function (option) { return option.key === selectedKey; })
          ? selectedKey
          : getDefaultPresetKey();
      }

      function updateQrPreview(record) {
        var publicUrl = String(record && record.publicUrl || '').trim();
        if (!publicUrl) {
          qrPreviewImgEl.removeAttribute('src');
          qrPreviewImgEl.classList.add('lrm-hidden');
          qrPreviewEmptyEl.classList.remove('lrm-hidden');
          qrPreviewMetaEl.textContent = 'The preview updates whenever you select a QR record.';
          return;
        }

        qrPreviewImgEl.src = buildQrImageUrl(publicUrl);
        qrPreviewImgEl.classList.remove('lrm-hidden');
        qrPreviewEmptyEl.classList.add('lrm-hidden');
        qrPreviewMetaEl.textContent = (record && record.name ? record.name + ' • ' : '') + publicUrl;
      }

      function populateEditor(item) {
        var record = item || {
          id: 0,
          name: '',
          slug: '',
          redirectMode: 'preset',
          presetKey: getDefaultPresetKey(),
          manualUrl: '',
          notes: '',
          isActive: true,
          isSystem: false,
          publicUrl: '',
          resolvedUrl: '',
          updatedAt: '',
          updatedBy: ''
        };

        qrIdEl.value = record.id || 0;
        qrNameEl.value = record.name || '';
        qrSlugEl.value = record.slug || '';
        qrStatusEl.value = record.isActive ? '1' : '0';
        qrModeEl.value = record.redirectMode || 'preset';
        renderPresetOptions(record.presetKey || getDefaultPresetKey());
        qrManualEl.value = record.manualUrl || '';
        qrNotesEl.value = record.notes || '';
        qrPublicUrlEl.textContent = record.publicUrl || 'Save the QR to generate its stable slug URL.';
        qrResolvedEl.textContent = 'Currently resolves to: ' + (record.resolvedUrl || '-');
        qrUpdatedEl.textContent = formatUpdatedText(record);
        updateQrPreview(record);
        toggleModeFields();
        toggleQrBtn.disabled = !record.id;
        deleteQrBtn.disabled = !record.id || !!record.isSystem;
        downloadQrBtn.disabled = !record.id || !record.publicUrl;
        downloadDesignerBtn.disabled = !record.id || !record.publicUrl;
      }

      function renderQrList() {
        recordsStatEl.textContent = String(module._records.length);
        activeStatEl.textContent = String(module._records.filter(function (item) { return item.isActive; }).length);

        if (!module._records.length) {
          qrListEl.innerHTML = '<div class="lrm-item"><div class="lrm-item-name">No QR records yet</div><div class="lrm-item-meta">Click "Generate New QR" to create the first reusable redirect.</div></div>';
          return;
        }

        qrListEl.innerHTML = module._records.map(function (item) {
          var badges = [];
          if (item.isSystem) badges.push('<span class="lrm-badge lrm-badge-system">System</span>');
          badges.push('<span class="lrm-badge ' + (item.isActive ? 'lrm-badge-active' : 'lrm-badge-off') + '">' + (item.isActive ? 'Active' : 'Inactive') + '</span>');
          return ''
            + '<div class="lrm-item ' + ((module._selectedId === item.id) ? 'active' : '') + '" data-id="' + item.id + '">'
            + '  <div class="lrm-item-top">'
            + '    <div>'
            + '      <div class="lrm-item-name">' + escapeHtml(item.name || 'Untitled QR') + '</div>'
            + '      <div class="lrm-item-slug">/qr/' + escapeHtml(item.slug || '') + '</div>'
            + '    </div>'
            + '    <div class="lrm-badges">' + badges.join('') + '</div>'
            + '  </div>'
            + '  <div class="lrm-item-meta">'
            + '    <div>Target: ' + escapeHtml(item.destinationLabel || '-') + '</div>'
            + '    <div>Public URL: ' + escapeHtml(item.publicUrl || '-') + '</div>'
            + '  </div>'
            + '</div>';
        }).join('');

        Array.prototype.forEach.call(qrListEl.querySelectorAll('.lrm-item[data-id]'), function (node) {
          node.addEventListener('click', function () {
            var id = Number(node.getAttribute('data-id') || 0);
            module._selectedId = id;
            populateEditor(module._records.find(function (item) { return item.id === id; }) || null);
            renderQrList();
          });
        });
      }

      function collectQrPayload() {
        return {
          id: Number(qrIdEl.value || 0),
          name: qrNameEl.value.trim(),
          slug: qrSlugEl.value.trim(),
          redirectMode: qrModeEl.value,
          presetKey: qrModeEl.value === 'preset' ? qrPresetEl.value : '',
          manualUrl: qrModeEl.value === 'manual' ? qrManualEl.value.trim() : '',
          notes: qrNotesEl.value.trim(),
          isActive: qrStatusEl.value === '1'
        };
      }

      function copyText(value, message) {
        if (!value) return;
        var done = function () { setStatus(message); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(value).then(done).catch(function () {
            var temp = document.createElement('textarea');
            temp.value = value;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            done();
          });
          return;
        }
      }

      function buildQrImageUrl(publicUrl) {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=900x900&data=' + encodeURIComponent(String(publicUrl || '').trim()) + '&format=png';
      }

      function loadImage(src) {
        return new Promise(function (resolve, reject) {
          var image = new Image();
          image.crossOrigin = 'anonymous';
          image.onload = function () { resolve(image); };
          image.onerror = reject;
          image.src = src;
        });
      }

      function deriveDesignerTitle(record) {
        var destinationKey = String(record && record.destinationKey || '').toLowerCase();
        var slug = String(record && record.slug || '').toLowerCase();
        var name = String(record && record.name || '').toLowerCase();
        if (destinationKey === 'admin' || slug.indexOf('admin') !== -1 || name.indexOf('admin') !== -1) {
          return 'Admin QR';
        }
        if (destinationKey === 'menu' || destinationKey === 'home' || slug.indexOf('guest') !== -1 || name.indexOf('guest') !== -1) {
          return 'Guest QR';
        }
        return 'Guest QR';
      }

      function downloadBlob(blob, fileName) {
        var url = URL.createObjectURL(blob);
        var anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = fileName;
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        URL.revokeObjectURL(url);
      }

      function downloadQrPng(record) {
        var publicUrl = String(record && record.publicUrl || '').trim();
        if (!publicUrl) {
          return Promise.reject(new Error('Save the QR first so its public URL is available.'));
        }

        return fetch(buildQrImageUrl(publicUrl), { mode: 'cors' })
          .then(function (response) {
            if (!response.ok) {
              throw new Error('Unable to download QR image.');
            }
            return response.blob();
          });
      }

      function ensureQrSheetReady() {
        if (!APPS_SCRIPT_URL) {
          return;
        }

        fetch(APPS_SCRIPT_URL + '?action=ensure_qr_sheet', { method: 'GET' })
          .catch(function () { /* non-critical */ });
      }

      function loadScanStats() {
        if (!APPS_SCRIPT_URL) {
          scansStatEl.textContent = 'N/A';
          milestoneStatEl.textContent = 'N/A';
          return;
        }

        fetch(APPS_SCRIPT_URL + '?action=qr_report', { method: 'GET', mode: 'cors', cache: 'no-store' })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data && data.ok) {
              var total = Number(data.totalScans || 0);
              var nextMilestone = Math.ceil((total + 1) / 100) * 100;
              scansStatEl.textContent = total.toLocaleString('en-IN');
              milestoneStatEl.textContent = nextMilestone.toLocaleString('en-IN') + ' scans';
              return;
            }

            scansStatEl.textContent = 'N/A';
            milestoneStatEl.textContent = 'N/A';
          })
          .catch(function () {
            scansStatEl.textContent = 'N/A';
            milestoneStatEl.textContent = 'N/A';
          });
      }

      function renderDesignerQr(record) {
        var publicUrl = String(record && record.publicUrl || '').trim();
        if (!publicUrl) {
          return Promise.reject(new Error('Save the QR first so its public URL is available.'));
        }

        var logoUrl = new URL('../assets/Logo/Namaste%20Kalyan%20by%20AWG%20-01.png', window.location.href).href;
        var qrUrl = buildQrImageUrl(publicUrl);
        return Promise.allSettled([loadImage(logoUrl), loadImage(qrUrl)]).then(function (results) {
          var logoResult = results[0];
          var qrResult = results[1];
          if (qrResult.status !== 'fulfilled') {
            throw new Error('Unable to render QR image right now.');
          }

          var logo = logoResult.status === 'fulfilled' ? logoResult.value : null;
          var qrImage = qrResult.value;
          var canvas = document.createElement('canvas');
          canvas.width = 1400;
          canvas.height = 1900;
          var ctx = canvas.getContext('2d');
          var title = deriveDesignerTitle(record);

          var bgGradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
          bgGradient.addColorStop(0, designerTheme.canvas);
          bgGradient.addColorStop(0.55, designerTheme.bg);
          bgGradient.addColorStop(1, designerTheme.bgSoft);
          ctx.fillStyle = bgGradient;
          ctx.fillRect(0, 0, canvas.width, canvas.height);

          ctx.fillStyle = 'rgba(182, 123, 69, 0.08)';
          ctx.beginPath();
          ctx.arc(180, 180, 210, 0, Math.PI * 2);
          ctx.fill();
          ctx.fillStyle = 'rgba(219, 230, 223, 0.9)';
          ctx.beginPath();
          ctx.arc(canvas.width - 180, canvas.height - 220, 240, 0, Math.PI * 2);
          ctx.fill();

          ctx.fillStyle = designerTheme.accentStrong;
          ctx.fillRect(68, 68, canvas.width - 136, canvas.height - 136);
          ctx.fillStyle = designerTheme.accentSoft;
          ctx.fillRect(92, 92, canvas.width - 184, canvas.height - 184);
          ctx.fillStyle = '#fffdf8';
          ctx.fillRect(122, 122, canvas.width - 244, canvas.height - 244);

          if (logo) {
            var logoMaxWidth = 440;
            var logoRatio = logo.width > 0 ? (logo.height / logo.width) : 0.28;
            var logoWidth = Math.min(logo.width || logoMaxWidth, logoMaxWidth);
            var logoHeight = logoWidth * (logoRatio || 0.28);
            ctx.drawImage(logo, (canvas.width - logoWidth) / 2, 182, logoWidth, logoHeight);
          } else {
            ctx.fillStyle = designerTheme.ink;
            ctx.textAlign = 'center';
            ctx.font = '700 58px Georgia';
            ctx.fillText('Namaste Kalyan', canvas.width / 2, 270);
          }

          ctx.fillStyle = designerTheme.muted;
          ctx.textAlign = 'center';
          ctx.font = '600 34px Avenir Next, Trebuchet MS, sans-serif';
          ctx.fillText('Scan to Open', canvas.width / 2, 430);

          var qrCardX = 250;
          var qrCardY = 500;
          var qrCardSize = 900;
          ctx.shadowColor = 'rgba(80, 57, 36, 0.16)';
          ctx.shadowBlur = 36;
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(qrCardX, qrCardY, qrCardSize, qrCardSize);
          ctx.shadowBlur = 0;
          ctx.strokeStyle = designerTheme.accent;
          ctx.lineWidth = 16;
          ctx.strokeRect(qrCardX, qrCardY, qrCardSize, qrCardSize);
          ctx.drawImage(qrImage, qrCardX + 60, qrCardY + 60, qrCardSize - 120, qrCardSize - 120);

          ctx.fillStyle = designerTheme.ink;
          ctx.textAlign = 'center';
          ctx.font = '700 44px Avenir Next, Trebuchet MS, sans-serif';
          ctx.fillText(String(record && record.name || title), canvas.width / 2, 1540);

          ctx.fillStyle = designerTheme.muted;
          ctx.font = '600 28px Avenir Next, Trebuchet MS, sans-serif';
          ctx.fillText(title, canvas.width / 2, 1612);

          ctx.fillStyle = designerTheme.accentStrong;
          ctx.font = '500 22px Avenir Next, Trebuchet MS, sans-serif';
          ctx.fillText(publicUrl, canvas.width / 2, 1710);

          return new Promise(function (resolve) {
            canvas.toBlob(function (blob) {
              resolve(blob);
            }, 'image/png');
          });
        });
      }

      function loadAll() {
        setStatus('Loading landing routing and QR registry...');
        return Promise.all([
          authClient.apiPost({ action: 'auth_get_app_settings' }),
          authClient.apiPost({ action: 'auth_list_qr_redirects' })
        ])
          .then(function (results) {
            applyAppSettings(results[0]);
            module._records = (results[1] && results[1].items) ? results[1].items : [];
            module._presetOptions = (results[1] && results[1].presetOptions) ? results[1].presetOptions : [];
            if (!module._selectedId && module._records.length) {
              module._selectedId = module._records[0].id;
            }
            renderQrList();
            populateEditor(module._records.find(function (item) { return item.id === module._selectedId; }) || null);
            loadScanStats();
            setStatus('Routing settings loaded.');
          })
          .catch(function (err) {
            setStatus('Failed to load landing routing settings: ' + err.message, true);
          });
      }

      saveBlockerBtn.addEventListener('click', function () {
        saveBlockerBtn.disabled = true;
        setStatus('Saving blocker placement...');
        authClient.apiPost({
          action: 'auth_set_app_settings',
          settings: {
            menuBlockerPages: {
              home: homeEl.checked,
              menu: menuEl.checked,
              cocktail: cocktailEl.checked
            }
          }
        })
          .then(function (result) {
            saveBlockerBtn.disabled = false;
            applyAppSettings(result);
            setStatus('Blocker placement saved.');
          })
          .catch(function (err) {
            saveBlockerBtn.disabled = false;
            setStatus('Failed to save blocker placement: ' + err.message, true);
          });
      });

      newQrBtn.addEventListener('click', function () {
        module._selectedId = 0;
        populateEditor(null);
        renderQrList();
        setStatus('New QR draft ready. Fill the form and save it to create a stable slug URL.');
      });

      reloadBtn.addEventListener('click', function () {
        loadAll();
      });

      qrNameEl.addEventListener('input', function () {
        if (!qrIdEl.value || Number(qrIdEl.value) === 0) {
          qrSlugEl.value = slugify(qrNameEl.value);
        }
      });

      qrModeEl.addEventListener('change', toggleModeFields);

      saveQrBtn.addEventListener('click', function () {
        var payload = collectQrPayload();
        saveQrBtn.disabled = true;
        setStatus((payload.id ? 'Updating' : 'Creating') + ' QR redirect...');
        authClient.apiPost({ action: 'auth_save_qr_redirect', record: payload })
          .then(function (result) {
            saveQrBtn.disabled = false;
            module._records = (result && result.items) ? result.items : [];
            module._presetOptions = (result && result.presetOptions) ? result.presetOptions : module._presetOptions;
            if (result && result.item && result.item.id) {
              module._selectedId = result.item.id;
            }
            renderQrList();
            populateEditor(module._records.find(function (item) { return item.id === module._selectedId; }) || null);
            setStatus('QR redirect saved. The slug URL is now ready for printing or sharing.');
          })
          .catch(function (err) {
            saveQrBtn.disabled = false;
            setStatus('Failed to save QR redirect: ' + err.message, true);
          });
      });

      copyQrBtn.addEventListener('click', function () {
        copyText(qrPublicUrlEl.textContent, 'QR URL copied.');
      });

      downloadQrBtn.addEventListener('click', function () {
        var recordId = Number(qrIdEl.value || 0);
        var current = module._records.find(function (item) { return item.id === recordId; }) || null;
        if (!recordId || !current) {
          setStatus('Select or save a QR first.', true);
          return;
        }

        downloadQrBtn.disabled = true;
        setStatus('Downloading QR PNG...');
        downloadQrPng(current)
          .then(function (blob) {
            downloadBlob(blob, (current.slug || 'qr') + '.png');
            setStatus('QR PNG downloaded.');
          })
          .catch(function (err) {
            setStatus('Failed to download QR PNG: ' + err.message, true);
          })
          .finally(function () {
            downloadQrBtn.disabled = !recordId || !current.publicUrl;
          });
      });

      downloadDesignerBtn.addEventListener('click', function () {
        var recordId = Number(qrIdEl.value || 0);
        var current = module._records.find(function (item) { return item.id === recordId; }) || null;
        if (!recordId || !current) {
          setStatus('Select or save a QR first.', true);
          return;
        }

        downloadDesignerBtn.disabled = true;
        setStatus('Rendering designer PNG...');
        renderDesignerQr(current)
          .then(function (blob) {
            if (!blob) {
              throw new Error('Unable to generate PNG.');
            }
            downloadBlob(blob, (current.slug || 'qr') + '-designer.png');
            setStatus('Designer QR PNG downloaded.');
          })
          .catch(function (err) {
            setStatus('Failed to render designer PNG: ' + err.message, true);
          })
          .finally(function () {
            downloadDesignerBtn.disabled = !recordId || !current.publicUrl;
          });
      });

      openQrBtn.addEventListener('click', function () {
        var url = String(qrPublicUrlEl.textContent || '').trim();
        if (!url || url.indexOf('http') !== 0) {
          setStatus('Save the QR first so the public slug URL is available.', true);
          return;
        }
        window.open(url, '_blank', 'noopener,noreferrer');
      });

      toggleQrBtn.addEventListener('click', function () {
        var recordId = Number(qrIdEl.value || 0);
        if (!recordId) {
          setStatus('Select or save a QR first.', true);
          return;
        }
        var nextActive = qrStatusEl.value !== '1';
        toggleQrBtn.disabled = true;
        setStatus((nextActive ? 'Activating' : 'Deactivating') + ' QR redirect...');
        authClient.apiPost({ action: 'auth_set_qr_redirect_active', id: recordId, isActive: nextActive })
          .then(function (result) {
            toggleQrBtn.disabled = false;
            module._records = (result && result.items) ? result.items : [];
            module._presetOptions = (result && result.presetOptions) ? result.presetOptions : module._presetOptions;
            renderQrList();
            populateEditor(module._records.find(function (item) { return item.id === recordId; }) || null);
            setStatus('QR redirect status updated.');
          })
          .catch(function (err) {
            toggleQrBtn.disabled = false;
            setStatus('Failed to update QR status: ' + err.message, true);
          });
      });

      deleteQrBtn.addEventListener('click', function () {
        var recordId = Number(qrIdEl.value || 0);
        var current = module._records.find(function (item) { return item.id === recordId; }) || null;
        if (!recordId || !current) {
          setStatus('Select a QR first.', true);
          return;
        }
        if (current.isSystem) {
          setStatus('System QR redirects cannot be deleted.', true);
          return;
        }
        if (!window.confirm('Delete QR "' + (current.name || 'Untitled QR') + '"? This cannot be undone.')) {
          return;
        }

        deleteQrBtn.disabled = true;
        setStatus('Deleting QR redirect...');
        authClient.apiPost({ action: 'auth_delete_qr_redirect', id: recordId })
          .then(function (result) {
            deleteQrBtn.disabled = false;
            module._records = (result && result.items) ? result.items : [];
            module._presetOptions = (result && result.presetOptions) ? result.presetOptions : module._presetOptions;
            module._selectedId = module._records.length ? module._records[0].id : 0;
            renderQrList();
            populateEditor(module._records.find(function (item) { return item.id === module._selectedId; }) || null);
            setStatus('QR redirect deleted.');
          })
          .catch(function (err) {
            deleteQrBtn.disabled = false;
            setStatus('Failed to delete QR redirect: ' + err.message, true);
          });
      });

      reportBtn.addEventListener('click', function () {
        window.open(new URL('../qr/report.html', window.location.href).href, 'QR_Report', 'width=1200,height=800,resizable=yes,scrollbars=yes');
      });

      ensureQrSheetReady();
      loadAll();
      module._statsInterval = setInterval(loadScanStats, 60000);
    },

    destroy: function () {
      if (this._statsInterval) {
        clearInterval(this._statsInterval);
        this._statsInterval = null;
      }
      this._container = null;
      this._authClient = null;
      this._records = [];
      this._presetOptions = [];
      this._selectedId = 0;
    }
  };
})(window.NK || (window.NK = {}));