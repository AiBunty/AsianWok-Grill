/**
 * menu-ui.js
 * ==========
 * DOM rendering, filter controls, and UI interaction for the menu page.
 * Depends on MenuData (menu-data.js) being loaded first.
 * No Google Sheets or localStorage logic here — pure UI only.
 */

'use strict';

// ---------------------------------------------------------------------------
// UI state
// ---------------------------------------------------------------------------
let MENU_DB       = [];
let foodFilter    = 'ALL';
let showChefOnly  = false;
let showJainOnly  = false;
let activeVariant = {};
let activeQty     = {};
let searchQuery   = '';

function setSearchQuery(value) {
  searchQuery = String(value || '').trim();
  render();
}

// Re-export constants from data layer for convenient local use
const VEG_XPCS_VARIANTS = MenuData.VEG_XPCS_COLS;
const NON_VEG_VARIANTS  = ["Chicken", "Prawn", "Mutton", "Fish", "Surmai", "Pomfret", "Crab", "Egg"];

// ---------------------------------------------------------------------------
// Loader helpers
// ---------------------------------------------------------------------------
const loaderMountedAt = Date.now();

function hideLoader() {
  const loader = document.getElementById('loader');
  const loaderShell = loader ? loader.querySelector('.loader-shell') : null;
  if (!loader) return Promise.resolve();
  if (loader.dataset.hidden === 'true') return Promise.resolve();

  const minVisibleMs = 1100;
  const remaining = Math.max(0, minVisibleMs - (Date.now() - loaderMountedAt));

  return new Promise((resolve) => {
    window.setTimeout(() => {
      loader.dataset.hidden = 'true';
      if (loaderShell) loaderShell.setAttribute('aria-busy', 'false');
      loader.classList.add('is-closing');
      window.setTimeout(() => {
        loader.style.display = 'none';
        resolve();
      }, 560);
    }, remaining);
  });
}

// ---------------------------------------------------------------------------
// Error overlays
// ---------------------------------------------------------------------------
function showInitError(message) {
  const overlayId = 'menuInitErrorOverlay';
  let ov = document.getElementById(overlayId);
  if (!ov) {
    ov = document.createElement('div');
    ov.id = overlayId;
    ov.style = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);color:#fff;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;text-align:left;';
    const inner = document.createElement('div');
    inner.style = 'max-width:760px;background:#2b0000;padding:24px;border-radius:12px;line-height:1.45;';
    ov.appendChild(inner);
    document.body.appendChild(ov);
  }
  ov.firstChild.innerHTML = `<h2 style="color:#ffdede;margin-bottom:8px;">Menu failed to load</h2><div style="color:#ffdede;">${message}</div>`;
  ov.style.display = 'flex';
}

function showSchemaErrorOverlay(missing) {
  const REQUIRED_HEADERS = ["Item Name","Description","Category","Image URL"];
  const overlayId = 'schemaErrorOverlay';
  let ov = document.getElementById(overlayId);
  if (!ov) {
    ov = document.createElement('div');
    ov.id = overlayId;
    ov.style = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);color:#fff;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;text-align:left;';
    const inner = document.createElement('div');
    inner.style = 'max-width:900px;background:#2b0000;padding:24px;border-radius:12px;line-height:1.4;';
    ov.appendChild(inner);
    document.body.appendChild(ov);
  }
  ov.firstChild.innerHTML = `<h2 style="color:#ffdede;margin-bottom:8px;">Menu load failed: sheet schema validation</h2><div style="color:#ffdede;margin-bottom:12px;">Required columns missing or invalid. Please update the Google Sheet to include: <strong>${REQUIRED_HEADERS.join(', ')}</strong></div><pre style="background:#2b0000;color:#fff;overflow:auto;padding:8px;border-radius:6px;">${(missing||[]).map(h => JSON.stringify({ message: 'missing: ' + h })).join('\n')}</pre>`;
  ov.style.display = 'flex';
}

