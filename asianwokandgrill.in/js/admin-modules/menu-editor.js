import { adminApi, adminMultipart } from './api-client.js';

const MENU_OPTIONS = [
  { value: 'menu_a', label: 'Menu A — AWG Main Food (menu.html)' },
  { value: 'menu_b', label: 'Menu B — Cocktail & Bar (cocktail.html)' },
  { value: 'menu_c', label: 'Menu C — Namaste Menu (namastemenu.html)' },
];

const PRICE_FIELDS = [
  'priceVeg', 'priceJain', 'priceChicken', 'priceMutton', 'priceBasa', 'pricePrawns',
  'priceSurmai', 'pricePomfret', 'priceCrab', 'priceEgg', 'priceHalf', 'priceFull',
  'pricePlain', 'priceButter', 'priceMedium', 'priceLarge', 'priceDirect',
];

// ─── Per-menu column definitions (mirrors actual Google Sheet column order) ────

// ── Sticky column offsets (px) ──────────────────────────────────────────────
// Select checkbox col is always 40px wide and sticky at left:0.
// Category is sticky at left:40, Item Name at left:160 (40+120).
const STICKY_OFFSET = { category: 40, itemName: 160 };

// ── menu_a — AWG Main Food Menu ──────────────────────────────────────────────
// Google Sheet MENU_A cols (A→AE):
// Category | Item Name | Description | Veg | Jain | Chicken | Prawn | Fish |
// Surmai | Pomfret | Crab | Egg | Spice Level | Chef Special | Unit(Pcs) |
// Veg 2/4/6/9/12Pcs | Chicken 2/4/6/9/12pcs | Prawns 2/4/6/9/12pcs | Image URL
const COLUMNS_MENU_A = [
  { key: 'category',      label: 'Category',    type: 'text',     width: '120px', sticky: true  },
  { key: 'itemName',      label: 'Item Name',   type: 'text',     width: '160px', sticky: true  },
  { key: 'description',   label: 'Description', type: 'text',     width: '200px' },
  { key: 'priceVeg',      label: 'Veg ₹',       type: 'number',   width: '65px'  },
  { key: 'priceJain',     label: 'Jain ₹',      type: 'number',   width: '65px'  },
  { key: 'priceChicken',  label: 'Chicken ₹',   type: 'number',   width: '72px'  },
  { key: 'pricePrawns',   label: 'Prawn ₹',     type: 'number',   width: '68px'  },
  { key: 'priceBasa',     label: 'Fish ₹',      type: 'number',   width: '62px'  },
  { key: 'priceSurmai',   label: 'Surmai ₹',    type: 'number',   width: '68px'  },
  { key: 'pricePomfret',  label: 'Pomfret ₹',   type: 'number',   width: '72px'  },
  { key: 'priceCrab',     label: 'Crab ₹',      type: 'number',   width: '65px'  },
  { key: 'priceEgg',      label: 'Egg ₹',       type: 'number',   width: '60px'  },
  { key: 'spiceLevel',    label: 'Spice Level', type: 'spice',    width: '100px' },
  { key: 'isChefSpecial', label: 'Chef Special',type: 'yesno',    width: '96px'  },
  { key: 'imageUrl',      label: 'Image URL',   type: 'text',     width: '180px' },
];

// Pcs-based variant columns (Google Sheet cols P–AD for menu_a)
const DEFAULT_VARIANTS_MENU_A = [
  'Veg 2Pcs', 'Veg 4Pcs', 'Veg 6Pcs', 'Veg 9Pcs', 'Veg 12Pcs',
  'Chicken 2Pcs', 'Chicken 4Pcs', 'Chicken 6Pcs', 'Chicken 9Pcs', 'Chicken 12Pcs',
  'Prawns 2Pcs', 'Prawns 4Pcs', 'Prawns 6Pcs', 'Prawns 9Pcs', 'Prawns 12Pcs',
];

// ── menu_b — Cocktail & Bar (cocktail.html) ──────────────────────────────────
// Simplified: only essential cocktail columns (NO image, NO spice level, NO diet variants)
const COLUMNS_MENU_B = [
  { key: 'category',      label: 'Category',    type: 'text',     width: '120px', sticky: true  },
  { key: 'itemName',      label: 'Item Name',   type: 'text',     width: '160px', sticky: true  },
  { key: 'description',   label: 'Description', type: 'text',     width: '200px' },
];

const DEFAULT_VARIANTS_MENU_B = ['Glass', 'Pitcher', '30ml', 'Pint', 'Per Bottle'];

