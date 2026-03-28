/**
 * menu-data.js
 * ============
 * Google Sheets fetch, parse, and cache layer for the menu page.
 * No DOM manipulation — pure data logic only.
 * Exposes: MenuData namespace with init(), MENU_DB, and column/price helpers.
 */

'use strict';

// ---------------------------------------------------------------------------
// Sheet configuration
// ---------------------------------------------------------------------------
const SHEET_SOURCE_URL = 'https://docs.google.com/spreadsheets/d/19hUSc2ny1NGd73WDTQfosdS3O7xhwiQbdGbiDgKSQlA/edit?usp=sharing';
const SHEET_ID = (() => {
  const m = SHEET_SOURCE_URL.match(/\/d\/([^/]+)/i);
  return m ? m[1] : '19hUSc2ny1NGd73WDTQfosdS3O7xhwiQbdGbiDgKSQlA';
})();
const API_URL = `https://docs.google.com/spreadsheets/d/${SHEET_ID}/gviz/tq?tqx=out:json;reqId:1&tq=select%20*&headers=1`;
const MENU_CACHE_KEY = `menu-cache:menu:${SHEET_ID}:v4`;

function isMobileCacheDisabled() {
  if (typeof window === 'undefined') return false;
  if (!window.matchMedia) return false;
  return window.matchMedia('(max-width: 768px)').matches;
}

async function cleanupMenuClientCache() {
  try {
    localStorage.removeItem(MENU_CACHE_KEY);
  } catch (_) {}

  try {
    if ('caches' in window) {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((key) => key.indexOf('menu-cache-') === 0)
          .map((key) => caches.delete(key))
      );
    }
  } catch (_) {}

  try {
    if ('serviceWorker' in navigator) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(
        regs
          .filter((reg) => {
            const scriptUrl = reg.active && reg.active.scriptURL ? reg.active.scriptURL : '';
            return scriptUrl.includes('menu-cache-sw.js');
          })
          .map((reg) => reg.unregister())
      );
    }
  } catch (_) {}
}

// Known column groups — treated as static, not dynamic
const PROTEIN_COLUMNS  = ["Veg", "Chicken", "Prawn", "Mutton", "Fish", "Surmai", "Pomfret", "Crab", "Egg"];
const VEG_XPCS_COLS    = ["Veg 2Pcs", "Veg 4pcs", "Veg 6pcs", "Veg 9pcs", "Veg 12pcs"];
const SERVING_COLUMNS  = [
  "Unit (Pcs)", ...VEG_XPCS_COLS,
  "Chicken 2pcs","Chicken 4pcs","Chicken 6pcs","Chicken 9pcs","Chicken 12pcs",
  "Prawns 2pcs","Prawns 4pcs","Prawns 6pcs","Prawns 9pcs","Prawns 12pcs",
  "Half","Full","Plain","Butter","Medium","Large"
];
const KNOWN_STATIC = [
  "Item Name","Description","Category","Image URL","Jain",
  "Chef Special","Chef's Special","Spice Level","Unit (Pcs)"
];
const REQUIRED_HEADERS = ["Item Name","Description","Category","Image URL"];
const DEFAULT_NON_VEG  = ['chicken','mutton','prawn','prawns','fish','egg','eggs','surmai','pomfret','crab','meat','shrimp'];

// ---------------------------------------------------------------------------
// Service worker registration (data-side concern: knows API_URL)
// ---------------------------------------------------------------------------
function registerMenuCacheWorker() {
  if (isMobileCacheDisabled()) {
    cleanupMenuClientCache().catch(() => {});
    return;
  }
  if (!('serviceWorker' in navigator)) return;
  if (!window.isSecureContext) return;
  if (location.protocol !== 'https:' && location.hostname !== 'localhost') return;

  const candidates = [
    new URL('menu-cache-sw.js', location.href).pathname,
    '/menu-cache-sw.js'
  ];

  (async () => {
    for (const scriptPath of candidates) {
      try {
        const response = await fetch(scriptPath, { method: 'HEAD', cache: 'no-store' });
        if (!response.ok) continue;
        await navigator.serviceWorker.register(scriptPath);
        return;
      } catch (_) {}
    }
    console.warn('[MenuData] Service worker not registered: script not found.');
  })();
}