// ---------------------------------------------------------------------------
// Safety helper
// ---------------------------------------------------------------------------
function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// ---------------------------------------------------------------------------
// Exempt categories (always visible regardless of diet filter)
// ---------------------------------------------------------------------------
function isExemptItem(item) {
  const cat = (item.cat || '').toString().trim().toLowerCase();
  if (cat === 'mocktail') return true;
  if (cat === 'bread') return true;
  if (cat === 'dessert') {
    const desc = (item.desc || '').toLowerCase();
    const proteinsText = Object.values(item.proteins || {}).join(' ').toLowerCase();
    const dynamicText = Object.values(item.dynamic || {}).join(' ').toLowerCase();
    const found = (window.NON_VEG_WORDS || []).some(w => desc.includes(w) || proteinsText.includes(w) || dynamicText.includes(w));
    return !found;
  }
  return false;
}

// ---------------------------------------------------------------------------
// Card variant helpers (shared between generateCard and updateCardSelectionState)
// ---------------------------------------------------------------------------
function getDietTypeForVariant(item, selV) {
  let dietType = item.rowDiet;
  if (selV === 'Veg' || VEG_XPCS_VARIANTS.includes(selV)) dietType = item.descNonVeg ? 'nonveg' : 'veg';
  else if (selV === 'Jain') dietType = 'jain';
  else if (NON_VEG_VARIANTS.includes(selV)) dietType = 'nonveg';
  return dietType;
}

function getDisplayPriceForVariant(item, selV) {
  const proteinKeys = Object.keys(item.proteins || {});
  const servingKeys = Object.keys(item.servings || {}).filter(k => k !== 'Unit (Pcs)');
  if (selV === 'Jain') return item.jainPrice ?? '--';
  if (servingKeys.includes(selV)) return item.servings[selV];
  if (proteinKeys.includes(selV)) return item.proteins[selV];
  return '--';
}

function getChipActiveClasses(variantValue) {
  if (variantValue === 'Jain') return ['active', 'v-jain'];
  if (variantValue === 'Veg' || VEG_XPCS_VARIANTS.includes(variantValue)) return ['active', 'v-veg'];
  return ['active', 'v-non'];
}

// ---------------------------------------------------------------------------
// In-place card state update (no image reload)
// ---------------------------------------------------------------------------
function updateCardSelectionState(id) {
  const item = MENU_DB.find(x => x.id === id);
  const card = document.querySelector(`.card[data-item-id="${id}"]`);
  if (!item || !card) return false;

  const selV  = activeVariant[id];
  const dietType = getDietTypeForVariant(item, selV);
  const price = getDisplayPriceForVariant(item, selV);

  const priceEl = card.querySelector('[data-role="total-price"]');
  if (priceEl) priceEl.textContent = `₹${MenuData.formatPrice(price)}`;

  card.querySelectorAll('[data-variant-value]').forEach(chip => {
    chip.classList.remove('active', 'v-veg', 'v-non', 'v-jain');
    if (chip.getAttribute('data-variant-value') === String(selV)) {
      getChipActiveClasses(selV).forEach(cls => chip.classList.add(cls));
    }
  });

  const symbolsEl = card.querySelector('.header-right-symbols');
  if (symbolsEl) {
    symbolsEl.innerHTML = `${selV === 'Jain' ? '<div class="jain-badge">JAIN</div>' : `<div class="badge-type"><div class="dot ${dietType}"></div></div>`}${item.chef ? '<div class="chef-badge">👨‍🍳</div>' : ''}`;
  }

  return true;
}

