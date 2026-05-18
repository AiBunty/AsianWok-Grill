import { adminApi } from './api-client.js';

const MENU_OPTIONS = [
  { value: 'menu_a', label: 'Menu A (menu.html)' },
  { value: 'menu_b', label: 'Menu B (cocktail.html)' },
  { value: 'menu_c', label: 'Menu C (namastemenu.html)' },
];

export async function renderMenuCategoryDesigner(container) {
  container.innerHTML = `
    <section class="admin-module-card md2-shell">
      <style>
        .md2-shell {
          background: #f6f2e8;
          border: 1px solid rgba(123, 94, 67, 0.18);
        }

        .md2-header {
          border: 1px solid rgba(123, 94, 67, 0.14);
          border-radius: 14px;
          background: #f9f6ef;
          padding: 16px 18px;
          margin-bottom: 14px;
        }

        .md2-header h3 {
          margin: 0 0 4px;
          color: #3f2d1e;
          font-size: 1.55rem;
          font-weight: 800;
        }

        .md2-header p {
          margin: 0;
          color: #6a5644;
          font-size: 0.9rem;
        }

        .md2-toolbar {
          border: 1px solid rgba(123, 94, 67, 0.14);
          border-radius: 14px;
          background: #f9f6ef;
          padding: 10px 12px;
          margin-bottom: 12px;
        }

        .md2-toolbar-row {
          display: flex;
          flex-wrap: wrap;
          align-items: center;
          gap: 8px;
          margin-bottom: 8px;
        }

        .md2-toolbar-row:last-child {
          margin-bottom: 0;
        }

        .md2-menu-select,
        .md2-category-search {
          min-width: 220px;
          background: #fff;
          border: 1px solid rgba(123, 94, 67, 0.25);
          border-radius: 10px;
          color: #4e3a29;
          font: inherit;
          padding: 9px 11px;
        }

        .md2-chip {
          margin-left: auto;
          border-radius: 999px;
          background: #efe8db;
          border: 1px solid rgba(123, 94, 67, 0.2);
          color: #6c563f;
          padding: 5px 11px;
          font-size: 0.74rem;
          font-weight: 700;
        }

        .md2-status {
          border-radius: 8px;
          background: #e4e8de;
          color: #385b40;
          font-size: 0.82rem;
          padding: 6px 8px;
        }

        .md2-status.error {
          background: #f8e5e3;
          color: #8b352d;
        }

        .md2-grid {
          display: grid;
          grid-template-columns: 1fr 1.6fr;
          gap: 12px;
        }

        .md2-pane {
          border: 1px solid rgba(123, 94, 67, 0.14);
          border-radius: 10px;
          background: #faf8f2;
          overflow: hidden;
          min-height: 58vh;
        }

        .md2-pane-title {
          margin: 0;
          font-size: 0.75rem;
          letter-spacing: 0.09em;
          color: #746150;
          text-transform: uppercase;
          font-weight: 800;
          background: #f3ede2;
          border-bottom: 1px solid rgba(123, 94, 67, 0.16);
          padding: 10px 12px;
        }

        .md2-list {
          margin: 0;
          padding: 10px;
          list-style: none;
          max-height: 64vh;
          overflow: auto;
        }

        .md2-row {
          background: #fff;
          border: 1px solid rgba(123, 94, 67, 0.18);
          border-radius: 9px;
          padding: 8px 10px;
          margin-bottom: 7px;
          display: flex;
          align-items: flex-start;
          gap: 9px;
          transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
        }

        .md2-row:last-child {
          margin-bottom: 0;
        }

        .md2-row:hover {
          border-color: rgba(123, 94, 67, 0.35);
          box-shadow: 0 2px 8px rgba(68, 46, 28, 0.08);
        }

        .md2-row.selected {
          border-color: #c6884b;
          background: #fff8ef;
        }

        .md2-row.drag-target {
          border-style: dashed;
          border-color: #b0753b;
          background: #fff4e4;
        }

        .md2-handle {
          flex: 0 0 auto;
          width: 18px;
          height: 24px;
          border-radius: 5px;
          background:
            radial-gradient(circle at 4px 5px, #c7b39d 1.4px, transparent 1.7px),
            radial-gradient(circle at 12px 5px, #c7b39d 1.4px, transparent 1.7px),
            radial-gradient(circle at 4px 12px, #c7b39d 1.4px, transparent 1.7px),
            radial-gradient(circle at 12px 12px, #c7b39d 1.4px, transparent 1.7px),
            radial-gradient(circle at 4px 19px, #c7b39d 1.4px, transparent 1.7px),
            radial-gradient(circle at 12px 19px, #c7b39d 1.4px, transparent 1.7px);
          cursor: grab;
          border: 1px solid rgba(123, 94, 67, 0.15);
        }

        .md2-row:active .md2-handle {
          cursor: grabbing;
        }

        .md2-main {
          flex: 1;
          min-width: 0;
        }

        .md2-title {
          color: #3c2d1f;
          font-weight: 700;
          line-height: 1.25;
        }

        .md2-sub {
          margin-top: 2px;
          color: #8b7764;
          font-size: 0.75rem;
          line-height: 1.2;
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
        }

        .md2-pill {
          border: 1px solid rgba(123, 94, 67, 0.2);
          border-radius: 999px;
          background: #f7f2ea;
          color: #725a42;
          padding: 1px 6px;
          font-size: 0.69rem;
        }

        .md2-actions {
          display: flex;
          gap: 6px;
        }

        .md2-empty {
          color: #7e6b59;
          padding: 18px;
          font-size: 0.9rem;
        }

        @media (max-width: 980px) {
          .md2-grid {
            grid-template-columns: 1fr;
          }

          .md2-pane {
            min-height: 320px;
          }

          .md2-list {
            max-height: 42vh;
          }

          .md2-chip {
            margin-left: 0;
          }
        }
      </style>

      <header class="md2-header">
        <h3>Menu Category Designer</h3>
        <p>Control category order and item order for Food and Cocktail menus. Drag and drop rows, then save your changes.</p>
      </header>

      <section class="md2-toolbar">
        <div class="md2-toolbar-row">
          <select id="mdMenuType" class="md2-menu-select">${MENU_OPTIONS.map((opt) => `<option value="${opt.value}">${opt.label}</option>`).join('')}</select>
          <input id="mdFilter" class="md2-category-search" type="text" placeholder="Search category" />
          <span id="mdStats" class="md2-chip">Categories: 0 · Items: 0</span>
        </div>
        <div class="md2-toolbar-row">
          <button id="mdRefresh" class="admin-button admin-button-secondary" type="button">Reload</button>
          <button id="mdSaveCategoryOrder" class="admin-button" type="button">Save Category Order</button>
          <button id="mdSaveItemOrder" class="admin-button admin-button-secondary" type="button">Save Item Order</button>
        </div>
        <div id="mdStatus" class="md2-status">Loading category designer...</div>
      </section>

      <section id="mdBoard" class="md2-grid"></section>
    </section>
  `;

  const menuTypeNode = container.querySelector('#mdMenuType');
  const filterNode = container.querySelector('#mdFilter');
  const statusNode = container.querySelector('#mdStatus');
  const statsNode = container.querySelector('#mdStats');
  const boardNode = container.querySelector('#mdBoard');

  let categories = [];
  let selectedCategoryName = '';
  let dragCategoryName = '';
  let dragItemPayload = null;

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  function filteredCategories() {
    const term = String(filterNode.value || '').trim().toLowerCase();
    if (!term) {
      return categories;
    }
    return categories.filter((cat) => String(cat.name || '').toLowerCase().includes(term));
  }

  function normalizeItemOrder() {
    categories.forEach((category, categoryIndex) => {
      category.sortOrder = categoryIndex;
      category.items = Array.isArray(category.items) ? category.items : [];
      category.items.forEach((item, itemIndex) => {
        item.itemSortOrder = itemIndex;
      });
    });
  }

  function getSelectedCategory() {
    const matched = categories.find((category) => category.name === selectedCategoryName);
    if (matched) {
      return matched;
    }

    const visibleCategories = filteredCategories();
    if (visibleCategories.length > 0) {
      selectedCategoryName = String(visibleCategories[0].name || '');
      return visibleCategories[0];
    }

    selectedCategoryName = '';
    return null;
  }

  function updateStats() {
    const visible = filteredCategories();
    const itemCount = visible.reduce((sum, category) => sum + (Array.isArray(category.items) ? category.items.length : 0), 0);
    statsNode.textContent = `Categories: ${visible.length} · Items: ${itemCount}`;
  }

  function bindBoardActions() {
    Array.from(boardNode.querySelectorAll('[data-md-category-row]')).forEach((card) => {
      card.addEventListener('click', () => {
        selectedCategoryName = String(card.getAttribute('data-md-category-row') || '');
        renderBoard();
      });

      card.addEventListener('dragstart', (event) => {
        dragCategoryName = String(card.getAttribute('data-md-category-row') || '');
        card.style.opacity = '0.5';
        event.dataTransfer.effectAllowed = 'move';
      });

      card.addEventListener('dragend', () => {
        dragCategoryName = '';
        card.style.opacity = '';
      });

      card.addEventListener('dragover', (event) => {
        event.preventDefault();
        card.classList.add('drag-target');
      });

      card.addEventListener('dragleave', () => {
        card.classList.remove('drag-target');
      });

      card.addEventListener('drop', (event) => {
        event.preventDefault();
        card.classList.remove('drag-target');
        const targetName = String(card.getAttribute('data-md-category-row') || '');

        if (dragItemPayload && dragItemPayload.itemId) {
          moveItemToPosition(dragItemPayload.itemId, dragItemPayload.sourceCategory, targetName, null);
          return;
        }

        if (!dragCategoryName || !targetName || dragCategoryName === targetName) {
          return;
        }

        const sourceIndex = categories.findIndex((cat) => cat.name === dragCategoryName);
        const targetIndex = categories.findIndex((cat) => cat.name === targetName);
        if (sourceIndex < 0 || targetIndex < 0) {
          return;
        }

        const [moved] = categories.splice(sourceIndex, 1);
        categories.splice(targetIndex, 0, moved);
        normalizeItemOrder();
        renderBoard();
        setStatus(`Moved category ${dragCategoryName}. Save order to persist.`, false);
      });
    });

    Array.from(boardNode.querySelectorAll('[data-md-item-card]')).forEach((card) => {
      card.addEventListener('dragstart', (event) => {
        dragItemPayload = {
          itemId: Number(card.getAttribute('data-md-item-id')),
          sourceCategory: String(card.getAttribute('data-md-item-category') || ''),
        };
        card.style.opacity = '0.5';
        event.dataTransfer.effectAllowed = 'move';
      });

      card.addEventListener('dragend', () => {
        dragItemPayload = null;
        card.style.opacity = '';
      });

      card.addEventListener('dragover', (event) => {
        event.preventDefault();
        card.classList.add('drag-target');
      });

      card.addEventListener('dragleave', () => {
        card.classList.remove('drag-target');
      });

      card.addEventListener('drop', (event) => {
        event.preventDefault();
        event.stopPropagation(); // prevent zone drop from also firing
        card.classList.remove('drag-target');
        if (!dragItemPayload) return;

        const targetItemId = Number(card.getAttribute('data-md-item-id'));
        const targetCategory = String(card.getAttribute('data-md-item-category') || '');
        moveItemToPosition(dragItemPayload.itemId, dragItemPayload.sourceCategory, targetCategory, targetItemId);
      });
    });

    Array.from(boardNode.querySelectorAll('[data-md-item-zone]')).forEach((zone) => {
      zone.addEventListener('dragover', (event) => {
        event.preventDefault();
        zone.classList.add('drag-target');
      });

      zone.addEventListener('dragleave', () => {
        zone.classList.remove('drag-target');
      });

      zone.addEventListener('drop', (event) => {
        event.preventDefault();
        zone.classList.remove('drag-target');
        if (!dragItemPayload) return;
        const targetCategory = String(zone.getAttribute('data-md-item-zone') || '');
        moveItemToPosition(dragItemPayload.itemId, dragItemPayload.sourceCategory, targetCategory, null);
      });
    });

    Array.from(boardNode.querySelectorAll('[data-md-toggle-category]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const name = String(button.getAttribute('data-md-toggle-category') || '');
        const category = categories.find((c) => c.name === name);
        if (!category) return;

        try {
          await adminApi('admin_menu_designer_toggle_category', {
            method: 'POST',
            body: {
              menuType: menuTypeNode.value,
              categoryName: name,
              isActive: !category.isActive,
            },
          });
          category.isActive = !category.isActive;
          renderBoard();
          setStatus(`Category ${name} updated.`, false);
        } catch (error) {
          setStatus(error.message || 'Failed to toggle category.', true);
        }
      });
    });

    Array.from(boardNode.querySelectorAll('[data-md-toggle-item]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const id = Number(button.getAttribute('data-md-toggle-item'));
        const item = findItemById(id);
        if (!item) return;

        try {
          await adminApi('admin_menu_designer_toggle_item', {
            method: 'POST',
            body: {
              menuType: menuTypeNode.value,
              id,
              isAvailable: !item.isAvailable,
            },
          });
          item.isAvailable = !item.isAvailable;
          renderBoard();
          setStatus(`Item visibility updated.`, false);
        } catch (error) {
          setStatus(error.message || 'Failed to toggle item.', true);
        }
      });
    });

    Array.from(boardNode.querySelectorAll('[data-md-clone-category]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const sourceCategory = String(button.getAttribute('data-md-clone-category') || '');
        const targetCategory = prompt('Clone into category name:', `${sourceCategory} Copy`);
        if (!targetCategory) {
          return;
        }

        const cloneItems = window.confirm('Clone items too? Click OK for yes, Cancel for category-only clone.');

        try {
          const payload = await adminApi('admin_menu_designer_clone_category', {
            method: 'POST',
            body: {
              menuType: menuTypeNode.value,
              sourceCategory,
              targetCategory,
              cloneItems,
            },
          });
          setStatus(`Category cloned. Items copied: ${payload.itemsCloned || 0}.`, false);
          await loadDesigner();
        } catch (error) {
          setStatus(error.message || 'Failed to clone category.', true);
        }
      });
    });
  }

  function moveItemToPosition(itemId, sourceCategory, targetCategory, beforeItemId) {
    const source = categories.find((cat) => cat.name === sourceCategory);
    const target = categories.find((cat) => cat.name === targetCategory);
    if (!source || !target) return;

    const sourceIndex = source.items.findIndex((item) => Number(item.id) === Number(itemId));
    if (sourceIndex < 0) return;

    const [moving] = source.items.splice(sourceIndex, 1);
    const targetIndex = beforeItemId === null
      ? target.items.length
      : target.items.findIndex((item) => Number(item.id) === Number(beforeItemId));

    if (targetIndex < 0) {
      target.items.push(moving);
    } else {
      target.items.splice(targetIndex, 0, moving);
    }

    normalizeItemOrder();
    renderBoard();
    setStatus(`Moved item ${moving.itemName || moving.id}. Save item order to persist.`, false);
  }

  function findItemById(id) {
    for (const category of categories) {
      const found = category.items.find((item) => Number(item.id) === Number(id));
      if (found) return found;
    }
    return null;
  }

  function renderBoard() {
    const visibleCategories = filteredCategories();
    updateStats();

    if (!visibleCategories.length) {
      boardNode.innerHTML = '<section class="md2-pane"><p class="md2-pane-title">Categories</p><div class="md2-empty">No category matches this filter.</div></section><section class="md2-pane"><p class="md2-pane-title">Items</p><div class="md2-empty">Select a category to view items.</div></section>';
      return;
    }

    const selectedCategory = getSelectedCategory();

    boardNode.innerHTML = `
      <section class="md2-pane">
        <p class="md2-pane-title">Categories</p>
        <ul class="md2-list" data-md-category-zone="1">
          ${visibleCategories.map((category) => {
            const isSelected = selectedCategoryName === category.name;
            return `
              <li
                class="md2-row ${isSelected ? 'selected' : ''}"
                draggable="true"
                data-md-category-row="${escapeHtml(category.name)}"
              >
                <span class="md2-handle" aria-hidden="true"></span>
                <div class="md2-main">
                  <div class="md2-title">${escapeHtml(category.name)}</div>
                  <div class="md2-sub">
                    <span>${category.items.length} item(s)</span>
                    <span class="md2-pill">${category.isActive ? 'Visible' : 'Hidden'}</span>
                  </div>
                </div>
                <div class="md2-actions">
                  <button type="button" class="admin-button admin-button-secondary" data-md-toggle-category="${escapeHtml(category.name)}">${category.isActive ? 'Hide' : 'Show'}</button>
                  <button type="button" class="admin-button admin-button-secondary" data-md-clone-category="${escapeHtml(category.name)}">Clone</button>
                </div>
              </li>
            `;
          }).join('')}
        </ul>
      </section>

      <section class="md2-pane">
        <p class="md2-pane-title">Items in Selected Category</p>
        <ul class="md2-list" data-md-item-zone="${escapeHtml(selectedCategory ? selectedCategory.name : '')}">
          ${selectedCategory && selectedCategory.items.length
            ? selectedCategory.items.map((item) => `
              <li
                class="md2-row"
                draggable="true"
                data-md-item-card="${escapeHtml(String(item.id))}"
                data-md-item-id="${escapeHtml(String(item.id))}"
                data-md-item-category="${escapeHtml(selectedCategory.name)}"
              >
                <span class="md2-handle" aria-hidden="true"></span>
                <div class="md2-main">
                  <div class="md2-title">${escapeHtml(item.itemName || '')}</div>
                  <div class="md2-sub">
                    <span>ID: ${escapeHtml(String(item.id))}</span>
                    <span>Base: ${escapeHtml(String(item.basePrice || item.price || '-'))}</span>
                    <span class="md2-pill">${item.isAvailable ? 'Visible' : 'Hidden'}</span>
                  </div>
                </div>
                <div class="md2-actions">
                  <button type="button" class="admin-button admin-button-secondary" data-md-toggle-item="${escapeHtml(String(item.id))}">${item.isAvailable ? 'Hide' : 'Show'}</button>
                </div>
              </li>
            `).join('')
            : '<li class="md2-empty">No items available in this category.</li>'
          }
        </ul>
      </section>
    `;

    bindBoardActions();
  }

  async function loadDesigner() {
    const menuType = menuTypeNode.value;
    setStatus(`Loading ${menuType} category designer...`, false);
    const payload = await adminApi('admin_menu_designer_load', {
      query: { menuType },
    });
    categories = Array.isArray(payload.categories) ? payload.categories : [];
    categories.forEach((category) => {
      category.items = Array.isArray(category.items) ? category.items : [];
    });
    if (!selectedCategoryName && categories.length > 0) {
      selectedCategoryName = String(categories[0].name || '');
    }
    normalizeItemOrder();
    renderBoard();
    setStatus(`Loaded ${categories.length} categories for ${menuType}.`, false);
  }

  container.querySelector('#mdRefresh').addEventListener('click', async () => {
    try {
      await loadDesigner();
    } catch (error) {
      setStatus(error.message || 'Refresh failed.', true);
    }
  });

  menuTypeNode.addEventListener('change', async () => {
    try {
      await loadDesigner();
    } catch (error) {
      setStatus(error.message || 'Failed to switch menu.', true);
    }
  });

  filterNode.addEventListener('input', () => {
    const selectedVisible = filteredCategories().some((category) => category.name === selectedCategoryName);
    if (!selectedVisible) {
      const first = filteredCategories()[0];
      selectedCategoryName = first ? String(first.name || '') : '';
    }
    renderBoard();
  });

  container.querySelector('#mdSaveCategoryOrder').addEventListener('click', async () => {
    try {
      const categoryNames = categories.map((cat) => cat.name);
      const payload = await adminApi('admin_menu_designer_save_category_order', {
        method: 'POST',
        body: {
          menuType: menuTypeNode.value,
          categories: categoryNames,
        },
      });
      setStatus(`Saved category order. Updated: ${payload.updated || 0}`, false);
      await loadDesigner();
    } catch (error) {
      setStatus(error.message || 'Failed to save category order.', true);
    }
  });

  container.querySelector('#mdSaveItemOrder').addEventListener('click', async () => {
    try {
      const items = [];
      categories.forEach((category, cIdx) => {
        category.items.forEach((item, iIdx) => {
          items.push({
            id: item.id,
            category: category.name,
            categorySortOrder: cIdx,
            itemSortOrder: iIdx,
          });
        });
      });

      const payload = await adminApi('admin_menu_designer_save_item_order', {
        method: 'POST',
        body: {
          menuType: menuTypeNode.value,
          items,
        },
      });
      setStatus(`Saved item order. Updated: ${payload.updated || 0}`, false);
      await loadDesigner();
    } catch (error) {
      setStatus(error.message || 'Failed to save item order.', true);
    }
  });

  try {
    await loadDesigner();
  } catch (error) {
    setStatus(error.message || 'Unable to initialize designer.', true);
  }
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