// ── menu_c — Namaste Menu (namastemenu.html) ─────────────────────────────────
// Google Sheet MENU_C cols (A→V):
// Category | Item Name | Description | Veg | Jain | Chicken | Prawn | Mutton |
// Fish | Surmai | Pomfret | Crab | Egg | Spice Level | Chef's Special |
// Half | Full | Plain | Butter | Medium | Large | Image URL
const COLUMNS_MENU_C = [
  { key: 'category',      label: 'Category',    type: 'text',     width: '120px', sticky: true  },
  { key: 'itemName',      label: 'Item Name',   type: 'text',     width: '160px', sticky: true  },
  { key: 'description',   label: 'Description', type: 'text',     width: '200px' },
  { key: 'priceVeg',      label: 'Veg ₹',       type: 'number',   width: '65px'  },
  { key: 'priceJain',     label: 'Jain ₹',      type: 'number',   width: '65px'  },
  { key: 'priceChicken',  label: 'Chicken ₹',   type: 'number',   width: '72px'  },
  { key: 'pricePrawns',   label: 'Prawn ₹',     type: 'number',   width: '68px'  },
  { key: 'priceMutton',   label: 'Mutton ₹',    type: 'number',   width: '68px'  },
  { key: 'priceBasa',     label: 'Fish ₹',      type: 'number',   width: '62px'  },
  { key: 'priceSurmai',   label: 'Surmai ₹',    type: 'number',   width: '68px'  },
  { key: 'pricePomfret',  label: 'Pomfret ₹',   type: 'number',   width: '72px'  },
  { key: 'priceCrab',     label: 'Crab ₹',      type: 'number',   width: '65px'  },
  { key: 'priceEgg',      label: 'Egg ₹',       type: 'number',   width: '60px'  },
  { key: 'priceHalf',     label: 'Half ₹',      type: 'number',   width: '62px'  },
  { key: 'priceFull',     label: 'Full ₹',      type: 'number',   width: '62px'  },
  { key: 'pricePlain',    label: 'Plain ₹',     type: 'number',   width: '62px'  },
  { key: 'priceButter',   label: 'Butter ₹',    type: 'number',   width: '65px'  },
  { key: 'priceMedium',   label: 'Medium ₹',    type: 'number',   width: '68px'  },
  { key: 'priceLarge',    label: 'Large ₹',     type: 'number',   width: '65px'  },
  { key: 'spiceLevel',    label: 'Spice Level', type: 'spice',    width: '100px' },
  { key: 'isChefSpecial', label: 'Chef Special',type: 'yesno',    width: '96px'  },
  { key: 'imageUrl',      label: 'Image URL',   type: 'text',     width: '180px' },
];
const DEFAULT_VARIANTS_MENU_C = [];

const MENU_COLUMNS = {
  menu_a: COLUMNS_MENU_A,
  menu_b: COLUMNS_MENU_B,
  menu_c: COLUMNS_MENU_C,
};

const MENU_DEFAULT_VARIANTS = {
  menu_a: DEFAULT_VARIANTS_MENU_A,
  menu_b: DEFAULT_VARIANTS_MENU_B,
  menu_c: DEFAULT_VARIANTS_MENU_C,
};

function getColumnsForMenu(menuType) {
  return MENU_COLUMNS[menuType] || COLUMNS_MENU_A;
}

function getDefaultVariantsForMenu(menuType) {
  return MENU_DEFAULT_VARIANTS[menuType] || [];
}