// ---------------------------------------------------------------------------
// Card HTML generator
// ---------------------------------------------------------------------------
function generateCard(i) {
  const proteinKeys = Object.keys(i.proteins);
  const servingKeys = Object.keys(i.servings).filter(k => k !== 'Unit (Pcs)');
  const vegXpcs = VEG_XPCS_VARIANTS;

  if (!activeVariant[i.id]) {
    if (showJainOnly && i.jainPrice !== null)      activeVariant[i.id] = 'Jain';
    else if (foodFilter === 'VEG')                 activeVariant[i.id] = proteinKeys.find(k => k === 'Veg') || servingKeys.find(k => vegXpcs.includes(k)) || proteinKeys[0] || servingKeys[0];
    else if (foodFilter === 'NON')                 activeVariant[i.id] = proteinKeys.find(k => k !== 'Veg') || proteinKeys[0] || servingKeys[0];
    else                                           activeVariant[i.id] = proteinKeys[0] || servingKeys[0];
  }

  const selV         = activeVariant[i.id];
  const dietType     = getDietTypeForVariant(i, selV);
  const displayPrice = getDisplayPriceForVariant(i, selV);
  const spiceLabel   = MenuData.getSpiceSymbol(i.spice);

  return `<div class="card" data-item-id="${i.id}">
    <div class="img-box">
      <div class="img-container-circle">
        <img src="${i.img}" class="img-main" loading="lazy" onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400'">
      </div>
    </div>
    <div class="card-body">
      <div class="card-header-row">
        <div class="item-name">${i.name} ${spiceLabel ? `<span class="spice-indicator">${spiceLabel}</span>` : ''}</div>
        <div class="header-right-symbols">
          ${selV === 'Jain' ? `<div class="jain-badge">JAIN</div>` : `<div class="badge-type"><div class="dot ${dietType}"></div></div>`}
          ${i.chef ? `<div class="chef-badge">👨‍🍳</div>` : ''}
        </div>
      </div>
      ${i.servingUnit ? `<div class="serving-remark">Serving: ${i.servingUnit}</div>` : ''}
      <div class="item-desc collapsed" onclick="toggleDesc(this)">${i.desc}</div>
      <div class="variant-container">
        ${proteinKeys.map(v => `<div class="variant-chip ${selV === v ? 'active ' + (v === 'Veg' ? 'v-veg' : 'v-non') : ''}" data-variant-value="${escapeHtml(v)}" onclick="setVariant(${i.id}, '${v}')">${v}</div>`).join('')}
        ${vegXpcs.filter(k => i.servings[k]).map(v => `<div class="variant-chip ${selV === v ? 'active v-veg' : ''}" data-variant-value="${escapeHtml(v)}" onclick="setVariant(${i.id}, '${v}')">${v}</div>`).join('')}
        ${i.jainPrice !== null ? `<div class="variant-chip jain-type ${selV === 'Jain' ? 'active v-jain' : ''}" data-variant-value="Jain" onclick="setVariant(${i.id}, 'Jain')">Jain</div>` : ''}
      </div>
      ${Object.keys(i.dynamic || {}).length ? `<div class="variant-container">${Object.entries(i.dynamic).map(([k,v]) => `<div class="variant-chip" title="${k} ₹${MenuData.formatPrice(v)}">${k} ₹${MenuData.formatPrice(v)}</div>`).join('')}</div>` : ''}
      <div class="qty-container">
        ${servingKeys.filter(k => !vegXpcs.includes(k)).map(q => `<div class="qty-chip ${selV === q ? 'active ' + (dietType === 'veg' ? 'v-veg' : 'v-non') : ''}" data-variant-value="${escapeHtml(q)}" onclick="setVariant(${i.id}, '${q}')">${q}</div>`).join('')}
      </div>
      <div class="price"><span>Total</span> <span data-role="total-price">₹${MenuData.formatPrice(displayPrice)}</span></div>
    </div>
  </div>`;
}

// ---------------------------------------------------------------------------
// Main render
// ---------------------------------------------------------------------------
function render() {
  const main    = document.getElementById('menuMain');
  const sideList = document.getElementById('sideNavList');
  let db = MENU_DB;

  if (showJainOnly) {
    db = db.filter(i => (i.jainPrice !== null && i.isVeg) || isExemptItem(i));
  } else {
    if (foodFilter === 'VEG') db = db.filter(i => i.isVeg || isExemptItem(i));
    if (foodFilter === 'NON') db = db.filter(i => !i.isVeg || isExemptItem(i));
  }
  if (showChefOnly) db = db.filter(i => i.chef);

  // Search filter — applied on top of all other filters
  const query = searchQuery.toLowerCase();
  if (query) {
    db = db.filter(i => {
      const variantKeys = [...Object.keys(i.proteins || {}), ...Object.keys(i.servings || {}), ...Object.keys(i.dynamic || {})];
      const searchable = [i.name, i.desc, i.cat, ...variantKeys].join(' ').toLowerCase();
      return searchable.includes(query);
    });
  }

  // Preserve original category order but only include categories that have
  // at least one item in the filtered set (e.g. only chef-special categories).
  const allCats    = [...new Set(MENU_DB.map(i => i.cat))];
  const activeCats = allCats.filter(c => db.some(i => i.cat === c));

  if (!activeCats.length) {
    main.innerHTML = '<div class="empty-state">No items found. Try a different keyword.</div>';
    sideList.innerHTML = '';
    return;
  }

  main.innerHTML = activeCats.map(c => {
    const items = db.filter(i => i.cat === c);
    const slug = c.replace(/[^a-z0-9]/gi, '-').toLowerCase();
    return `<section class="container" id="${slug}"><h2 class="section-title">${c}</h2><div class="grid">${items.map(i => generateCard(i)).join('')}</div></section>`;
  }).join('');

  sideList.innerHTML = activeCats.map(c => {
    const slug = c.replace(/[^a-z0-9]/gi, '-').toLowerCase();
    return `<div class="cat-link" onclick="snapTo('${slug}')">${c}</div>`;
  }).join('');
}

