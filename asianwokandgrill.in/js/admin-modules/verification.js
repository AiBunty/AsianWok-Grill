import { adminApi } from './api-client.js';

export async function renderVerification(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Coupon Verification</h3>
      <div class="admin-form-grid admin-form-grid-inline">
        <input id="vfPhone" type="tel" placeholder="Enter 10-digit phone" maxlength="10" />
        <button id="vfCheck" class="admin-button" type="button">Check</button>
      </div>
      <div class="admin-form-grid admin-form-grid-inline" style="margin-top:10px;">
        <input id="vfSurpriseLabel" type="text" placeholder="Surprise reward label (optional)" />
        <button id="vfIssueSurprise" class="admin-button admin-button-secondary" type="button">Issue Surprise</button>
      </div>
      <div id="vfStatus" class="admin-form-status">Awaiting input.</div>
      <div id="vfLead"></div>
      <div style="margin-top:12px;">
        <h4>Recent Verification History</h4>
        <div id="vfHistory"></div>
      </div>
    </section>
  `;

  const phone = container.querySelector('#vfPhone');
  const checkBtn = container.querySelector('#vfCheck');
  const status = container.querySelector('#vfStatus');
  const leadNode = container.querySelector('#vfLead');
  const historyNode = container.querySelector('#vfHistory');
  const surpriseLabelNode = container.querySelector('#vfSurpriseLabel');

  let activeLead = null;
  let history = loadHistory();

  function renderHistory() {
    if (!history.length) {
      historyNode.innerHTML = '<div class="admin-module-empty">No verification activity yet.</div>';
      return;
    }

    historyNode.innerHTML = `
      <div class="admin-module-table-wrap">
        <table class="admin-module-table">
          <thead><tr><th>Time</th><th>Phone</th><th>Name</th><th>Reward</th><th>Status</th></tr></thead>
          <tbody>
            ${history.slice(0, 20).map((row) => `
              <tr>
                <td>${escapeHtml(row.time)}</td>
                <td>${escapeHtml(row.phone)}</td>
                <td>${escapeHtml(row.name)}</td>
                <td>${escapeHtml(row.reward)}</td>
                <td>${escapeHtml(row.status)}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  function addHistory(lead) {
    history.unshift({
      time: new Date().toLocaleString(),
      phone: String(lead.phone || '-'),
      name: String(lead.name || '-'),
      reward: String(lead.activeRewardLabel || '-'),
      status: String(lead.status || '-'),
    });
    history = history.slice(0, 50);
    saveHistory(history);
    renderHistory();
  }

  checkBtn.addEventListener('click', async () => {
    const value = String(phone.value || '').replace(/\D/g, '');
    if (value.length !== 10) {
      status.textContent = 'Please enter a valid 10-digit phone number.';
      status.classList.add('error');
      leadNode.innerHTML = '';
      activeLead = null;
      return;
    }

    status.textContent = 'Checking...';
    status.classList.remove('error');
    leadNode.innerHTML = '';

    try {
      const payload = await adminApi('admin_verify_phone', { query: { phone: value } });
      activeLead = payload.lead;
      status.textContent = 'Lead found.';
      leadNode.innerHTML = buildLeadCard(activeLead);
      addHistory(activeLead);
      bindActions();
    } catch (error) {
      activeLead = null;
      status.textContent = error.message;
      status.classList.add('error');
      leadNode.innerHTML = '';
    }
  });

  function bindActions() {
    const redeemBtn = container.querySelector('#vfRedeem');
    const surpriseBtn = container.querySelector('#vfSurprise');

    redeemBtn?.addEventListener('click', async () => {
      try {
        await adminApi('admin_redeem_coupon', {
          method: 'POST',
          body: { leadId: activeLead.id },
        });
        status.textContent = 'Coupon redeemed successfully.';
        checkBtn.click();
      } catch (error) {
        status.textContent = error.message;
        status.classList.add('error');
      }
    });

    surpriseBtn?.addEventListener('click', async () => {
      const rewardLabel = window.prompt('Enter surprise reward label', 'Dessert on the House');
      if (!rewardLabel) return;

      try {
        await adminApi('admin_issue_surprise_coupon', {
          method: 'POST',
          body: { leadId: activeLead.id, rewardLabel },
        });
        status.textContent = 'Surprise coupon issued.';
        checkBtn.click();
      } catch (error) {
        status.textContent = error.message;
        status.classList.add('error');
      }
    });
  }

  container.querySelector('#vfIssueSurprise')?.addEventListener('click', async () => {
    if (!activeLead) {
      status.textContent = 'Verify a lead first.';
      status.classList.add('error');
      return;
    }

    const rewardLabel = String(surpriseLabelNode.value || '').trim() || window.prompt('Enter surprise reward label', 'Dessert on the House');
    if (!rewardLabel) {
      return;
    }

    try {
      await adminApi('admin_issue_surprise_coupon', {
        method: 'POST',
        body: { leadId: activeLead.id, rewardLabel },
      });
      status.textContent = 'Surprise coupon issued.';
      status.classList.remove('error');
      checkBtn.click();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  renderHistory();
}

function buildLeadCard(lead) {
  return `
    <div class="admin-module-card">
      <div class="admin-detail-grid">
        <div><span>Name</span><strong>${escapeHtml(lead.name)}</strong></div>
        <div><span>Phone</span><strong>${escapeHtml(lead.phone)}</strong></div>
        <div><span>Reward</span><strong>${escapeHtml(lead.activeRewardLabel || '-')}</strong></div>
        <div><span>Coupon</span><strong>${escapeHtml(lead.couponCode || '-')}</strong></div>
        <div><span>Status</span><strong>${escapeHtml(lead.status)}</strong></div>
        <div><span>Source</span><strong>${escapeHtml(lead.source || '-')}</strong></div>
      </div>
      <div class="admin-form-actions">
        ${lead.canRedeem ? '<button id="vfRedeem" class="admin-button" type="button">Redeem Coupon</button>' : ''}
        ${lead.canIssueSurprise ? '<button id="vfSurprise" class="admin-button admin-button-secondary" type="button">Issue Surprise Coupon</button>' : ''}
      </div>
    </div>
  `;
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function loadHistory() {
  try {
    const raw = localStorage.getItem('awg:verification:history');
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function saveHistory(history) {
  localStorage.setItem('awg:verification:history', JSON.stringify(history));
}