export async function renderMenuEditor(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Menu Bulk Editor</h3>
      <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:12px;">
        <label>
          Menu
          <select id="meMenuType">${MENU_OPTIONS.map((opt) => `<option value="${opt.value}">${opt.label}</option>`).join('')}</select>
        </label>
        <label>
          Category
          <select id="meCategory"><option value="">All categories</option></select>
        </label>
        <label>
          Search
          <input id="meSearch" type="text" placeholder="Search by item/category" />
        </label>
        <label>
          Visible Rows
          <select id="meLimit">
            <option value="30">30</option>
            <option value="60" selected>60</option>
            <option value="120">120</option>
            <option value="200">200</option>
          </select>
        </label>
      </div>
      <div class="admin-form-actions" style="margin-bottom:12px;">
        <button id="meRefresh" class="admin-button admin-button-secondary" type="button">Refresh</button>
        <button id="meAddRow" class="admin-button admin-button-secondary" type="button">Add Row</button>
        <button id="meDeleteSelected" class="admin-button admin-button-secondary" type="button">Delete Selected</button>
        <button id="meSetVisible" class="admin-button admin-button-secondary" type="button">Set Visible</button>
        <button id="meSetHidden" class="admin-button admin-button-secondary" type="button">Set Hidden</button>
        <input id="meNewVariant" class="admin-input" type="text" placeholder="New variant column (e.g. Jumbo)" style="max-width:220px;" />
        <button id="meAddVariantCol" class="admin-button admin-button-secondary" type="button">Add Variant Column</button>
        <button id="meSave" class="admin-button" type="button">Save Changes</button>
      </div>
      <div id="meStatus" class="admin-form-status">Loading editor...</div>
      <div id="meTable"></div>
    </section>
  `;

  const menuTypeNode = container.querySelector('#meMenuType');
  const categoryNode = container.querySelector('#meCategory');
  const searchNode = container.querySelector('#meSearch');
  const limitNode = container.querySelector('#meLimit');
  const newVariantNode = container.querySelector('#meNewVariant');
  const statusNode = container.querySelector('#meStatus');
  const tableNode = container.querySelector('#meTable');

  let rows = [];
  let dirty = new Map();
  let variantColumns = [];
  let currentPage = 1;
  let currentColumns = getColumnsForMenu('menu_a');

  function effectiveImageSource(row) {
    if (row && row.hasUploadedImage) {
      return 'uploaded';
    }
    return String(row && row.imageUrl || '').trim() ? 'url' : '';
  }

  function effectiveImagePreviewUrl(row) {
    const uploaded = String(row && row.uploadedImageDataUri || '').trim();
    if (uploaded) {
      return uploaded;
    }

    const manual = String(row && row.imageUrl || '').trim();
    return manual || '';
  }

  function updateRowPreviewDom(row) {
    const rowId = Number(row && row.id);
    if (!Number.isFinite(rowId) || rowId <= 0) {
      return;
    }

    const rowNode = tableNode.querySelector(`tr[data-me-id="${rowId}"]`);
    if (!rowNode) {
      return;
    }

    const manualPreview = rowNode.querySelector('[data-role="manual-preview"]');
    if (manualPreview) {
      const manual = String(row.imageUrl || '').trim();
      manualPreview.src = manual || '';
      manualPreview.style.display = manual ? 'block' : 'none';
    }

    const uploadedPreview = rowNode.querySelector('[data-role="uploaded-preview"]');
    if (uploadedPreview) {
      const uploaded = String(row.uploadedImageDataUri || '').trim();
      uploadedPreview.src = uploaded || '';
      uploadedPreview.style.display = uploaded ? 'block' : 'none';
    }

    const effectivePreview = rowNode.querySelector('[data-role="effective-preview"]');
    if (effectivePreview) {
      const effective = effectiveImagePreviewUrl(row);
      effectivePreview.src = effective || '';
      effectivePreview.style.display = effective ? 'block' : 'none';
    }

    const sourceNode = rowNode.querySelector('[data-role="effective-source"]');
    if (sourceNode) {
      const source = effectiveImageSource(row);
      sourceNode.textContent = source ? source.toUpperCase() : 'NONE';
    }
  }

  async function ensureUploadedPreview(row) {
    if (!row || !row.hasUploadedImage || row.uploadedImageDataUri || row.uploadPreviewLoading) {
      return;
    }

    row.uploadPreviewLoading = true;
    try {
      const payload = await adminApi('admin_menu_editor_image_preview', {
        query: {
          menuType: menuTypeNode.value,
          itemId: row.id,
        },
      });

      row.uploadedImageDataUri = String(payload.uploadedImageDataUri || '');
      updateRowPreviewDom(row);
    } catch (_error) {
      // Keep editing functional even if preview fetch fails.
    } finally {
      row.uploadPreviewLoading = false;
    }
  }

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  function selectedIds() {
    return Array.from(container.querySelectorAll('[data-me-select]:checked'))
      .map((node) => Number(node.getAttribute('data-me-select')))
      .filter((id) => Number.isFinite(id) && id > 0);
  }

  function filteredRows() {
    const term = String(searchNode.value || '').trim().toLowerCase();
    const selectedCategory = String(categoryNode.value || '').trim();

    return rows.filter((row) => {
      if (selectedCategory && String(row.category || '') !== selectedCategory) {
        return false;
      }
      if (!term) {
        return true;
      }
      const hay = [row.category, row.itemName, row.description].map((v) => String(v || '').toLowerCase()).join(' ');
      return hay.includes(term);
    });
  }

  function syncCategories() {
    const categories = new Set(rows.map((row) => String(row.category || '').trim()).filter(Boolean));
    categoryNode.innerHTML = '<option value="">All categories</option>' + Array.from(categories).sort((a, b) => a.localeCompare(b)).map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
  }

  function buildVariantColumns(items, menuType) {
    // Start with the default variant columns for this menu
    const defaults = getDefaultVariantsForMenu(menuType);
    const labels = new Set(defaults);
    // Also discover any variants already stored in the data
    items.forEach((row) => {
      (Array.isArray(row.variants) ? row.variants : []).forEach((variant) => {
        const label = String(variant && (variant.variantLabel || variant.label) || '').trim();
        if (label) {
          labels.add(label);
        }
      });
    });
    // Keep defaults in their defined order, then any extras alphabetically
    const defaultsOrdered = defaults.filter((d) => labels.has(d));
    const extras = Array.from(labels).filter((l) => !defaults.includes(l)).sort((a, b) => a.localeCompare(b));
    return [...defaultsOrdered, ...extras];
  }

  function getVariantPrice(row, label) {
    const variants = Array.isArray(row.variants) ? row.variants : [];
    const found = variants.find((variant) => String(variant.variantLabel || '').trim() === label);
    return found ? numberOrBlank(found.price) : '';
  }

  function setVariantPrice(row, label, value) {
    const variants = Array.isArray(row.variants) ? [...row.variants] : [];
    const text = String(value || '').trim();
    const index = variants.findIndex((variant) => String(variant.variantLabel || '').trim() === label);

    if (!text) {
      if (index >= 0) {
        variants.splice(index, 1);
      }
      row.variants = variants;
      row.pricingMode = variants.length ? 'custom_variants' : 'standard';
      return;
    }

    const parsedPrice = readNumberOrNull(text);
    if (parsedPrice === null) {
      return;
    }

    const payload = {
      variantLabel: label,
      price: parsedPrice,
      variantSortOrder: index >= 0
        ? Number(variants[index].variantSortOrder || index)
        : variants.length,
    };

    if (index >= 0) {
      variants[index] = payload;
    } else {
      variants.push(payload);
    }

    row.variants = variants;
    row.pricingMode = 'custom_variants';
  }

  function readNumberOrNull(value) {
    const text = String(value || '').trim();
    if (!text) return null;
    const n = Number(text);
    return Number.isFinite(n) ? n : null;
  }

  function applyInput(row, input) {
    const field = String(input.getAttribute('data-field') || '');
    if (!field) {
      return;
    }

    // Native checkbox (row-select only — kept as fallback)
    if (input.type === 'checkbox') {
      row[field] = Boolean(input.checked);
      return;
    }

    // Yes/No dropdown → boolean
    if (input.getAttribute('data-coltype') === 'yesno') {
      row[field] = input.value === 'yes';
      return;
    }

    // Spice level dropdown → string (falls through to String(value) below)

    if (field.startsWith('variant:')) {
      const label = decodeURIComponent(field.slice('variant:'.length));
      setVariantPrice(row, label, input.value || '');
      return;
    }

    if (PRICE_FIELDS.includes(field)) {
      row[field] = readNumberOrNull(input.value);
      return;
    }

    row[field] = String(input.value || '');
  }

  function patchDirty(id, row) {
    const current = dirty.get(id) || {};
    dirty.set(id, {
      ...current,
      id,
      category: row.category,
      itemName: row.itemName,
      description: row.description,
      imageUrl: row.imageUrl,
      isAvailable: Boolean(row.isAvailable),
      isChefSpecial: Boolean(row.isChefSpecial),
      isVeg: Boolean(row.isVeg),
      isNonveg: Boolean(row.isNonveg),
      isJain: Boolean(row.isJain),
      isUniversal: Boolean(row.isUniversal),
      spiceLevel: String(row.spiceLevel || ''),
      pricingMode: row.pricingMode || (Array.isArray(row.variants) && row.variants.length ? 'custom_variants' : 'standard'),
      variants: Array.isArray(row.variants) ? row.variants : [],
      priceVeg: row.priceVeg,
      priceJain: row.priceJain,
      priceChicken: row.priceChicken,
      priceMutton: row.priceMutton,
      priceBasa: row.priceBasa,
      pricePrawns: row.pricePrawns,
      priceSurmai: row.priceSurmai,
      pricePomfret: row.pricePomfret,
      priceCrab: row.priceCrab,
      priceEgg: row.priceEgg,
      priceHalf: row.priceHalf,
      priceFull: row.priceFull,
      pricePlain: row.pricePlain,
      priceButter: row.priceButter,
      priceMedium: row.priceMedium,
      priceLarge: row.priceLarge,
      priceDirect: row.priceDirect,
      categorySortOrder: row.categorySortOrder,
      itemSortOrder: row.itemSortOrder,
      sourceRow: row.sourceRow,
    });
  }

  function bindTable() {
    Array.from(tableNode.querySelectorAll('tr[data-me-id]')).forEach((tr) => {
      const id = Number(tr.getAttribute('data-me-id'));
      const row = rows.find((item) => Number(item.id) === id);
      if (!row) return;

      Array.from(tr.querySelectorAll('[data-field]')).forEach((input) => {
        const handler = () => {
          try {
            applyInput(row, input);
            patchDirty(id, row);
            if (String(input.getAttribute('data-field') || '') === 'imageUrl') {
              updateRowPreviewDom(row);
            }
            if (input.getAttribute('data-field') === 'variants') {
              input.classList.remove('error');
            }
            setStatus(`Edited ${dirty.size} row(s). Save to persist changes.`, false);
          } catch (_error) {
            if (input.getAttribute('data-field') === 'variants') {
              input.classList.add('error');
            }
          }
        };
        input.addEventListener('change', handler);
        input.addEventListener('input', handler);
      });

      Array.from(tr.querySelectorAll('[data-upload-image]')).forEach((button) => {
        button.addEventListener('click', async () => {
          const itemId = Number(button.getAttribute('data-upload-image'));
          const rowForUpload = rows.find((item) => Number(item.id) === itemId);
          if (!rowForUpload) {
            return;
          }

          const fileInput = tr.querySelector('[data-upload-file]');
          const file = fileInput && fileInput.files ? fileInput.files[0] : null;
          if (!file) {
            setStatus('Choose an image file before uploading.', true);
            return;
          }

          try {
            button.disabled = true;
            setStatus(`Uploading image for row #${itemId}...`, false);

            const formData = new FormData();
            formData.append('menuType', menuTypeNode.value);
            formData.append('itemId', String(itemId));
            formData.append('file', file);

            const payload = await adminMultipart('admin_menu_editor_upload_image', formData);
            rowForUpload.hasUploadedImage = true;
            rowForUpload.uploadedImageDataUri = String(payload.uploadedImageDataUri || '');
            rowForUpload.effectiveImageSource = 'uploaded';
            updateRowPreviewDom(rowForUpload);
            setStatus(`Uploaded image for row #${itemId}. Uploaded image is now the effective image.`, false);
          } catch (error) {
            setStatus(error.message || 'Image upload failed.', true);
          } finally {
            button.disabled = false;
          }
        });
      });

      ensureUploadedPreview(row);
    });
  }

  function renderTable() {
    const filtered = filteredRows();
    const limit = Math.max(1, Number(limitNode.value || 60));
    const totalPages = Math.max(1, Math.ceil(filtered.length / limit));
    currentPage = Math.min(Math.max(1, currentPage), totalPages);
    const startIndex = (currentPage - 1) * limit;
    const endIndex = startIndex + limit;
    const display = filtered.slice(startIndex, endIndex);

    if (!display.length) {
      tableNode.innerHTML = '<div class="admin-module-empty">No rows found for this filter.</div>';
      return;
    }

    // ── Spice level options & colours ───────────────────────────────────────
    const SPICE_OPTS = ['', 'Mild', 'Medium', 'Hot', 'Extra Hot'];
    const SPICE_COLOR = { '': '#aaa', Mild: '#43a047', Medium: '#e65100', Hot: '#e53935', 'Extra Hot': '#b71c1c' };

    // ── Shared style strings ─────────────────────────────────────────────────
    // TH base (all header cells are sticky-top)
    const TH = `font-size:11.5px;font-weight:700;color:#3c4043;background:#f1f3f4;`
      + `padding:0 7px;height:30px;line-height:30px;border-right:1px solid #c6c6c6;`
      + `border-bottom:2px solid #bdbdbd;white-space:nowrap;user-select:none;`
      + `text-align:left;position:sticky;top:0;z-index:5;`;
    // TH for left-sticky cols (also sticky left)
    const thSticky = (left) => `${TH}left:${left}px;z-index:6;`;
    // Input style (fills the cell)
    const INP = `display:block;width:100%;height:27px;border:none;background:transparent;`
      + `font-size:13px;font-family:Arial,sans-serif;padding:0 6px;`
      + `box-sizing:border-box;outline:none;color:#202124;`;
    const SEL = `${INP}cursor:pointer;-webkit-appearance:none;appearance:none;padding-right:4px;`;

    // ── Cell renderer ────────────────────────────────────────────────────────
    const renderCell = (col, row) => {
      const stickyStyle = col.sticky
        ? `position:sticky;left:${STICKY_OFFSET[col.key]}px;z-index:2;box-shadow:2px 0 4px rgba(0,0,0,.07);`
        : '';
      const cls = `me-gs-td${col.sticky ? ' me-gs-td-s' : ''}`;

      if (col.type === 'yesno') {
        const v = Boolean(row[col.key]);
        const color = v ? '#1e8e3e' : '#9e9e9e';
        const weight = v ? '600' : '400';
        return `<td class="${cls}" style="${stickyStyle}">`
          + `<select data-field="${col.key}" data-coltype="yesno"`
          + ` style="${SEL}color:${color};font-weight:${weight};">`
          + `<option value="yes"${v ? ' selected' : ''}>✓ Yes</option>`
          + `<option value="no"${!v ? ' selected' : ''}>✗ No</option>`
          + `</select></td>`;
      }

      if (col.type === 'spice') {
        const v = String(row[col.key] || '');
        const color = SPICE_COLOR[v] ?? '#aaa';
        return `<td class="${cls}" style="${stickyStyle}">`
          + `<select data-field="${col.key}" data-coltype="spice" style="${SEL}color:${color};">`
          + SPICE_OPTS.map((o) =>
            `<option value="${o}"${v === o ? ' selected' : ''} style="color:${SPICE_COLOR[o] ?? '#aaa'}">`
            + `${o || '— None —'}</option>`
          ).join('')
          + `</select></td>`;
      }

      if (col.type === 'number') {
        const v = numberOrBlank(row[col.key]);
        return `<td class="${cls}" style="${stickyStyle}">`
          + `<input class="me-inp" data-field="${col.key}" value="${escapeHtml(v)}"`
          + ` style="${INP}text-align:right;min-width:${col.width || '58px'};`
          + `color:${v ? '#202124' : '#c8c8c8'};" /></td>`;
      }

      if (col.key === 'imageUrl') {
        const manual = String(row.imageUrl || '');
        const uploaded = String(row.uploadedImageDataUri || '');
        const effective = effectiveImagePreviewUrl(row);
        const source = effectiveImageSource(row);

        return `<td class="${cls}" style="${stickyStyle}padding:6px;min-width:260px;">`
          + `<input class="me-inp" data-field="imageUrl" value="${escapeHtml(manual)}"`
          + ` placeholder="https://..." style="${INP}border:1px solid #d0d7de;border-radius:4px;height:28px;background:#fff;min-width:220px;" />`
          + `<div style="display:flex;gap:6px;align-items:center;margin-top:6px;flex-wrap:wrap;">`
          + `<input type="file" accept="image/*" data-upload-file style="font-size:11px;max-width:150px;" />`
          + `<button type="button" class="admin-button admin-button-secondary" data-upload-image="${Number(row.id)}" style="height:26px;padding:0 8px;font-size:11px;">Upload WEBP</button>`
          + `</div>`
          + `<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;margin-top:6px;">`
          + `<div style="font-size:10px;color:#5f6368;">URL`
          + `<img data-role="manual-preview" alt="URL preview" src="${escapeHtml(manual)}" style="display:${manual ? 'block' : 'none'};width:44px;height:44px;object-fit:cover;border:1px solid #d0d7de;border-radius:4px;margin-top:2px;" />`
          + `</div>`
          + `<div style="font-size:10px;color:#5f6368;">Uploaded`
          + `<img data-role="uploaded-preview" alt="Uploaded preview" src="${escapeHtml(uploaded)}" style="display:${uploaded ? 'block' : 'none'};width:44px;height:44px;object-fit:cover;border:1px solid #d0d7de;border-radius:4px;margin-top:2px;" />`
          + `</div>`
          + `<div style="font-size:10px;color:#5f6368;">Effective (<span data-role="effective-source">${source ? source.toUpperCase() : 'NONE'}</span>)`
          + `<img data-role="effective-preview" alt="Effective preview" src="${escapeHtml(effective)}" style="display:${effective ? 'block' : 'none'};width:44px;height:44px;object-fit:cover;border:1px solid #1a73e8;border-radius:4px;margin-top:2px;" />`
          + `</div>`
          + `</div>`
          + `</td>`;
      }

      const v = String(row[col.key] || '');
      return `<td class="${cls}" style="${stickyStyle}">`
        + `<input class="me-inp" data-field="${col.key}" value="${escapeHtml(v)}"`
        + ` style="${INP}min-width:${col.width || '80px'};" /></td>`;
    };

    // ── Header row ───────────────────────────────────────────────────────────
    const headerCells =
      currentColumns.map((col) =>
        `<th style="${col.sticky ? thSticky(STICKY_OFFSET[col.key]) : TH}min-width:${col.width || '60px'}">`
        + `${escapeHtml(col.label)}</th>`
      ).join('')
      + variantColumns.map((label) =>
        `<th style="${TH}min-width:62px">${escapeHtml(label)} ₹</th>`
      ).join('');

    // ── Build table HTML ─────────────────────────────────────────────────────
    tableNode.innerHTML = `
      <style>
        .me-gs-td {
          border-right: 1px solid #e8eaed;
          border-bottom: 1px solid #e8eaed;
          padding: 0;
          vertical-align: middle;
          background: #fff;
          transition: background 0.08s;
        }
        .me-gs-row:nth-child(even) .me-gs-td { background: #f8fbff; }
        .me-gs-row:hover .me-gs-td { background: #e8f0fe !important; }
        .me-gs-td-s { background: #fff !important; }
        .me-gs-row:nth-child(even) .me-gs-td-s { background: #f0f4ff !important; }
        .me-gs-row:hover .me-gs-td-s { background: #dce8fc !important; }
        .me-gs-td-sel {
          background: #f1f3f4 !important;
          border-right: 2px solid #c6c6c6;
        }
        .me-gs-row:hover .me-gs-td-sel { background: #e8f0fe !important; }
        .me-gs-td:focus-within { outline: 2px solid #1a73e8; outline-offset: -2px; }
        .me-inp:focus { outline: none; box-shadow: none; }
        .me-inp::placeholder { color: #c8c8c8; }
        select[data-coltype]:focus { outline: none; }
      </style>
      <div style="max-width:100%;margin-bottom:8px;font-size:12px;color:#5f6368;">
        Showing <b>${startIndex + 1}</b>-<b>${Math.min(endIndex, filtered.length)}</b> of <b>${filtered.length}</b> rows
        <span style="margin-left:8px;color:#80868b;">(Page ${currentPage} of ${totalPages})</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
        <button id="mePrevPage" class="admin-button admin-button-secondary" type="button" ${currentPage <= 1 ? 'disabled' : ''}>Previous</button>
        <button id="meNextPage" class="admin-button admin-button-secondary" type="button" ${currentPage >= totalPages ? 'disabled' : ''}>Next</button>
        <span style="font-size:12px;color:#5f6368;">Page ${currentPage} / ${totalPages}</span>
      </div>
      <div style="overflow-x:auto;overflow-y:auto;border:1px solid #d0d0d0;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.1);max-height:calc(100vh - 320px);">
        <table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;background:#fff;">
          <thead style="position:sticky;top:0;z-index:10;">
            <tr>
              <th style="${TH}position:sticky;left:0;top:0;z-index:11;width:36px;min-width:36px;text-align:center;border-right:2px solid #c6c6c6;">☑</th>
              ${headerCells}
            </tr>
          </thead>
          <tbody>
            ${display.map((row) => `
              <tr class="me-gs-row" data-me-id="${Number(row.id)}">
                <td class="me-gs-td me-gs-td-sel" style="position:sticky;left:0;z-index:2;box-shadow:2px 0 4px rgba(0,0,0,.07);text-align:center;width:36px;">
                  <input type="checkbox" data-me-select="${Number(row.id)}" style="cursor:pointer;margin:0;" />
                </td>
                ${currentColumns.map((col) => renderCell(col, row)).join('')}
                ${variantColumns.map((label) => {
                  const v = numberOrBlank(getVariantPrice(row, label));
                  return `<td class="me-gs-td"><input class="me-inp" data-field="variant:${encodeURIComponent(label)}" value="${escapeHtml(v)}" style="${INP}text-align:right;min-width:56px;color:${v ? '#202124' : '#c8c8c8'};" /></td>`;
                }).join('')}
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;

    bindTable();

    const prevButton = container.querySelector('#mePrevPage');
    const nextButton = container.querySelector('#meNextPage');
    if (prevButton) {
      prevButton.addEventListener('click', () => {
        if (currentPage > 1) {
          currentPage -= 1;
          renderTable();
        }
      });
    }
    if (nextButton) {
      nextButton.addEventListener('click', () => {
        if (currentPage < totalPages) {
          currentPage += 1;
          renderTable();
        }
      });
    }
  }

  async function loadRows() {
    const menuType = menuTypeNode.value;
    setStatus(`Loading ${menuType}...`, false);
    const payload = await adminApi('admin_menu_editor_load', {
      query: { menuType },
    });
    rows = Array.isArray(payload.items) ? payload.items : [];
    currentColumns = getColumnsForMenu(menuType);
    variantColumns = buildVariantColumns(rows, menuType);
    dirty = new Map();
    currentPage = 1;
    syncCategories();
    renderTable();
    setStatus(`Loaded ${rows.length} row(s) for ${menuType}. Columns: ${currentColumns.length} fixed + ${variantColumns.length} variant.`, false);
  }

  container.querySelector('#meRefresh').addEventListener('click', async () => {
    try {
      await loadRows();
    } catch (error) {
      setStatus(error.message || 'Failed to refresh.', true);
    }
  });

  menuTypeNode.addEventListener('change', async () => {
    try {
      await loadRows();
    } catch (error) {
      setStatus(error.message || 'Failed to switch menu.', true);
    }
  });

  categoryNode.addEventListener('change', () => {
    currentPage = 1;
    renderTable();
  });
  searchNode.addEventListener('input', () => {
    currentPage = 1;
    renderTable();
  });
  limitNode.addEventListener('change', () => {
    currentPage = 1;
    renderTable();
  });

  container.querySelector('#meSave').addEventListener('click', async () => {
    try {
      const menuType = menuTypeNode.value;
      const changes = Array.from(dirty.values());
      if (!changes.length) {
        setStatus('No unsaved changes.', false);
        return;
      }

      const payload = await adminApi('admin_menu_editor_save_changes', {
        method: 'POST',
        body: {
          menuType,
          changes,
        },
      });

      setStatus(`Saved ${payload.updatedCount || 0} row(s).`, false);
      await loadRows();
    } catch (error) {
      setStatus(error.message || 'Save failed.', true);
    }
  });

  container.querySelector('#meAddRow').addEventListener('click', async () => {
    try {
      const menuType = menuTypeNode.value;
      const isMenuA = menuType === 'menu_a';
      const row = {
        category: categoryNode.value || 'Uncategorized',
        itemName: 'New Item',
        description: '',
        isAvailable: true,
        isChefSpecial: false,
        isVeg: true,
        isNonveg: !isMenuA,
        isJain: false,
        isUniversal: false,
        pricingMode: 'standard',
        priceDirect: null,
        variants: [],
      };
      await adminApi('admin_menu_editor_add_row', {
        method: 'POST',
        body: { menuType, row },
      });
      await loadRows();
      setStatus('Row added.', false);
    } catch (error) {
      setStatus(error.message || 'Failed to add row.', true);
    }
  });

  container.querySelector('#meDeleteSelected').addEventListener('click', async () => {
    try {
      const ids = selectedIds();
      if (!ids.length) {
        setStatus('Select at least one row to delete.', true);
        return;
      }
      const menuType = menuTypeNode.value;
      const payload = await adminApi('admin_menu_editor_delete_rows', {
        method: 'POST',
        body: { menuType, ids },
      });
      setStatus(`Deleted ${payload.deleted || 0} row(s).`, false);
      await loadRows();
    } catch (error) {
      setStatus(error.message || 'Delete failed.', true);
    }
  });

  container.querySelector('#meAddVariantCol').addEventListener('click', () => {
    const label = String(newVariantNode.value || '').trim();
    if (!label) {
      setStatus('Enter a variant column name first.', true);
      return;
    }

    if (!variantColumns.includes(label)) {
      variantColumns = [...variantColumns, label];
    }

    newVariantNode.value = '';
    renderTable();
    setStatus(`Variant column "${label}" added. Fill values and click Save Changes.`, false);
  });

  async function bulkVisibility(isVisible) {
    const ids = selectedIds();
    if (!ids.length) {
      setStatus('Select at least one row first.', true);
      return;
    }

    const menuType = menuTypeNode.value;
    const payload = await adminApi('admin_menu_editor_set_visibility', {
      method: 'POST',
      body: { menuType, ids, isVisible },
    });

    setStatus(`Updated visibility for ${payload.updated || 0} row(s).`, false);
    await loadRows();
  }

  container.querySelector('#meSetVisible').addEventListener('click', async () => {
    try {
      await bulkVisibility(true);
    } catch (error) {
      setStatus(error.message || 'Visibility update failed.', true);
    }
  });

  container.querySelector('#meSetHidden').addEventListener('click', async () => {
    try {
      await bulkVisibility(false);
    } catch (error) {
      setStatus(error.message || 'Visibility update failed.', true);
    }
  });

  try {
    await loadRows();
  } catch (error) {
    setStatus(error.message || 'Unable to load menu editor.', true);
  }
}

function numberOrBlank(value) {
  if (value === null || value === undefined || value === '') {
    return '';
  }
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