// ---------------------------------------------------------------------------
// Symbol legend
// ---------------------------------------------------------------------------
function buildSymbolDefinitions() {
  const defs = [];
  const hasVeg    = MENU_DB.some(i => i.isVeg);
  const hasNonVeg = MENU_DB.some(i => !i.isVeg);
  const hasJain   = MENU_DB.some(i => i.jainPrice !== null);
  const hasChef   = MENU_DB.some(i => i.chef);
  const spiceLevels = [...new Set(MENU_DB.map(i => (i.spice || '').toString().trim()).filter(Boolean))];

  if (hasVeg)    defs.push({ iconHtml: '<span class="badge-type"><span class="dot veg"></span></span>',    label: 'Vegetarian',     desc: 'Pure vegetarian dishes with no meat or seafood.' });
  if (hasNonVeg) defs.push({ iconHtml: '<span class="badge-type"><span class="dot nonveg"></span></span>', label: 'Non-Vegetarian', desc: 'Contains meat, poultry, seafood, or egg.' });
  if (hasJain)   defs.push({ iconHtml: '<span class="jain-badge">JAIN</span>',                            label: 'Jain Option',    desc: 'Prepared without onion, garlic, or root vegetables.' });
  if (hasChef)   defs.push({ iconHtml: '👨‍🍳', label: 'Chef Special', desc: 'Signature dishes handpicked by our kitchen team.' });

  spiceLevels.forEach(level => {
    const icon = MenuData.getSpiceSymbol(level);
    if (!icon) return;
    const lv = level.toLowerCase();
    defs.push({
      iconHtml: icon,
      label: level,
      desc: /non spicy|non-spicy|mild|no spice|low/.test(lv)
        ? 'Mild dishes with no added spice.'
        : 'Spice level listed in the current Google Sheet menu.'
    });
  });

  return defs;
}

function symbolCardMarkup(def) {
  return `<article class="legend-card spice-dynamic-card"><span class="legend-icon">${def.iconHtml}</span><span class="legend-label">${escapeHtml(def.label)}</span><span class="legend-desc">${escapeHtml(def.desc)}</span></article>`;
}

function renderSymbolLegends() {
  const symbolDefs = buildSymbolDefinitions();
  const mainGrid   = document.getElementById('symbolLegendGrid');
  const quickGrid  = document.getElementById('symbolGuideGrid');
  const html = symbolDefs.map(symbolCardMarkup).join('');
  if (mainGrid)  mainGrid.innerHTML  = html;
  if (quickGrid) quickGrid.innerHTML = html;
}

// ---------------------------------------------------------------------------
// Filter / toggle controls (called from HTML onclick)
// ---------------------------------------------------------------------------
function setVariant(id, v) {
  activeVariant[id] = v;
  if (!updateCardSelectionState(id)) render();
}

function toggleDesc(el) {
  el.classList.toggle('collapsed');
  el.classList.toggle('expanded');
}

// Tracks a category target requested while the page is still loading.
let _pendingSnapTarget = null;

