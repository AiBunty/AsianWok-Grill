import { adminApi } from './api-client.js';

export async function renderCashApprovals(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Superadmin Cash Approvals</h3>
      <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:12px;">
        <label>
          Ledger date
          <input id="cashApproveLedgerDate" type="date" class="admin-input" />
        </label>
        <button id="cashApproveRefresh" class="admin-button admin-button-secondary" type="button">Refresh</button>
      </div>

      <div class="admin-module-item" style="margin-bottom:8px;">
        <div><strong>Pending Handovers</strong><div id="cashApproveHandoverCount">0</div></div>
        <div><strong>Pending Cancels</strong><div id="cashApproveCancelCount">0</div></div>
        <div><strong>Approved Today</strong><div id="cashApproveTodayCount">0</div></div>
      </div>

      <div id="cashApproveStatus" class="admin-form-status">Loading approvals dashboard...</div>

      <div style="margin-top:12px;">
        <h4>Pending Handover Requests</h4>
        <div id="cashApproveHandoverTable"></div>
      </div>

      <div style="margin-top:12px;">
        <h4>Pending Cancel Requests</h4>
        <div id="cashApproveCancelTable"></div>
      </div>

      <div style="margin-top:12px;">
        <h4>Recent Approved Handovers</h4>
        <div id="cashApproveRecentTable"></div>
      </div>
    </section>
  `;

  const ledgerDateInput = container.querySelector('#cashApproveLedgerDate');
  const refreshBtn = container.querySelector('#cashApproveRefresh');
  const statusNode = container.querySelector('#cashApproveStatus');
  const handoverTableNode = container.querySelector('#cashApproveHandoverTable');
  const cancelTableNode = container.querySelector('#cashApproveCancelTable');
  const recentTableNode = container.querySelector('#cashApproveRecentTable');

  const handoverCountNode = container.querySelector('#cashApproveHandoverCount');
  const cancelCountNode = container.querySelector('#cashApproveCancelCount');
  const todayCountNode = container.querySelector('#cashApproveTodayCount');

  ledgerDateInput.value = todayIso();

  async function loadDashboard() {
    const payload = await adminApi('superadmin_cash_dashboard', {
      query: {
        ledgerDate: ledgerDateInput.value,
      },
    });

    const summary = payload.summary || {};
    handoverCountNode.textContent = String(summary.pendingHandoverCount || 0);
    cancelCountNode.textContent = String(summary.pendingCancelCount || 0);
    todayCountNode.textContent = String(summary.approvedTodayCount || 0);

    const pendingHandovers = Array.isArray(payload.pendingHandovers) ? payload.pendingHandovers : [];
    const pendingCancels = Array.isArray(payload.pendingCancelRequests) ? payload.pendingCancelRequests : [];
    const recentApprovals = Array.isArray(payload.recentApprovals) ? payload.recentApprovals : [];

    handoverTableNode.innerHTML = renderPendingHandovers(pendingHandovers);
    cancelTableNode.innerHTML = renderPendingCancels(pendingCancels);
    recentTableNode.innerHTML = renderRecentApprovals(recentApprovals);

    bindHandoverApproveButtons();
    bindCancelResolveButtons();
  }

  refreshBtn.addEventListener('click', () => {
    loadDashboard()
      .then(() => setStatus('Cash approvals refreshed.', false))
      .catch((error) => setStatus(error.message || 'Failed to refresh approvals.', true));
  });

  ledgerDateInput.addEventListener('change', () => {
    loadDashboard()
      .then(() => setStatus('Ledger date updated.', false))
      .catch((error) => setStatus(error.message || 'Failed to refresh approvals.', true));
  });

  function bindHandoverApproveButtons() {
    Array.from(container.querySelectorAll('[data-approve-handover]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const batchKey = button.dataset.approveHandover;
        const adminUsername = button.dataset.adminUsername || '';
        const ledgerDate = button.dataset.ledgerDate || ledgerDateInput.value;
        if (!batchKey) {
          return;
        }

        setStatus('Approving handover...');
        try {
          await adminApi('superadmin_approve_cash_handover', {
            method: 'POST',
            body: {
              batchKey,
              adminUsername,
              ledgerDate,
            },
          });
          setStatus('Handover approved.', false);
          await loadDashboard();
        } catch (error) {
          setStatus(error.message || 'Failed to approve handover.', true);
        }
      });
    });
  }

  function bindCancelResolveButtons() {
    Array.from(container.querySelectorAll('[data-resolve-cancel]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const transactionId = button.dataset.resolveCancel;
        const decision = button.dataset.decision || 'reject';
        if (!transactionId) {
          return;
        }

        const note = window.prompt(
          decision === 'approve' ? 'Approval note for cancel:' : 'Reason for rejection:',
          decision === 'approve' ? 'Approved by superadmin' : 'Rejected by superadmin'
        );

        if (!note) {
          return;
        }

        setStatus('Resolving cancel request...');
        try {
          await adminApi('superadmin_resolve_cash_cancel', {
            method: 'POST',
            body: {
              transactionId,
              decision,
              note,
            },
          });
          setStatus(`Cancel request ${decision === 'approve' ? 'approved' : 'rejected'}.`, false);
          await loadDashboard();
        } catch (error) {
          setStatus(error.message || 'Failed to resolve cancel request.', true);
        }
      });
    });
  }

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  try {
    await loadDashboard();
    setStatus('Cash approvals module ready.', false);
  } catch (error) {
    setStatus(error.message || 'Failed to load cash approvals module.', true);
  }
}

function renderPendingHandovers(rows) {
  if (!rows.length) {
    return '<div class="admin-module-empty">No pending handover requests.</div>';
  }

  return `
    <table class="admin-table" style="width:100%;">
      <thead>
        <tr>
          <th>Cashier</th>
          <th>Ledger Date</th>
          <th>Amount</th>
          <th>Transactions</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.adminUsername || '-')}</td>
            <td>${escapeHtml(row.ledgerDate || '-')}</td>
            <td>${formatCurrency(row.amount || 0)}</td>
            <td>${Array.isArray(row.transactionIds) ? row.transactionIds.length : 0}</td>
            <td>
              <button
                class="admin-button"
                type="button"
                data-approve-handover="${escapeHtml(row.batchKey || '')}"
                data-admin-username="${escapeHtml(row.adminUsername || '')}"
                data-ledger-date="${escapeHtml(row.ledgerDate || '')}">
                Approve Handover
              </button>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;
}

