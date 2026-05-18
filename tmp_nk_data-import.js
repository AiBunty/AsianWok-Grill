/* data-import.js — Menu Excel import/export, image ZIP upload, snapshot management
 * Requires: admin-auth.js (authClient), base.js (NK.MODULE_BASE.escHtml)
 */
(function (window) {
  'use strict';
  var NK = window.NK = (window.NK || {});
  NK.MODULES = NK.MODULES || {};

  NK.MODULES['data-import'] = {
    _container: null,
    _authClient: null,
    _phpApiUrl: null,
    _state: null,

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    init: function (container, authClient /*, user */) {
      this._container = container;
      this._authClient = authClient;
      this._phpApiUrl = (window.NK_DATA_API && window.NK_DATA_API.phpApiUrl) || '';
      this._state = { previewData: null, previewTmpPath: null, snapshots: [] };
      var module = this;
      container.innerHTML = module._buildHtml();
      module._injectStyles();
      module._bindEvents();
      module._loadSnapshots();
    },

    destroy: function () {
      this._container = null;
      this._authClient = null;
      this._phpApiUrl = null;
      this._state = null;
    },

    // ─── Raw API helpers (bypasses apiPost to support blobs + multipart) ──────

    _jsonPost: function (body) {
      var module = this;
      var token = (module._authClient && module._authClient.getToken()) || '';
      var postBody = Object.assign({}, body, { token: token });
      var actionParam = (body && body.action) ? ('?action=' + encodeURIComponent(body.action)) : '';
      return fetch(module._phpApiUrl.split('?')[0] + actionParam, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: 'payload=' + encodeURIComponent(JSON.stringify(postBody))
      }).then(function (r) {
        return r.json().then(function (payload) {
          if (!r.ok || !payload || payload.ok !== true) {
            throw new Error((payload && (payload.message || payload.error)) || ('HTTP ' + r.status));
          }
          return payload;
        });
      });
    },

    _multipartPost: function (formData) {
      var module = this;
      var token = (module._authClient && module._authClient.getToken()) || '';
      formData.append('token', token);
      return fetch(module._phpApiUrl, { method: 'POST', body: formData })
        .then(function (r) {
          return r.json().then(function (payload) {
            if (!r.ok || !payload || payload.ok !== true) {
              throw new Error((payload && (payload.message || payload.error)) || ('HTTP ' + r.status));
            }
            return payload;
          });
        });
    },

    _blobPost: function (body, filename) {
      var module = this;
      var token = (module._authClient && module._authClient.getToken()) || '';
      var postBody = Object.assign({}, body, { token: token });
      var actionParam = (body && body.action) ? ('?action=' + encodeURIComponent(body.action)) : '';
      return fetch(module._phpApiUrl.split('?')[0] + actionParam, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: 'payload=' + encodeURIComponent(JSON.stringify(postBody))
      }).then(function (r) {
        // Detect JSON error responses (PHP returns JSON when export fails)
        var ct = r.headers.get('Content-Type') || '';
        if (ct.indexOf('application/json') !== -1) {
          return r.json().then(function (payload) {
            throw new Error((payload && (payload.message || payload.error)) || ('HTTP ' + r.status));
          });
        }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.blob();
      }).then(function (blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      });
    },

    // ─── Status helper ────────────────────────────────────────────────────────

    _setStatus: function (elId, msg, type) {
      var c = this._container;
      if (!c) return;
      var el = c.querySelector('#' + elId);
      if (!el) return;
      el.textContent = msg;
      el.className = 'di-status'
        + (type === 'error' ? ' di-status-error' : type === 'ok' ? ' di-status-ok' : '');
    },

    // ─── Snapshots ────────────────────────────────────────────────────────────

    _loadSnapshots: function () {
      var module = this;
      module._setStatus('snapshotStatus', 'Loading…', '');
      module._jsonPost({ action: 'admin_snapshot_list' })
        .then(function (payload) {
          module._state.snapshots = payload.snapshots || [];
          module._renderSnapshots();
          module._setStatus('snapshotStatus', '', '');
        })
        .catch(function (err) {
          module._setStatus('snapshotStatus', err.message || 'Failed to load snapshots.', 'error');
        });
    },

    _renderSnapshots: function () {
      var module = this;
      var c = module._container;
      if (!c) return;
      var tableBody = c.querySelector('#diSnapshotTableBody');
      if (!tableBody) return;
      var snapshots = module._state.snapshots;
      if (!snapshots.length) {
        tableBody.innerHTML = '<tr><td colspan="5" class="di-empty">No snapshots yet.</td></tr>';
        return;
      }
      tableBody.innerHTML = snapshots.map(function (snap) {
        return '<tr>'
          + '<td>' + NK.MODULE_BASE.escHtml(snap.sheet_type || '') + '</td>'
          + '<td>' + NK.MODULE_BASE.escHtml(snap.label || '') + '</td>'
          + '<td>' + NK.MODULE_BASE.escHtml(snap.triggered_by || '') + '</td>'
          + '<td>' + NK.MODULE_BASE.escHtml(snap.created_at || '') + '</td>'
          + '<td><button class="di-btn di-btn-sm" data-action="restore" data-snap-id="'
          + NK.MODULE_BASE.escHtml(String(snap.id)) + '">Restore</button></td>'
          + '</tr>';
      }).join('');
    },

    // ─── Preview modal ────────────────────────────────────────────────────────

    _showPreviewModal: function (sheetType) {
      var module = this;
      var c = module._container;
      if (!c) return;
      var data = module._state.previewData;
      var modal = c.querySelector('#diPreviewModal');
      var rows = (data && (data.sample_rows || data.rows)) || [];
      var totalRows = (data && (data.total_rows || data.data_rows || (data.previewSummary && data.previewSummary.rows))) || rows.length;

      c.querySelector('#diPreviewSummary').textContent = 'Sheet: ' + sheetType
        + '  |  Rows in file: ' + totalRows
        + (rows.length < totalRows ? '  (showing first ' + rows.length + ')' : '');

      // Determine columns dynamically from first row, falling back to known defaults
      var keys = (rows.length > 0) ? Object.keys(rows[0]) : ['item_name', 'category', 'base_price', 'primary_diet', 'is_available'];
      var labels = keys;
      var colspan = keys.length || 5;
      c.querySelector('#diPreviewTableHead').innerHTML =
        '<tr>' + labels.map(function (l) { return '<th>' + NK.MODULE_BASE.escHtml(l) + '</th>'; }).join('') + '</tr>';

      c.querySelector('#diPreviewTableBody').innerHTML = rows.length
        ? rows.map(function (row) {
            return '<tr>' + keys.map(function (k) {
              var v = row[k] !== undefined && row[k] !== null ? String(row[k]) : '';
              return '<td>' + NK.MODULE_BASE.escHtml(v) + '</td>';
            }).join('') + '</tr>';
          }).join('')
        : '<tr><td colspan="' + colspan + '" class="di-empty">File appears empty.</td></tr>';

      modal.classList.remove('di-hidden');
    },

    _closePreviewModal: function () {
      var c = this._container;
      if (!c) return;
      var modal = c.querySelector('#diPreviewModal');
      if (modal) modal.classList.add('di-hidden');
    },

    // ─── Events ───────────────────────────────────────────────────────────────

    _bindEvents: function () {
      var module = this;
      var c = module._container;
      if (!c) return;

      // Export
      c.querySelector('#diDownloadFood').addEventListener('click', function () {
        module._setStatus('exportStatus', 'Generating file…', '');
        module._blobPost({ action: 'admin_food_menu_export' }, 'food-menu.xlsx')
          .then(function () { module._setStatus('exportStatus', 'Download started.', 'ok'); })
          .catch(function (e) { module._setStatus('exportStatus', e.message || 'Export failed.', 'error'); });
      });

      c.querySelector('#diDownloadBar').addEventListener('click', function () {
        module._setStatus('exportStatus', 'Generating file…', '');
        module._blobPost({ action: 'admin_bar_menu_export' }, 'bar-menu.xlsx')
          .then(function () { module._setStatus('exportStatus', 'Download started.', 'ok'); })
          .catch(function (e) { module._setStatus('exportStatus', e.message || 'Export failed.', 'error'); });
      });

      // Import preview
      c.querySelector('#diPreviewBtn').addEventListener('click', function () {
        var fileInput = c.querySelector('#diImportFile');
        var sheetType = c.querySelector('#diImportSheet').value;
        if (!fileInput.files || !fileInput.files.length) {
          module._setStatus('importStatus', 'Please select an Excel file first.', 'error');
          return;
        }
        var fd = new FormData();
        fd.append('action', sheetType === 'bar' ? 'admin_bar_menu_import_preview' : 'admin_food_menu_import_preview');
        fd.append('file', fileInput.files[0]);
        module._setStatus('importStatus', 'Reading file…', '');
        module._multipartPost(fd)
          .then(function (payload) {
            module._state.previewData    = payload;
            module._state.previewTmpPath = payload.tmpPath || '';
            module._showPreviewModal(sheetType);
            var summary = payload.previewSummary || {};
            var unresolved = payload.categoryMappingActions && payload.categoryMappingActions.unresolved
              ? payload.categoryMappingActions.unresolved.length
              : 0;
            module._setStatus('importStatus',
              'Preview ready. Rows: ' + (summary.rows || payload.data_rows || 0)
              + ', skipped: ' + (summary.skipped || payload.blank_rows_skipped || 0)
              + (unresolved ? ', unresolved categories: ' + unresolved : ''),
              unresolved ? 'error' : 'ok');
          })
          .catch(function (e) { module._setStatus('importStatus', e.message || 'Preview failed.', 'error'); });
      });

      // Confirm import (inside modal)
      c.querySelector('#diConfirmImportBtn').addEventListener('click', function () {
        var sheetType    = c.querySelector('#diImportSheet').value;
        var takeSnapshot = c.querySelector('#diImportSnapshot').checked;
        var tmpPath      = module._state.previewTmpPath;
        if (!tmpPath) {
          module._setStatus('importStatus', 'No preview file found. Please preview first.', 'error');
          return;
        }
        var previewData = module._state.previewData || {};
        var unresolved = previewData.categoryMappingActions && previewData.categoryMappingActions.unresolved
          ? previewData.categoryMappingActions.unresolved
          : [];
        var createCategories = [];
        for (var i = 0; i < unresolved.length; i += 1) {
          var entry = unresolved[i] || {};
          var input = String(entry.input || '').trim();
          if (!input) continue;
          if (!window.confirm('Create new canonical category "' + input + '" for the ' + sheetType + ' import?')) {
            module._setStatus('importStatus', 'Import cancelled. Category "' + input + '" was not confirmed.', 'error');
            return;
          }
          createCategories.push(input);
        }
        module._setStatus('importStatus', 'Importing…', '');
        module._jsonPost({
          action: sheetType === 'bar' ? 'admin_bar_menu_import_execute' : 'admin_food_menu_import_execute',
          tmpPath: tmpPath,
          takeSnapshot: takeSnapshot ? 1 : 0,
          createCategories: createCategories
        }).then(function (payload) {
          module._closePreviewModal();
          module._setStatus('importStatus',
            'Imported ' + (payload.inserted || payload.items_inserted || 0) + ' rows successfully.', 'ok');
          module._state.previewData    = null;
          module._state.previewTmpPath = null;
          module._loadSnapshots();
        }).catch(function (e) {
          module._setStatus('importStatus', e.message || 'Import failed.', 'error');
        });
      });

      c.querySelector('#diCancelImportBtn').addEventListener('click', function () {
        module._closePreviewModal();
      });

      // Image upload
      c.querySelector('#diImageUploadBtn').addEventListener('click', function () {
        var fileInput = c.querySelector('#diImageFile');
        var sheetType = c.querySelector('#diImageSheet').value;
        if (!fileInput.files || !fileInput.files.length) {
          module._setStatus('imageStatus', 'Please select a ZIP file first.', 'error');
          return;
        }
        var fd = new FormData();
        fd.append('action', 'admin_image_upload');
        fd.append('sheetType', sheetType);
        fd.append('file', fileInput.files[0]);
        module._setStatus('imageStatus', 'Uploading and processing images…', '');
        module._multipartPost(fd)
          .then(function (payload) {
            var skippedCount = typeof payload.skippedCount === 'number'
              ? payload.skippedCount
              : (Array.isArray(payload.skipped) ? payload.skipped.length : 0);
            var failedCount = typeof payload.failedCount === 'number'
              ? payload.failedCount
              : (Array.isArray(payload.failed) ? payload.failed.length : 0);
            module._setStatus('imageStatus',
              'Done — Processed: ' + (payload.processed || 0)
              + ', Skipped: ' + skippedCount
              + ', Failed: ' + failedCount + '.', 'ok');
          })
          .catch(function (e) { module._setStatus('imageStatus', e.message || 'Upload failed.', 'error'); });
      });

      // Template download
      var templateFood = c.querySelector('#diTemplateFood');
      if (templateFood) {
        templateFood.addEventListener('click', function () {
          module._setStatus('importStatus', 'Preparing template…', '');
          module._blobPost({ action: 'admin_food_menu_template' }, 'food-import-template.xlsx')
            .then(function () { module._setStatus('importStatus', 'Template downloaded.', 'ok'); })
            .catch(function (e) { module._setStatus('importStatus', e.message || 'Download failed.', 'error'); });
        });
      }
      var templateBar = c.querySelector('#diTemplateBar');
      if (templateBar) {
        templateBar.addEventListener('click', function () {
          module._setStatus('importStatus', 'Preparing template…', '');
          module._blobPost({ action: 'admin_bar_menu_template' }, 'bar-import-template.xlsx')
            .then(function () { module._setStatus('importStatus', 'Template downloaded.', 'ok'); })
            .catch(function (e) { module._setStatus('importStatus', e.message || 'Download failed.', 'error'); });
        });
      }

      // Snapshot restore (delegated)
      c.querySelector('#diSnapshotTableBody').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action="restore"]');
        if (!btn) return;
        var snapId = btn.getAttribute('data-snap-id');
        if (!window.confirm('Restore this snapshot? The current menu data will be replaced.')) return;
        module._setStatus('snapshotStatus', 'Restoring snapshot…', '');
        module._jsonPost({ action: 'admin_snapshot_restore', snapshotId: snapId })
          .then(function () {
            module._setStatus('snapshotStatus', 'Snapshot restored successfully.', 'ok');
            module._loadSnapshots();
          })
          .catch(function (e) {
            module._setStatus('snapshotStatus', e.message || 'Restore failed.', 'error');
          });
      });
    },

    // ─── HTML ─────────────────────────────────────────────────────────────────

    _buildHtml: function () {
      return [
        '<div class="di-wrap">',

        // Export
        '<section class="di-section">',
        '<h3 class="di-section-title">Export Menu to Excel</h3>',
        '<div class="di-row">',
        '<button id="diDownloadFood" class="di-btn">&#x2B07; Food Menu (.xlsx)</button>',
        '<button id="diDownloadBar"  class="di-btn">&#x2B07; Bar Menu (.xlsx)</button>',
        '</div>',
        '<div id="exportStatus" class="di-status"></div>',
        '</section>',

        // Import
        '<section class="di-section">',
        '<h3 class="di-section-title">Import Menu from Excel</h3>',
        '<div class="di-row">',
        '<select id="diImportSheet" class="di-select">',
        '<option value="food">Food Menu</option>',
        '<option value="bar">Bar Menu</option>',
        '</select>',
        '<label class="di-file-label">',
        '<input type="file" id="diImportFile" accept=".xlsx,.xls">',
        'Choose Excel file',
        '</label>',
        '<button id="diPreviewBtn" class="di-btn">Preview Import</button>',
        '</div>',
        '<div class="di-row di-template-row">',
        '<span class="di-hint">Download a blank template with correct column headers:</span>',
        '<button id="diTemplateFood" class="di-btn di-btn-sec">&#x2B07; Food Template</button>',
        '<button id="diTemplateBar"  class="di-btn di-btn-sec">&#x2B07; Bar Template</button>',
        '</div>',
        '<div id="importStatus" class="di-status"></div>',
        '</section>',

        // Image upload
        '<section class="di-section">',
        '<h3 class="di-section-title">Upload Item Images (ZIP)</h3>',
        '<p class="di-hint">For Food Menu, ZIP filenames can use the exported <code>Image File Name</code> value such as <code>starters--crispy-corn.jpg</code> or a legacy numeric item ID. Bar Menu ZIPs still use numeric item IDs. Max 10 MB per image. Uploaded images become the default image, otherwise the saved Image URL is used, otherwise the site fallback image is shown.</p>',
        '<div class="di-row">',
        '<select id="diImageSheet" class="di-select">',
        '<option value="food">Food Menu</option>',
        '<option value="bar">Bar Menu</option>',
        '</select>',
        '<label class="di-file-label">',
        '<input type="file" id="diImageFile" accept=".zip">',
        'Choose ZIP file',
        '</label>',
        '<button id="diImageUploadBtn" class="di-btn">Upload Images</button>',
        '</div>',
        '<div id="imageStatus" class="di-status"></div>',
        '</section>',

        // Snapshots
        '<section class="di-section">',
        '<h3 class="di-section-title">Snapshots</h3>',
        '<div id="snapshotStatus" class="di-status"></div>',
        '<div class="di-table-wrap">',
        '<table class="di-table">',
        '<thead><tr><th>Sheet</th><th>Label</th><th>Triggered By</th><th>Created At</th><th></th></tr></thead>',
        '<tbody id="diSnapshotTableBody"><tr><td colspan="5" class="di-empty">Loading\u2026</td></tr></tbody>',
        '</table>',
        '</div>',
        '</section>',

        // Preview modal
        '<div id="diPreviewModal" class="di-modal-backdrop di-hidden" role="dialog" aria-modal="true" aria-labelledby="diPreviewModalTitle">',
        '<div class="di-modal">',
        '<h3 id="diPreviewModalTitle" class="di-modal-title">Import Preview</h3>',
        '<p id="diPreviewSummary" class="di-hint"></p>',
        '<label class="di-checkbox-label">',
        '<input type="checkbox" id="diImportSnapshot" checked>',
        'Take a snapshot of current data before importing (recommended)',
        '</label>',
        '<div class="di-preview-table-wrap">',
        '<table class="di-table">',
        '<thead id="diPreviewTableHead"></thead>',
        '<tbody id="diPreviewTableBody"></tbody>',
        '</table>',
        '</div>',
        '<div class="di-modal-actions">',
        '<button id="diConfirmImportBtn" class="di-btn di-btn-primary">Confirm &amp; Import</button>',
        '<button id="diCancelImportBtn" class="di-btn">Cancel</button>',
        '</div>',
        '</div>',
        '</div>',

        '</div>' // .di-wrap
      ].join('\n');
    },

    // ─── CSS ──────────────────────────────────────────────────────────────────

    _injectStyles: function () {
      if (document.getElementById('nk-data-import-styles')) return;
      var css = [
        '.di-wrap { display:flex; flex-direction:column; gap:24px; padding:16px 0; max-width:920px; }',
        '.di-section { background:#fff; border:1px solid #e2d9cf; border-radius:8px; padding:16px 20px; }',
        '.di-section-title { font-size:1rem; font-weight:600; color:#5a3e28; margin:0 0 12px; }',
        '.di-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }',
        '.di-hint { font-size:0.83rem; color:#888; margin:0 0 10px; }',
        '.di-hint code { font-size:0.9em; background:#f5f0eb; padding:1px 4px; border-radius:3px; }',
        '.di-status { font-size:0.83rem; margin-top:8px; min-height:18px; }',
        '.di-status-ok    { color:#2e7d32; }',
        '.di-status-error { color:#c0392b; }',
        '.di-btn { padding:7px 14px; font-size:0.84rem; border:1px solid #c6a882; border-radius:5px; background:#f7f0e6; color:#5a3e28; cursor:pointer; white-space:nowrap; }',
        '.di-btn:hover:not(:disabled) { background:#ede0ce; }',
        '.di-btn-primary { background:#b67b45; color:#fff; border-color:#9a6233; }',
        '.di-btn-primary:hover:not(:disabled) { background:#a06a38; }',
        '.di-btn-sec { background:transparent; color:#5a3e28; border-color:#c6a882; }',
        '.di-btn-sec:hover:not(:disabled) { background:#f3ead9; }',
        '.di-btn-sm { padding:4px 10px; font-size:0.77rem; }',
        '.di-template-row { align-items:center; flex-wrap:wrap; gap:8px; margin-top:6px; }',
        '.di-select { padding:6px 10px; border:1px solid #c6a882; border-radius:5px; font-size:0.84rem; background:#fff; }',
        '.di-file-label { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; font-size:0.84rem; border:1px solid #c6a882; border-radius:5px; background:#fff; cursor:pointer; }',
        '.di-file-label:hover { background:#f7f0e6; }',
        '.di-file-label input[type="file"] { max-width:200px; font-size:0.8rem; }',
        '.di-table-wrap { overflow-x:auto; }',
        '.di-table { width:100%; border-collapse:collapse; font-size:0.83rem; }',
        '.di-table th { background:#f5ede0; font-weight:600; padding:7px 10px; border-bottom:2px solid #e2d9cf; text-align:left; white-space:nowrap; }',
        '.di-table td { padding:6px 10px; border-bottom:1px solid #f0e8de; vertical-align:top; }',
        '.di-table tr:last-child td { border-bottom:none; }',
        '.di-empty { color:#aaa; font-style:italic; text-align:center !important; padding:14px !important; }',
        '.di-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.48); z-index:2000; display:flex; align-items:center; justify-content:center; padding:16px; }',
        '.di-modal-backdrop.di-hidden { display:none; }',
        '.di-modal { background:#fff; border-radius:10px; padding:24px; max-width:800px; width:100%; max-height:88vh; display:flex; flex-direction:column; gap:14px; overflow:hidden; }',
        '.di-modal-title { font-size:1.05rem; font-weight:700; color:#5a3e28; margin:0; }',
        '.di-preview-table-wrap { overflow:auto; flex:1; border:1px solid #e2d9cf; border-radius:5px; }',
        '.di-modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:4px; }',
        '.di-checkbox-label { display:flex; align-items:center; gap:8px; font-size:0.84rem; cursor:pointer; user-select:none; }'
      ].join('\n');
      var style = document.createElement('style');
      style.id = 'nk-data-import-styles';
      style.textContent = css;
      document.head.appendChild(style);
    }
  };

})(window);