function _getStickyOffset() {
  const header   = document.querySelector('.concept-header');
  const controls = document.getElementById('topControlsBar');
  const headerH   = header   ? header.getBoundingClientRect().height   : 92;
  const controlsH = controls ? controls.getBoundingClientRect().height : 0;
  return Math.round(headerH + controlsH) + 8; // 8 px breathing room
}

function _doSnap(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const offset = _getStickyOffset();
  window.scrollTo({ top: el.getBoundingClientRect().top + window.pageYOffset - offset, behavior: 'smooth' });
}

function snapTo(id) {
  toggleSideNav(false);
  _pendingSnapTarget = id;
  _doSnap(id);
}

function updateFilter(val) {
  foodFilter = val;
  if (val !== 'VEG') showJainOnly = false;
  activeVariant = {};
  document.querySelectorAll('.top-controls-bar .square-btn').forEach(b => {
    if (b.id && b.id.startsWith('f-')) b.classList.remove('active');
  });
  document.getElementById('f-' + val.toLowerCase()).classList.add('active');
  render();
}

function setChefToggle(val) {
  showChefOnly = val;
  document.getElementById('chef-on').classList.toggle('active', val);
  document.getElementById('chef-off').classList.toggle('active', !val);
  render();
}

function setJainToggle(val) {
  showJainOnly = val;
  activeVariant = {};
  if (val) updateFilter('VEG');
  document.getElementById('jain-on').classList.toggle('active', val);
  document.getElementById('jain-off').classList.toggle('active', !val);
  render();
}

function toggleSideNav(show) {
  const drawer  = document.getElementById('sideNavContainer');
  const overlay = document.getElementById('sideNavOverlay');
  if (show) {
    document.body.classList.add('drawer-open');
    drawer.classList.add('open');
    overlay.style.display = 'block';
  } else {
    drawer.classList.remove('open');
    overlay.style.display = 'none';
    document.body.classList.remove('drawer-open');
  }
}

function openSymbolGuide() {
  const modal = document.getElementById('symbolGuideModal');
  if (!modal) return;
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('modal-open');
}