function warmMenuAssets(items) {
  if (isMobileCacheDisabled()) return;
  if (!('serviceWorker' in navigator)) return;
  const imageUrls = [...new Set((items || []).map(i => i && i.img).filter(Boolean))];
  const urls = [API_URL, ...imageUrls];
  navigator.serviceWorker.ready.then((reg) => {
    const target = reg.active || reg.waiting || reg.installing;
    if (target) target.postMessage({ type: 'WARM_CACHE', urls });
  }).catch(() => {});
}

// ---------------------------------------------------------------------------
// Network / cache fetch
// ---------------------------------------------------------------------------
async function loadMenuDataWithCache() {
  if (isMobileCacheDisabled()) {
    cleanupMenuClientCache().catch(() => {});

    const res = await fetch(`${API_URL}&_ts=${Date.now()}`, { cache: 'no-store' });
    if (!res.ok) throw new Error(`Sheet fetch failed: HTTP ${res.status}`);
    const text = await res.text();
    const json = JSON.parse(text.substring(text.indexOf('{'), text.lastIndexOf('}') + 1));
    const rows = json.table.rows || [];
    const cols = json.table.cols || [];
    if (!cols.length) throw new Error('Sheet returned no columns');
    return { rows, cols, fromCache: false };
  }

  try {
    const res = await fetch(`${API_URL}&_ts=${Date.now()}`, { cache: 'no-store' });
    if (!res.ok) throw new Error(`Sheet fetch failed: HTTP ${res.status}`);
    const text = await res.text();
    const json = JSON.parse(text.substring(text.indexOf('{'), text.lastIndexOf('}') + 1));
    const rows = json.table.rows || [];
    const cols = json.table.cols || [];
    if (!cols.length) throw new Error('Sheet returned no columns');

    try {
      localStorage.setItem(MENU_CACHE_KEY, JSON.stringify({ rows, cols, ts: Date.now() }));
    } catch (_) {}

    return { rows, cols, fromCache: false };
  } catch (networkErr) {
    try {
      const cached = localStorage.getItem(MENU_CACHE_KEY);
      if (cached) {
        const parsed = JSON.parse(cached);
        if (parsed && Array.isArray(parsed.rows) && Array.isArray(parsed.cols) && parsed.cols.length) {
          console.warn('[MenuData] Using cached feed — live fetch failed.', networkErr);
          return { rows: parsed.rows, cols: parsed.cols, fromCache: true };
        }
      }
    } catch (_) {}
    throw networkErr;
  }
}

