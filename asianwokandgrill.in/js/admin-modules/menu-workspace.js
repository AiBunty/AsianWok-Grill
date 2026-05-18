import { adminApi } from './api-client.js';

const INTEGRATED_MENUS = [
  {
    key: 'awg_main',
    title: 'AWG Main Menu',
    description: 'Primary Asian Wok menu feed.',
    liveUrl: '/menu.html',
    action: 'menu_public_items',
  },
  {
    key: 'namastemenu',
    title: 'Namaste Menu',
    description: 'Namaste menu feed for Menu C.',
    liveUrl: '/namastemenu.html',
    action: 'menu_public_items',
  },
  {
    key: 'cocktail',
    title: 'Cocktail Menu',
    description: 'Bar and cocktail feed.',
    liveUrl: '/cocktail.html',
    action: 'menu_public_cocktail',
  },
];

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function summarizeCategories(records) {
  const counts = new Map();
  records.forEach((record) => {
    const category = String(record.cat || record.category || record.Category || 'Uncategorized').trim() || 'Uncategorized';
    counts.set(category, (counts.get(category) || 0) + 1);
  });

  return Array.from(counts.entries())
    .sort((a, b) => b[1] - a[1])
    .slice(0, 6);
}

function normalizeRows(payload, sourceKey) {
  if (sourceKey === 'cocktail') {
    return Array.isArray(payload.data) ? payload.data : [];
  }
  return Array.isArray(payload.items) ? payload.items : [];
}

function deriveHeaders(records) {
  if (!records.length || typeof records[0] !== 'object' || records[0] === null) {
    return [];
  }

  return Object.keys(records[0]).slice(0, 6);
}

function buildPreviewTable(headers, records) {
  const previewRows = records.slice(0, 8);

  if (!headers.length) {
    return '<div class="admin-menu-empty">No columns found in MySQL payload.</div>';
  }

  const head = headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('');
  const body = previewRows.map((record) => {
    const cells = headers.map((header) => `<td>${escapeHtml(record[header])}</td>`).join('');
    return `<tr>${cells}</tr>`;
  }).join('');

  return `
    <div class="admin-menu-table-wrap">
      <table class="admin-menu-table">
        <thead><tr>${head}</tr></thead>
        <tbody>${body}</tbody>
      </table>
    </div>
  `;
}

function buildLoadingMarkup() {
  return `
    <section class="admin-menu-workspace">
      <div class="admin-menu-workspace__header">
        <div>
          <p class="admin-card-label">Menu Workspace</p>
          <h3>Loading MySQL-backed menus...</h3>
          <p>Reading current menu records from the production menu API.</p>
        </div>
      </div>
    </section>
  `;
}

function buildWorkspaceMarkup(states) {
  const cards = states.map((state) => {
    const { source, status, error, records, itemCount, importStatus, sourceName } = state;
    const categorySummary = summarizeCategories(records);
    const headers = deriveHeaders(records);

    const metrics = status === 'ready'
      ? `
        <div class="admin-menu-metrics">
          <div><strong>${itemCount}</strong><span>rows</span></div>
          <div><strong>${headers.length}</strong><span>columns</span></div>
          <div><strong>${categorySummary.length}</strong><span>top categories</span></div>
        </div>
      `
      : '';

    const categoriesMarkup = categorySummary.length
      ? `<div class="admin-menu-chips">${categorySummary.map(([name, count]) => `<span>${escapeHtml(name)} · ${count}</span>`).join('')}</div>`
      : '';

    const body = status === 'ready'
      ? `${metrics}${categoriesMarkup}${buildPreviewTable(headers, records)}`
      : `<div class="admin-menu-error">${escapeHtml(error || 'Unable to load MySQL data.')}</div>`;

    return `
      <article class="admin-menu-card">
        <div class="admin-menu-card__top">
          <div>
            <p class="admin-card-label">${escapeHtml(source.title)}</p>
            <h4>${escapeHtml(source.description)}</h4>
            <div class="admin-menu-subtle">${escapeHtml(sourceName || source.key)} · Last import: ${escapeHtml(importStatus || 'unknown')}</div>
          </div>
          <div class="admin-menu-actions">
            <a class="admin-menu-link" href="${escapeHtml(source.liveUrl)}" target="_blank" rel="noopener noreferrer">Open live menu</a>
          </div>
        </div>
        ${body}
      </article>
    `;
  }).join('');

  return `
    <section class="admin-menu-workspace">
      <div class="admin-menu-workspace__header">
        <div>
          <p class="admin-card-label">Menu Workspace</p>
          <h3>MySQL-backed integrated menus</h3>
          <p>Only 3 menus are integrated in AWG admin: AWG Main, Namaste Menu, and Cocktail.</p>
        </div>
        <button class="admin-menu-refresh" type="button" data-menu-refresh="1">Refresh data</button>
      </div>
      <div class="admin-menu-grid">${cards}</div>
    </section>
  `;
}

