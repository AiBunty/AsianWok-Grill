import { adminApi } from './api-client.js';

export async function renderSpinOfferControl(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Spin Wheel Offer Control</h3>
      <p class="admin-module-empty" style="margin-bottom:12px;">
        Weight controls probability. Higher weight = more chance to win.
      </p>
      <div class="admin-module-item" style="margin-bottom:8px;">
        <div><strong>How Weight Works</strong><div id="spinWeightHelp">Loading...</div></div>
      </div>
      <form id="spinOfferForm" class="admin-form-grid">
        <input id="spinOfferId" type="hidden" />
        <input id="spinOfferLabel" type="text" placeholder="Offer label (e.g., Free Mocktail)" required />
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="spinOfferWeight" type="number" min="0.01" step="0.01" placeholder="Weight" required />
          <input id="spinOfferPrefix" type="text" placeholder="Coupon prefix (MOCK)" />
        </div>
        <div class="admin-form-grid admin-form-grid-inline">
          <label class="admin-checkbox"><input id="spinOfferCoupon" type="checkbox" checked /> Generate coupon</label>
          <label class="admin-checkbox"><input id="spinOfferActive" type="checkbox" checked /> Active</label>
        </div>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="spinOfferColor" type="text" placeholder="#C7A46B" />
        </div>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Add / Update Offer</button>
          <button id="spinOfferReset" class="admin-button admin-button-secondary" type="button">Reset</button>
          <button id="spinOfferSaveAll" class="admin-button admin-button-secondary" type="button">Save Offer Model</button>
        </div>
      </form>
      <div id="spinStatus" class="admin-form-status">Loading spin offers...</div>
      <div id="spinChart"></div>
      <div id="spinOfferList"></div>
    </section>
  `;

  const spinStatus = container.querySelector('#spinStatus');
  const spinChartNode = container.querySelector('#spinChart');
  const spinOfferListNode = container.querySelector('#spinOfferList');
  const spinOfferForm = container.querySelector('#spinOfferForm');
  const spinWeightHelp = container.querySelector('#spinWeightHelp');

  let spinOffers = [];

  function setStatus(message, isError = false) {
    spinStatus.textContent = message;
    spinStatus.classList.toggle('error', Boolean(isError));
  }

  async function loadSpinOffers() {
    try {
      const payload = await adminApi('auth_get_spin_offers');
      spinOffers = Array.isArray(payload.offers) ? payload.offers : [];
      setStatus(`${spinOffers.length} active offer(s) configured.`, false);
      spinChartNode.innerHTML = buildSpinChart(payload.chart || []);
      spinOfferListNode.innerHTML = buildSpinTable(spinOffers);
      bindSpinRowActions();
      setWeightHelp(spinOffers);
    } catch (error) {
      setStatus(error.message || 'Failed to load spin offers.', true);
      spinChartNode.innerHTML = '';
      spinOfferListNode.innerHTML = '';
      spinWeightHelp.textContent = 'Unable to load weight model.';
      spinOffers = [];
    }
  }

  spinOfferForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const id = container.querySelector('#spinOfferId').value || `off_${Math.random().toString(16).slice(2, 10)}`;
    const offer = {
      id,
      label: container.querySelector('#spinOfferLabel').value.trim(),
      weight: Number(container.querySelector('#spinOfferWeight').value || 0),
      hasCoupon: container.querySelector('#spinOfferCoupon').checked,
      couponPrefix: container.querySelector('#spinOfferPrefix').value.trim() || 'AWG',
      color: container.querySelector('#spinOfferColor').value.trim() || '#C7A46B',
      isActive: container.querySelector('#spinOfferActive').checked,
    };

    if (!offer.label || offer.weight <= 0) {
      setStatus('Offer label and a weight greater than zero are required.', true);
      return;
    }

    const index = spinOffers.findIndex((item) => String(item.id) === String(id));
    if (index >= 0) {
      spinOffers[index] = offer;
    } else {
      spinOffers.push(offer);
    }

    setStatus('Offer staged locally. Click "Save Offer Model" to publish.', false);
    spinChartNode.innerHTML = buildSpinChart(toChart(spinOffers.filter((item) => item.isActive !== false)));
    spinOfferListNode.innerHTML = buildSpinTable(spinOffers);
    bindSpinRowActions();
    setWeightHelp(spinOffers);
    resetSpinForm();
  });

  container.querySelector('#spinOfferReset')?.addEventListener('click', () => {
    resetSpinForm();
  });

  container.querySelector('#spinOfferSaveAll')?.addEventListener('click', async () => {
    try {
      await adminApi('auth_set_spin_offers', { method: 'POST', body: { offers: spinOffers } });
      setStatus('Spin offer model saved successfully.', false);
      await loadSpinOffers();
    } catch (error) {
      setStatus(error.message || 'Failed to save spin offers.', true);
    }
  });

  function bindSpinRowActions() {
    Array.from(container.querySelectorAll('[data-spin-edit]')).forEach((button) => {
      button.addEventListener('click', () => {
        const id = button.getAttribute('data-spin-edit') || '';
        const row = spinOffers.find((item) => String(item.id) === String(id));
        if (!row) {
          return;
        }

        container.querySelector('#spinOfferId').value = row.id || '';
        container.querySelector('#spinOfferLabel').value = row.label || '';
        container.querySelector('#spinOfferWeight').value = String(row.weight || '');
        container.querySelector('#spinOfferCoupon').checked = !!row.hasCoupon;
        container.querySelector('#spinOfferPrefix').value = row.couponPrefix || '';
        container.querySelector('#spinOfferColor').value = row.color || '#C7A46B';
        container.querySelector('#spinOfferActive').checked = row.isActive !== false;
      });
    });

    Array.from(container.querySelectorAll('[data-spin-delete]')).forEach((button) => {
      button.addEventListener('click', () => {
        const id = button.getAttribute('data-spin-delete') || '';
        spinOffers = spinOffers.filter((item) => String(item.id) !== String(id));
        setStatus('Offer removed locally. Click "Save Offer Model" to publish.', false);
        spinChartNode.innerHTML = buildSpinChart(toChart(spinOffers.filter((item) => item.isActive !== false)));
        spinOfferListNode.innerHTML = buildSpinTable(spinOffers);
        bindSpinRowActions();
        setWeightHelp(spinOffers);
      });
    });
  }

  function setWeightHelp(offers) {
    const active = offers.filter((item) => item.isActive !== false && Number(item.weight || 0) > 0);
    const total = active.reduce((carry, item) => carry + Number(item.weight || 0), 0);
    if (!active.length || total <= 0) {
      spinWeightHelp.textContent = 'No active weighted offers yet.';
      return;
    }

    const preview = active.slice(0, 3).map((item) => {
      const chance = ((Number(item.weight || 0) / total) * 100).toFixed(2);
      return `${item.label}: ${chance}%`;
    }).join(' | ');

    spinWeightHelp.textContent = `Total weight: ${total.toFixed(2)}. Chance = weight / total. ${preview}`;
  }

  function resetSpinForm() {
    spinOfferForm.reset();
    container.querySelector('#spinOfferId').value = '';
    container.querySelector('#spinOfferCoupon').checked = true;
    container.querySelector('#spinOfferActive').checked = true;
    container.querySelector('#spinOfferColor').value = '#C7A46B';
  }

  await loadSpinOffers();
}

function buildSpinTable(offers) {
  if (!offers.length) {
    return '<div class="admin-module-empty">No offers configured.</div>';
  }

  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>Offer</th><th>Weight</th><th>Coupon</th><th>Prefix</th><th>Color</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          ${offers.map((offer) => `
            <tr>
              <td>${escapeHtml(offer.label)}</td>
              <td>${escapeHtml(offer.weight)}</td>
              <td>${offer.hasCoupon ? 'Yes' : 'No'}</td>
              <td>${escapeHtml(offer.couponPrefix || '-')}</td>
              <td><span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:${escapeHtml(offer.color || '#C7A46B')};border:1px solid rgba(255,255,255,0.3);"></span> ${escapeHtml(offer.color || '#C7A46B')}</td>
              <td>${offer.isActive === false ? 'Inactive' : 'Active'}</td>
              <td>
                <button class="admin-table-button" data-spin-edit="${escapeHtml(offer.id)}">Edit</button>
                <button class="admin-table-button admin-table-button-danger" data-spin-delete="${escapeHtml(offer.id)}">Delete</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function buildSpinChart(chartRows) {
  if (!chartRows.length) {
    return '<div class="admin-module-empty">Chart unavailable until offers are added.</div>';
  }

  return `
    <div style="display:grid;gap:8px;margin:12px 0;">
      ${chartRows.map((item) => `
        <div style="display:grid;grid-template-columns:220px 1fr 70px;gap:10px;align-items:center;">
          <strong style="font-size:0.88rem;color:#f3eadc;">${escapeHtml(item.label)}</strong>
          <div style="height:12px;border-radius:999px;background:rgba(255,255,255,0.08);overflow:hidden;">
            <div style="height:100%;width:${Math.max(2, Number(item.percentage || 0))}%;background:${escapeHtml(item.color || '#C7A46B')};"></div>
          </div>
          <span style="font-size:0.82rem;color:#e4cda2;text-align:right;">${escapeHtml(item.percentage)}%</span>
        </div>
      `).join('')}
    </div>
  `;
}

function toChart(offers) {
  const total = offers.reduce((carry, item) => carry + Number(item.weight || 0), 0);
  return offers.map((item) => ({
    label: item.label,
    weight: Number(item.weight || 0),
    percentage: total > 0 ? ((Number(item.weight || 0) / total) * 100).toFixed(2) : '0.00',
    color: item.color || '#C7A46B',
  }));
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
