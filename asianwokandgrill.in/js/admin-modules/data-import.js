import { adminApi, adminMultipart, downloadBase64File } from './api-client.js';

const MENU_OPTIONS = [
  { value: 'menu_a', label: 'Menu A (menu.html)' },
  { value: 'menu_b', label: 'Menu B (cocktail.html)' },
  { value: 'menu_c', label: 'Menu C (namastemenu.html)' },
];

export async function renderDataImport(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Data Import / Export</h3>
      <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:12px;">
        <label>
          Menu
          <select id="diMenuType">${MENU_OPTIONS.map((opt) => `<option value="${opt.value}">${opt.label}</option>`).join('')}</select>
        </label>
        <label>
          Excel (.xlsx)
          <input id="diFile" type="file" accept=".xlsx" />
        </label>
      </div>

      <div class="admin-form-actions" style="margin-bottom:12px;">
        <button id="diPreview" class="admin-button" type="button">Import Preview</button>
        <button id="diExecute" class="admin-button admin-button-secondary" type="button">Import Execute</button>
        <button id="diExport" class="admin-button admin-button-secondary" type="button">Export Excel</button>
        <button id="diTemplate" class="admin-button admin-button-secondary" type="button">Download Template</button>
      </div>

      <div id="diStatus" class="admin-form-status">Ready.</div>
      <div id="diPreviewPanel"></div>
    </section>
  `;

  const menuTypeNode = container.querySelector('#diMenuType');
  const fileNode = container.querySelector('#diFile');
  const statusNode = container.querySelector('#diStatus');
  const previewNode = container.querySelector('#diPreviewPanel');

  let previewState = null;

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  function renderPreview(data) {
    const sampleRows = Array.isArray(data.sample_rows) ? data.sample_rows : [];
    const rows = sampleRows.slice(0, 6);
    const columns = collectColumns(rows);

    previewNode.innerHTML = `
      <div class="admin-module-item" style="margin-bottom:8px;">
        <div>
          <strong>Preview Summary</strong>
          <div>${escapeHtml(data.previewSummary || '-')}</div>
        </div>
      </div>
      <div class="admin-module-item" style="margin-bottom:8px;">
        <div>Total rows: ${Number(data.total_rows || 0)} • Data rows: ${Number(data.data_rows || 0)} • Blank skipped: ${Number(data.blank_rows_skipped || 0)}</div>
      </div>
      <div class="admin-module-item" style="margin-bottom:8px;">
        <div>Mapped: ${escapeHtml(JSON.stringify(data.mapped_columns || {}))}</div>
      </div>
      <div class="admin-module-item" style="margin-bottom:8px;">
        <div>Unmapped: ${escapeHtml(JSON.stringify(data.unmapped_columns || []))}</div>
      </div>
      <div class="admin-module-item" style="margin-bottom:8px;">
        <div>Variant Columns: ${escapeHtml(JSON.stringify(data.variant_columns || []))}</div>
      </div>
      ${rows.length ? `
      <div style="overflow:auto;">
        <table class="admin-table" style="min-width:980px; width:100%;">
          <thead>
            <tr>${columns.map((col) => `<th>${escapeHtml(col)}</th>`).join('')}</tr>
          </thead>
          <tbody>
            ${rows.map((row) => `
              <tr>
                ${columns.map((col) => `<td>${escapeHtml(stringifyCell(row[col]))}</td>`).join('')}
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
      ` : '<div class="admin-module-empty">No sample rows available.</div>'}
    `;
  }

  container.querySelector('#diPreview').addEventListener('click', async () => {
    try {
      const file = fileNode.files && fileNode.files[0];
      if (!file) {
        setStatus('Choose an Excel file first.', true);
        return;
      }

      const fd = new FormData();
      fd.append('menuType', menuTypeNode.value);
      fd.append('file', file);

      setStatus('Generating preview...', false);
      const payload = await adminMultipart('admin_menu_import_preview', fd);
      previewState = payload;
      renderPreview(payload);
      setStatus('Preview generated. Review rows and run execute.', false);
    } catch (error) {
      setStatus(error.message || 'Preview failed.', true);
    }
  });

  container.querySelector('#diExecute').addEventListener('click', async () => {
    try {
      if (!previewState || !previewState.tmpPath) {
        setStatus('Run import preview first.', true);
        return;
      }

      const createCategories = window.confirm('Create missing categories automatically? Click Cancel to skip unresolved categories.');
      const takeSnapshot = window.confirm('Take a pre-import snapshot before writing data?');

      setStatus('Executing import transaction...', false);
      const payload = await adminApi('admin_menu_import_execute', {
        method: 'POST',
        body: {
          menuType: menuTypeNode.value,
          tmpPath: previewState.tmpPath,
          createCategories,
          takeSnapshot,
        },
      });

      const warningText = Array.isArray(payload.warnings) ? payload.warnings.slice(0, 5).join(' | ') : '';
      setStatus(`Import done. Inserted ${payload.inserted || 0}, updated ${payload.updated || 0}, skipped ${payload.skipped || 0}${warningText ? `. Warnings: ${warningText}` : ''}`, false);
    } catch (error) {
      setStatus(error.message || 'Import execute failed.', true);
    }
  });

  container.querySelector('#diExport').addEventListener('click', async () => {
    try {
      setStatus('Building export workbook...', false);
      const payload = await adminApi('admin_menu_export', {
        query: {
          menuType: menuTypeNode.value,
        },
      });

      downloadBase64File(payload.fileName, payload.mimeType, payload.base64);
      setStatus(`Export ready: ${payload.fileName}`, false);
    } catch (error) {
      setStatus(error.message || 'Export failed.', true);
    }
  });

  container.querySelector('#diTemplate').addEventListener('click', async () => {
    try {
      setStatus('Building import template...', false);
      const payload = await adminApi('admin_menu_template', {
        query: {
          menuType: menuTypeNode.value,
        },
      });

      downloadBase64File(payload.fileName, payload.mimeType, payload.base64);
      setStatus(`Template ready: ${payload.fileName}`, false);
    } catch (error) {
      setStatus(error.message || 'Template download failed.', true);
    }
  });
}

function collectColumns(rows) {
  const set = new Set();
  rows.forEach((row) => {
    Object.keys(row || {}).forEach((key) => set.add(key));
  });
  return Array.from(set).slice(0, 12);
}

function stringifyCell(value) {
  if (value === null || value === undefined) return '';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