async function fetchMenuPayload(source) {
  const url = source.action === 'menu_public_cocktail'
    ? `/?action=menu_public_cocktail&_ts=${Date.now()}`
    : `/?action=menu_public_items&source=${encodeURIComponent(source.key)}&_ts=${Date.now()}`;

  const response = await fetch(url, { cache: 'no-store' });
  if (!response.ok) {
    throw new Error(`Menu API fetch failed with HTTP ${response.status}.`);
  }

  const payload = await response.json();
  if (!payload || payload.ok !== true) {
    throw new Error(payload && payload.message ? payload.message : 'Menu API returned invalid payload.');
  }

  const records = normalizeRows(payload, source.key);
  const itemCount = Number(payload.itemCount || records.length || 0);
  const importStatus = payload.lastImport && payload.lastImport.status ? payload.lastImport.status : '';
  const sourceName = payload.sourceName || payload.sourceKey || source.key;

  return { records, itemCount, importStatus, sourceName };
}

export async function renderMenuWorkspace(container) {
  if (!container) {
    return;
  }

  container.innerHTML = buildLoadingMarkup();

  try {
    const sourcesPayload = await adminApi('menu_admin_sources');
    const allowed = new Set(INTEGRATED_MENUS.map((item) => item.key));
    const liveSources = Array.isArray(sourcesPayload.sources)
      ? sourcesPayload.sources.filter((source) => allowed.has(String(source.sourceKey || '').trim()))
      : [];

    const sourceByKey = new Map(liveSources.map((source) => [String(source.sourceKey), source]));

    const states = await Promise.all(INTEGRATED_MENUS.map(async (source) => {
      try {
        const payload = await fetchMenuPayload(source);
        const sourceMeta = sourceByKey.get(source.key) || {};

        return {
          source,
          status: 'ready',
          records: payload.records,
          itemCount: payload.itemCount,
          importStatus: payload.importStatus || (sourceMeta.lastImport && sourceMeta.lastImport.status) || '',
          sourceName: payload.sourceName,
          error: '',
        };
      } catch (error) {
        return {
          source,
          status: 'error',
          records: [],
          itemCount: 0,
          importStatus: '',
          sourceName: source.key,
          error: error instanceof Error ? error.message : 'Unknown loading failure.',
        };
      }
    }));

    container.innerHTML = buildWorkspaceMarkup(states);

    const refreshButton = container.querySelector('[data-menu-refresh="1"]');
    if (refreshButton) {
      refreshButton.addEventListener('click', () => {
        renderMenuWorkspace(container).catch(() => {});
      }, { once: true });
    }
  } catch (error) {
    container.innerHTML = `
      <section class="admin-menu-workspace">
        <div class="admin-menu-error">${escapeHtml(error instanceof Error ? error.message : 'Unable to load menu workspace.')}</div>
      </section>
    `;
  }
}