// ---------------------------------------------------------------------------
// Header resolution helpers
// ---------------------------------------------------------------------------
function normalizeHeader(name) {
  return String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function buildHeaderMapFromCols(columns) {
  const headerList = (columns || []).map((c, idx) => {
    const label = c && c.label ? String(c.label).trim() : '';
    if (label) return label;
    return c && c.id ? `Col_${c.id}` : `Col_${idx + 1}`;
  });
  const map = {};
  headerList.forEach((name, idx) => { map[name] = idx; });
  return { map, headerList };
}

function buildHeaderMapFromFirstRow(firstRow, columnCount) {
  const headerList = Array.from({ length: columnCount }, (_, idx) => {
    const cell = firstRow && firstRow.c && firstRow.c[idx] && firstRow.c[idx].v;
    const text = cell == null ? '' : String(cell).trim();
    return text || `Col_${idx + 1}`;
  });
  const map = {};
  headerList.forEach((name, idx) => { map[name] = idx; });
  return { map, headerList };
}

function getColumnIndex(columnMap, name) {
  if (columnMap[name] !== undefined) return columnMap[name];
  const wanted = normalizeHeader(name);
  const matched = Object.keys(columnMap).find(k => normalizeHeader(k) === wanted);
  return matched ? columnMap[matched] : undefined;
}

// ---------------------------------------------------------------------------
// Cell value helpers
// ---------------------------------------------------------------------------
function getCellValue(cells, index) {
  if (index === undefined || index === null) return null;
  if (!cells || !cells[index]) return null;
  const cell = cells[index];
  if (cell.v !== undefined && cell.v !== null) return cell.v;
  if (cell.f !== undefined && cell.f !== null) return cell.f;
  return null;
}

// ---------------------------------------------------------------------------
// Price helpers
// ---------------------------------------------------------------------------
function parsePriceValue(value) {
  if (value === null || value === undefined) return null;
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  const cleaned = String(value).replace(/[,\s₹]/g, '').trim();
  if (!cleaned) return null;
  if (!/^\d+(\.\d+)?$/.test(cleaned)) return null;
  const num = Number(cleaned);
  return Number.isFinite(num) ? num : null;
}

function isPriceLike(value) {
  return parsePriceValue(value) !== null;
}

function formatPrice(value) {
  const num = parsePriceValue(value);
  if (num === null) return String(value || '');
  return Number.isInteger(num) ? String(num) : num.toFixed(2);
}

// ---------------------------------------------------------------------------
// Field value helpers
// ---------------------------------------------------------------------------
function normalizeToken(value) {
  return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function isChefSpecialValue(value) {
  // Master truth from sheet: only "Yes" marks chef special.
  return normalizeToken(value) === 'yes';
}

function getSpiceSymbol(value) {
  const token = normalizeToken(value);
  if (!token) return '';

  // Exact sheet values (master of truth): "No Spicy", "Medium Spicy", "Spicy"
  if (token === 'no spicy')      return '🌿';   // No Spicy → leaf
  if (token === 'medium spicy')  return '';      // Medium Spicy → no symbol
  if (token === 'spicy')         return '🌶️';  // Spicy → 1 chili

  // Numeric fallback (in case sheet ever uses numbers 0–5)
  const numeric = Number(token.replace(/[^0-9.]/g, ''));
  if (Number.isFinite(numeric) && numeric >= 0 && numeric <= 5) {
    if (numeric <= 1) return '🌿';
    if (numeric <= 3) return '';
    return '🌶️';
  }

  return '';
}

// ---------------------------------------------------------------------------
// Row parsing — builds MENU_DB from raw gviz rows
// ---------------------------------------------------------------------------
function parseMenuRows(dataRows, map, headerList, cols) {
  // Resolve static column indexes
  const idxItemName        = getColumnIndex(map, 'Item Name');
  const idxDescription     = getColumnIndex(map, 'Description');
  const idxCategory        = getColumnIndex(map, 'Category');
  const idxImageUrl        = getColumnIndex(map, 'Image URL');
  const idxJain            = getColumnIndex(map, 'Jain');
  const idxUnitPcs         = getColumnIndex(map, 'Unit (Pcs)');
  const idxSpiceLevel      = getColumnIndex(map, 'Spice Level');
  const idxChefSpecial     = getColumnIndex(map, 'Chef Special');
  const idxChefSpecialAlt  = getColumnIndex(map, "Chef's Special");
  const idxChefSpecialGeneric = (() => {
    const hdr = (headerList || []).find(h => {
      const n = normalizeHeader(h);
      return n.includes('chef') && n.includes('special');
    });
    return hdr ? getColumnIndex(map, hdr) : undefined;
  })();

  // Build NonVeg keywords list (runtime override from sheet if present)
  const nonVegWords = [...DEFAULT_NON_VEG];
  if (getColumnIndex(map, 'NonVeg Keywords') !== undefined) {
    try {
      const nkIdx = getColumnIndex(map, 'NonVeg Keywords');
      const firstNonEmpty = dataRows
        .map(r => (r.c && r.c[nkIdx] && r.c[nkIdx].v) || '')
        .find(v => v && String(v).trim());
      if (firstNonEmpty) {
        const tokens = String(firstNonEmpty).split(/[,;]+/).map(s => s.trim().toLowerCase()).filter(Boolean);
        if (tokens.length) nonVegWords.splice(0, nonVegWords.length, ...tokens);
      }
    } catch (e) {
      console.warn('[MenuData] Failed to read NonVeg Keywords column', e);
    }
  }
  window.NON_VEG_WORDS = nonVegWords;

  // Detect dynamic (non-static, non-protein, non-serving) columns
  const proteinRegex = /\b(veg|chicken|prawn|mutton|fish|surmai|pomfret|crab|egg)\b/i;
  const servingRegex = /\b(pcs|pc|piece|half|full|plain|butter|medium|large)\b/i;

  function sampleColumnRawValues(colName, sourceRows, columnMap) {
    const idx = getColumnIndex(columnMap, colName);
    if (idx === undefined) return [];
    return (sourceRows || [])
      .slice(0, 30)
      .map(r => (r.c && r.c[idx] ? (r.c[idx].v ?? r.c[idx].f ?? null) : null))
      .filter(v => v !== null && v !== undefined && String(v).trim() !== '');
  }

  function sampleColumnValues(colName, rows, columnMap) {
    const idx = getColumnIndex(columnMap, colName);
    if (idx === undefined) return '';
    return rows.slice(0, 6).map(r => (r.c && r.c[idx] && r.c[idx].v) || '').join(' ');
  }

  const dynamicColumns = (headerList || [])
    .filter(h => !KNOWN_STATIC.includes(h) && !SERVING_COLUMNS.includes(h) && !PROTEIN_COLUMNS.includes(h))
    .map(h => {
      const sample = sampleColumnValues(h, dataRows, map).toString();
      const rawSamples = sampleColumnRawValues(h, dataRows, map);
      const hasPriceSample = rawSamples.some(isPriceLike);
      const hasNumber = /[0-9]/.test(sample);
      let type = 'meta';
      if (proteinRegex.test(h)) type = 'protein';
      else if (servingRegex.test(h)) type = 'serving';
      else if (hasPriceSample || hasNumber) type = 'price';
      return { key: h, label: h, type };
    });

  const chefIdx = idxChefSpecial !== undefined
    ? idxChefSpecial
    : (idxChefSpecialAlt !== undefined ? idxChefSpecialAlt : idxChefSpecialGeneric);

  return dataRows.map((r, idx) => {
    const d = r.c;
    const rawName = getCellValue(d, idxItemName);
    if (!rawName || !String(rawName).trim()) return null;

    const itemName = String(rawName);
    const itemDesc = String(getCellValue(d, idxDescription) || '');
    const fullText = (itemName + ' ' + itemDesc).toLowerCase();

    // Specific overrides for items that look non-veg by name but are veg
    const isSpecificVeg = fullText.includes('veg delight') || fullText.includes('mushroom veg');
    const nonVegInText = nonVegWords.some(w => fullText.includes(w));
    const nonVegFromProtein = PROTEIN_COLUMNS.some(pCol => {
      const idxp = getColumnIndex(map, pCol);
      if (idxp === undefined) return false;
      const val = String(getCellValue(d, idxp) || '').toLowerCase();
      return nonVegWords.some(k => val.includes(k));
    });
    const nonVegFromDynamic = dynamicColumns.some(dc => {
      if (dc.type !== 'protein') return false;
      const idxp = getColumnIndex(map, dc.key);
      if (idxp === undefined) return false;
      const val = String(getCellValue(d, idxp) || '').toLowerCase();
      return nonVegWords.some(k => val.includes(k));
    });
    const isNonVegItem = !isSpecificVeg && (nonVegInText || nonVegFromProtein || nonVegFromDynamic);

    const proteins = {};
    PROTEIN_COLUMNS.forEach(pCol => {
      const idxp = getColumnIndex(map, pCol);
      const value = getCellValue(d, idxp);
      if (idxp !== undefined && value !== null && value !== '') proteins[pCol] = value;
    });

    const servings = {};
    SERVING_COLUMNS.forEach(sCol => {
      const idxs = getColumnIndex(map, sCol);
      const value = getCellValue(d, idxs);
      if (idxs !== undefined && value !== null && value !== '') servings[sCol] = value;
    });

    let dynamic = {};
    dynamicColumns.forEach(dc => {
      const dIdx = getColumnIndex(map, dc.key);
      const val = getCellValue(d, dIdx);
      if (!val) return;
      if (dc.type === 'protein') proteins[dc.key] = val;
      else if (dc.type === 'serving' || dc.type === 'price') servings[dc.key] = val;
      else dynamic[dc.key] = val;
    });

    // Only keep dynamic entries that have a valid price for this row
    const dynamicPriced = {};
    Object.entries(dynamic).forEach(([k, v]) => {
      if (isPriceLike(v)) dynamicPriced[k] = v;
    });

    const jVal   = getCellValue(d, idxJain);
    const chefRaw = getCellValue(d, chefIdx);

    return {
      id: idx,
      cat: String(getCellValue(d, idxCategory) || 'Other'),
      name: itemName,
      desc: itemDesc,
      proteins,
      servings,
      dynamic: dynamicPriced,
      servingUnit: getCellValue(d, idxUnitPcs),
      chef: isChefSpecialValue(chefRaw),
      spice: String(getCellValue(d, idxSpiceLevel) || ''),
      jainPrice: jVal,
      img: String(getCellValue(d, idxImageUrl) || 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400'),
      isVeg: !isNonVegItem,
      rowDiet: isNonVegItem ? 'nonveg' : 'veg',
      descNonVeg: nonVegInText
    };
  }).filter(x => x);
}

// ---------------------------------------------------------------------------
// Schema validation
// ---------------------------------------------------------------------------
function validateHeaders(map, headerList, rows, cols) {
  let dataRows = rows;
  let currentMap = map;
  let currentHeaderList = headerList;

  let missing = REQUIRED_HEADERS.filter(h => getColumnIndex(currentMap, h) === undefined);

  if (missing.length && rows.length) {
    const fromFirstRow = buildHeaderMapFromFirstRow(rows[0], cols.length);
    const missingFromFirstRow = REQUIRED_HEADERS.filter(h => getColumnIndex(fromFirstRow.map, h) === undefined);
    if (missingFromFirstRow.length < missing.length) {
      currentMap = fromFirstRow.map;
      currentHeaderList = fromFirstRow.headerList;
      dataRows = rows.slice(1);
      missing = missingFromFirstRow;
    }
  }

  return { valid: missing.length === 0, missing, dataRows, map: currentMap, headerList: currentHeaderList };
}

// ---------------------------------------------------------------------------
// Public init — fetches, validates, parses, returns MENU_DB array
// ---------------------------------------------------------------------------
async function loadAndParseMenu() {
  const { rows, cols, fromCache } = await loadMenuDataWithCache();

  const { map, headerList } = buildHeaderMapFromCols(cols);
  const validation = validateHeaders(map, headerList, rows, cols);

  if (!validation.valid) {
    throw Object.assign(
      new Error('Menu sheet schema validation failed: missing required columns: ' + validation.missing.join(', ')),
      { type: 'SCHEMA_ERROR', missing: validation.missing }
    );
  }

  const items = parseMenuRows(validation.dataRows, validation.map, validation.headerList, cols);

  if (!items.length) {
    throw Object.assign(
      new Error('No menu rows were parsed from Google Sheet. Verify required columns and row values.'),
      { type: 'EMPTY_ERROR' }
    );
  }

  return { items, fromCache };
}

// Expose constants needed by UI layer
window.MenuData = {
  SHEET_ID,
  API_URL,
  PROTEIN_COLUMNS,
  VEG_XPCS_COLS,
  SERVING_COLUMNS,
  loadAndParseMenu,
  warmMenuAssets,
  registerMenuCacheWorker,
  // Value helpers used by UI for display
  formatPrice,
  isPriceLike,
  getSpiceSymbol,
  isChefSpecialValue,
  normalizeToken,
};