function renderPendingCancels(rows) {
  if (!rows.length) {
    return '<div class="admin-module-empty">No pending cancel requests.</div>';
  }

  return `
    <table class="admin-table" style="width:100%;">
      <thead>
        <tr>
          <th>Transaction</th>
          <th>Cashier</th>
          <th>Reason</th>
          <th>Amount</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.transactionId || '-')}</td>
            <td>${escapeHtml(row.adminUsername || '-')}</td>
            <td>${escapeHtml(row.reason || '-')}</td>
            <td>${formatCurrency(row.amount || 0)}</td>
            <td>
              <button class="admin-button" type="button" data-resolve-cancel="${escapeHtml(row.transactionId || '')}" data-decision="approve">Approve</button>
              <button class="admin-button admin-button-secondary" type="button" data-resolve-cancel="${escapeHtml(row.transactionId || '')}" data-decision="reject">Reject</button>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;
}

function renderRecentApprovals(rows) {
  if (!rows.length) {
    return '<div class="admin-module-empty">No recent approved handovers.</div>';
  }

  return `
    <table class="admin-table" style="width:100%;">
      <thead>
        <tr>
          <th>Cashier</th>
          <th>Ledger Date</th>
          <th>Amount</th>
          <th>Resolved By</th>
          <th>Resolved At</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.adminUsername || '-')}</td>
            <td>${escapeHtml(row.ledgerDate || '-')}</td>
            <td>${formatCurrency(row.amount || 0)}</td>
            <td>${escapeHtml(row.resolvedByName || '-')}</td>
            <td>${formatDateTime(row.resolvedAt || '')}</td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;
}

function todayIso() {
  const now = new Date();
  const yyyy = now.getFullYear();
  const mm = String(now.getMonth() + 1).padStart(2, '0');
  const dd = String(now.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

function formatCurrency(value) {
  const amount = Number(value || 0);
  return `Rs ${amount.toFixed(2)}`;
}

function formatDateTime(value) {
  if (!value) {
    return '-';
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return escapeHtml(String(value));
  }

  return parsed.toLocaleString();
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