function closeSymbolGuide() {
  const modal = document.getElementById('symbolGuideModal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('modal-open');
}

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resolvePrimaryFooterContact() {
  const primaryWa =
    document.querySelector('.concept-footer .property-footer-card:first-of-type a[href*="wa.me/"]') ||
    document.querySelector('.concept-footer a[href*="wa.me/"]');
  if (!primaryWa) return null;

  const href = primaryWa.getAttribute('href') || '';
  const phoneMatch = href.match(/wa\.me\/(\d+)/i);
  if (!phoneMatch || !phoneMatch[1]) return null;

  const phone = phoneMatch[1];
  return {
    whatsappUrl: `https://wa.me/${phone}`,
    callUrl: `tel:+${phone}`,
    phone,
  };
}

function toggleQuickActions(forceCollapsed) {
  const wrap = document.getElementById('floatingQuickActions');
  const toggleBtn = document.getElementById('quickActionToggleBtn');
  if (!wrap || !toggleBtn) return;

  const nextCollapsed = typeof forceCollapsed === 'boolean'
    ? forceCollapsed
    : !wrap.classList.contains('is-collapsed');

  wrap.classList.toggle('is-collapsed', nextCollapsed);
  toggleBtn.classList.toggle('is-collapsed', nextCollapsed);
  toggleBtn.setAttribute('aria-pressed', String(nextCollapsed));
  toggleBtn.setAttribute('aria-label', nextCollapsed ? 'Show quick actions' : 'Hide quick actions');
  toggleBtn.setAttribute('title', nextCollapsed ? 'Show quick actions' : 'Hide quick actions');
}

function initFloatingQuickActions() {
  const waBtn = document.getElementById('floatingWhatsAppBtn');
  const callBtn = document.getElementById('floatingCallBtn');
  const toggleBtn = document.getElementById('quickActionToggleBtn');
  if (!waBtn || !callBtn || !toggleBtn) return;

  const contact = resolvePrimaryFooterContact();
  if (!contact) {
    waBtn.style.display = 'none';
    callBtn.style.display = 'none';
    toggleBtn.style.display = 'none';
    return;
  }

  waBtn.href = contact.whatsappUrl;
  callBtn.href = contact.callUrl;
  waBtn.setAttribute('aria-label', `Chat on WhatsApp (${contact.phone})`);
  callBtn.setAttribute('aria-label', `Call ${contact.phone}`);
}

// ---------------------------------------------------------------------------
// URL params + hash anchor handling (runs after render)
// ---------------------------------------------------------------------------
function checkURLParams() {
  const params = new URLSearchParams(window.location.search);
  if (params.get('chef') === 'true') {
    showChefOnly = true;
    document.getElementById('chef-on').classList.add('active');
    document.getElementById('chef-off').classList.remove('active');
  }
  const hash = window.location.hash;
  if (hash) {
    const targetId = hash.substring(1);
    setTimeout(() => { snapTo(targetId); }, 100);
  }
}

// ---------------------------------------------------------------------------
// Header nav interactions
// ---------------------------------------------------------------------------
(function setupHeaderNav() {
  const conceptMenuToggle = document.getElementById('conceptMenuToggle');
  const conceptNav        = document.getElementById('conceptNav');
  const orderOnlineWrap   = document.getElementById('orderOnlineWrap');
  const orderOnlineBtn    = orderOnlineWrap ? orderOnlineWrap.querySelector('.order-online-btn') : null;

  if (conceptMenuToggle && conceptNav) {
    conceptMenuToggle.addEventListener('click', () => {
      const next = !conceptNav.classList.contains('open');
      conceptNav.classList.toggle('open', next);
      conceptMenuToggle.classList.toggle('active', next);
      conceptMenuToggle.setAttribute('aria-expanded', String(next));
    });
    conceptNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        conceptNav.classList.remove('open');
        conceptMenuToggle.classList.remove('active');
        conceptMenuToggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  if (orderOnlineWrap && orderOnlineBtn) {
    orderOnlineBtn.addEventListener('click', () => {
      const next = !orderOnlineWrap.classList.contains('open');
      orderOnlineWrap.classList.toggle('open', next);
      orderOnlineBtn.setAttribute('aria-expanded', String(next));
    });
    document.addEventListener('click', (event) => {
      if (!orderOnlineWrap.contains(event.target)) {
        orderOnlineWrap.classList.remove('open');
        orderOnlineBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeSymbolGuide();
      if (orderOnlineWrap && orderOnlineBtn) {
        orderOnlineWrap.classList.remove('open');
        orderOnlineBtn.setAttribute('aria-expanded', 'false');
      }
    }
  });

  const symbolGuideModal = document.getElementById('symbolGuideModal');
  if (symbolGuideModal) {
    symbolGuideModal.addEventListener('click', (event) => {
      if (event.target === symbolGuideModal) closeSymbolGuide();
    });
  }
})();

// ---------------------------------------------------------------------------
// Ambient canvas background particles
// ---------------------------------------------------------------------------
function initMenuAmbient() {
  // Skip on mobile — canvas animations are GPU-heavy and cause crashes on low-end phones.
  if (window.matchMedia('(max-width: 768px)').matches) return;

  const canvas     = document.getElementById('menuAmbientCanvas');
  const blurTop    = document.getElementById('menuAmbientBlurTop');
  const blurBottom = document.getElementById('menuAmbientBlurBottom');
  if (!canvas || !blurTop || !blurBottom) return;

  const ctx       = canvas.getContext('2d');
  const topCtx    = blurTop.getContext('2d');
  const bottomCtx = blurBottom.getContext('2d');
  if (!ctx || !topCtx || !bottomCtx) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let width = 0, height = 0, rafId = 0, particles = [];

  const palette = [
    'rgba(244, 236, 222, 0.92)',
    'rgba(242, 213, 166, 0.82)',
    'rgba(196, 110, 83, 0.7)',
    'rgba(139, 58, 56, 0.58)',
    'rgba(98, 138, 119, 0.46)'
  ];

  function fitCanvas(target, targetCtx) {
    const dpr  = Math.min(window.devicePixelRatio || 1, 1.5);
    target.width  = Math.round(width * dpr);
    target.height = Math.round(height * dpr);
    targetCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function createParticle() {
    const speed = reduceMotion ? 0 : 0.08 + Math.random() * 0.22;
    const angle = Math.random() * Math.PI * 2;
    return {
      x: Math.random() * width,
      y: Math.random() * height,
      radius: 2.2 + Math.random() * 4.2,
      color: palette[Math.floor(Math.random() * palette.length)],
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed
    };
  }

  function resetScene() {
    width  = window.innerWidth;
    height = window.innerHeight;
    fitCanvas(canvas, ctx);
    fitCanvas(blurTop, topCtx);
    fitCanvas(blurBottom, bottomCtx);
    const particleCount = reduceMotion ? 20 : Math.max(44, Math.min(86, Math.round((width * height) / 22000)));
    particles = Array.from({ length: particleCount }, createParticle);
    renderFrame();
  }

  function updateParticles() {
    for (const p of particles) {
      p.x += p.vx;
      p.y += p.vy;
      if (p.x < -20)          p.x = width + 20;
      if (p.x > width + 20)   p.x = -20;
      if (p.y < -20)          p.y = height + 20;
      if (p.y > height + 20)  p.y = -20;
    }
  }

  function drawLinks() {
    const linkDistance = Math.min(150, width * 0.16);
    for (let i = 0; i < particles.length; i++) {
      const a = particles[i];
      for (let j = i + 1; j < particles.length; j++) {
        const b = particles[j];
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        const distance = Math.hypot(dx, dy);
        if (distance > linkDistance) continue;
        ctx.beginPath();
        ctx.strokeStyle = `rgba(242, 213, 166, ${0.14 * (1 - distance / linkDistance)})`;
        ctx.lineWidth = 1.2;
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();
      }
    }
  }

  function drawParticles() {
    for (const p of particles) {
      ctx.shadowBlur  = 18;
      ctx.shadowColor = p.color;
      ctx.beginPath();
      ctx.fillStyle = p.color;
      ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.shadowBlur = 0;
  }

  function renderFrame() {
    ctx.clearRect(0, 0, width, height);
    drawLinks();
    drawParticles();
    topCtx.clearRect(0, 0, width, height);
    bottomCtx.clearRect(0, 0, width, height);
    topCtx.drawImage(canvas, 0, 0, width, height);
    bottomCtx.drawImage(canvas, 0, 0, width, height);
  }

  function animate() {
    updateParticles();
    renderFrame();
    rafId = window.requestAnimationFrame(animate);
  }

  function handleVisibility() {
    if (document.hidden) {
      if (rafId) { window.cancelAnimationFrame(rafId); rafId = 0; }
      return;
    }
    if (!reduceMotion && !rafId) rafId = window.requestAnimationFrame(animate);
  }

  resetScene();
  if (!reduceMotion) {
    rafId = window.requestAnimationFrame(animate);
    document.addEventListener('visibilitychange', handleVisibility);
  }
  window.addEventListener('resize', resetScene, { passive: true });
}

window.addEventListener('load', initMenuAmbient, { once: true });

// ---------------------------------------------------------------------------
// Main init — called by menu.html on window.onload
// ---------------------------------------------------------------------------
async function init() {
  try {
    const { items, fromCache } = await MenuData.loadAndParseMenu();

    MENU_DB = items;

    document.getElementById('footerSyncTime').innerText =
      `Last synced: Today at ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
    document.getElementById('footerItemCount').innerText = `Total Selections: ${MENU_DB.length}`;

    initFloatingQuickActions();
    renderSymbolLegends();
    MenuData.warmMenuAssets(MENU_DB);
    checkURLParams();
    render();
    await hideLoader();
    // Re-apply any category scroll that fired during loading (layout is now stable).
    if (_pendingSnapTarget) {
      const t = _pendingSnapTarget;
      _pendingSnapTarget = null;
      window.setTimeout(() => _doSnap(t), 80);
    }
  } catch (e) {
    console.error(e);
    if (e && e.type === 'SCHEMA_ERROR') {
      showSchemaErrorOverlay(e.missing || []);
    } else {
      showInitError(e && e.message
        ? e.message
        : 'Unexpected error while reading menu data. Please refresh once. If issue persists, check browser console logs.'
      );
    }
    await hideLoader();
  }
}

window.onload = init;
