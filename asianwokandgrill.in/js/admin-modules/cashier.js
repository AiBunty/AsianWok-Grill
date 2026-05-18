import { adminApi } from './api-client.js';

export async function renderCashier(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Cashier Desk</h3>
      <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:12px;">
        <label>
          Event
          <select id="cashierEventSelect" class="admin-input"></select>
        </label>
        <label>
          Ledger date
          <input id="cashierLedgerDate" type="date" class="admin-input" />
        </label>
      </div>
      <div class="admin-module-item" style="margin-bottom:8px;">
        <div><strong>Today Sales</strong><div id="cashSummarySales">0</div></div>
        <div><strong>Visitors</strong><div id="cashSummaryVisitors">0</div></div>
        <div><strong>Pending Handover</strong><div id="cashSummaryHandover">0</div></div>
        <div><strong>Pending Cancel</strong><div id="cashSummaryCancel">0</div></div>
      </div>

      <form id="cashIssueForm" class="admin-form-grid" style="margin-top:12px;">
        <h4>Issue Paid Pass</h4>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="cashCustomerName" type="text" placeholder="Customer name" required />
          <input id="cashCustomerPhone" type="tel" placeholder="Customer phone" required />
        </div>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="cashCustomerEmail" type="email" placeholder="Customer email (optional)" />
          <input id="cashQty" type="number" min="1" value="1" placeholder="Qty" required />
        </div>
        <textarea id="cashAttendees" rows="2" placeholder="Attendee names (comma or new line)"></textarea>
        <textarea id="cashNotes" rows="2" placeholder="Notes"></textarea>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Issue Cash Pass</button>
          <button id="cashHandoverBtn" class="admin-button admin-button-secondary" type="button">Request Handover</button>
        </div>
      </form>

      <div id="cashierStatus" class="admin-form-status">Loading cashier data...</div>

      <div class="admin-module-card" style="padding:0; margin-top:12px; border:none;">
        <h4 style="margin:0 0 8px 0;">Transactions</h4>
        <div id="cashTxTable"></div>
      </div>

      <div class="admin-module-card" style="padding:0; margin-top:12px; border:none;">
        <h4 style="margin:0 0 8px 0;">Handover History</h4>
        <div id="cashHandoverHistory"></div>
      </div>
    </section>
  `;

  const eventSelect = container.querySelector('#cashierEventSelect');
  const ledgerDateInput = container.querySelector('#cashierLedgerDate');
  const issueForm = container.querySelector('#cashIssueForm');
  const handoverBtn = container.querySelector('#cashHandoverBtn');
  const statusNode = container.querySelector('#cashierStatus');
  const txTableNode = container.querySelector('#cashTxTable');
  const handoverHistoryNode = container.querySelector('#cashHandoverHistory');

  const salesNode = container.querySelector('#cashSummarySales');
  const visitorsNode = container.querySelector('#cashSummaryVisitors');
  const handoverNode = container.querySelector('#cashSummaryHandover');
  const cancelNode = container.querySelector('#cashSummaryCancel');

  ledgerDateInput.value = todayIso();

  async function loadEvents() {
    const payload = await adminApi('admin_list_events');
    const events = Array.isArray(payload.events) ? payload.events.filter((event) => event && event.id) : [];

    if (!events.length) {
      eventSelect.innerHTML = '<option value="">No events available</option>';
      return;
    }

    eventSelect.innerHTML = events.map((event) => {
      const dateLabel = event.date ? ` (${escapeHtml(event.date)})` : '';
      return `<option value="${escapeHtml(event.id)}">${escapeHtml(event.title || event.id)}${dateLabel}</option>`;
    }).join('');
  }

  async function loadSummary() {
    const payload = await adminApi('admin_cash_summary', {
      query: {
        eventId: eventSelect.value,
        ledgerDate: ledgerDateInput.value,
      },
    });

    const summary = payload.summary || {};
    salesNode.textContent = formatCurrency(summary.todaySales || 0);
    visitorsNode.textContent = String(summary.activeVisitors || 0);
    handoverNode.textContent = formatCurrency(summary.pendingHandoverAmount || 0);
    cancelNode.textContent = String(summary.pendingCancelCount || 0);

    const transactions = Array.isArray(payload.recentTransactions) ? payload.recentTransactions : [];
    const handoverHistory = Array.isArray(payload.handoverHistory) ? payload.handoverHistory : [];

    txTableNode.innerHTML = renderTransactions(transactions);
    handoverHistoryNode.innerHTML = renderHandovers(handoverHistory);

    bindCancelButtons(transactions);
  }

  issueForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setStatus('Saving cash pass...');

    try {
      await adminApi('admin_issue_cash_paid_pass', {
        method: 'POST',
        body: {
          eventId: eventSelect.value,
          ledgerDate: ledgerDateInput.value,
          customerName: container.querySelector('#cashCustomerName').value.trim(),
          customerPhone: container.querySelector('#cashCustomerPhone').value.trim(),
          customerEmail: container.querySelector('#cashCustomerEmail').value.trim(),
          qty: Number(container.querySelector('#cashQty').value || 1),
          attendeeNames: container.querySelector('#cashAttendees').value,
          notes: container.querySelector('#cashNotes').value,
        },
      });

      issueForm.reset();
      container.querySelector('#cashQty').value = '1';
      setStatus('Cash pass issued successfully.', false);
      await loadSummary();
    } catch (error) {
      setStatus(error.message || 'Failed to issue cash pass.', true);
    }
  });

  handoverBtn.addEventListener('click', async () => {
    setStatus('Submitting handover request...');
    try {
      await adminApi('admin_request_cash_handover', {
        method: 'POST',
        body: {
          eventId: eventSelect.value,
          ledgerDate: ledgerDateInput.value,
        },
      });
      setStatus('Cash handover request submitted.', false);
      await loadSummary();
    } catch (error) {
      setStatus(error.message || 'Failed to request handover.', true);
    }
  });

  eventSelect.addEventListener('change', () => {
    loadSummary().catch((error) => setStatus(error.message || 'Failed to refresh cashier summary.', true));
  });

  ledgerDateInput.addEventListener('change', () => {
    loadSummary().catch((error) => setStatus(error.message || 'Failed to refresh cashier summary.', true));
  });

  function bindCancelButtons(transactions) {
    Array.from(container.querySelectorAll('[data-cancel-transaction]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const transactionId = button.dataset.cancelTransaction;
        if (!transactionId) {
          return;
        }

        const target = transactions.find((row) => row.transactionId === transactionId);
        if (!target) {
          return;
        }

        const reason = window.prompt('Reason for cancel request:', target.notes || 'Customer requested cancel');
        if (!reason) {
          return;
        }

        setStatus('Submitting cancel request...');
        try {
          await adminApi('admin_request_cash_cancel', {
            method: 'POST',
            body: {
              transactionId,
              reason,
              ledgerDate: ledgerDateInput.value,
            },
          });
          setStatus('Cancel request sent to superadmin.', false);
          await loadSummary();
        } catch (error) {
          setStatus(error.message || 'Failed to submit cancel request.', true);
        }
      });
    });
  }

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  try {
    await loadEvents();
    await loadSummary();
    setStatus('Cashier module ready.', false);
  } catch (error) {
    setStatus(error.message || 'Failed to load cashier module.', true);
  }
}

function renderTransactions(rows) {
  if (!rows.length) {
    return '<div class="admin-module-empty">No transactions found for this ledger date.</div>';
  }

  return `
    <table class="admin-table" style="width:100%;">
      <thead>
        <tr>
          <th>Time</th>
          <th>Customer</th>
          <th>Qty</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map((row) => {
          const createdAt = formatDateTime(row.createdAt);
          const customer = escapeHtml(row.customerName || '-');
          const qty = Number(row.qty || 0);
          const amount = formatCurrency(row.amount || 0);
          const status = row.status === 'cancelled' ? 'Cancelled' : 'Active';
          const cancelState = row.cancelStatus || 'none';
          const canCancel = row.status !== 'cancelled' && cancelState === 'none';

          return `
            <tr>
              <td>${createdAt}</td>
              <td>${customer}</td>
              <td>${qty}</td>
              <td>${amount}</td>
              <td>${escapeHtml(status)}${cancelState !== 'none' ? ` / ${escapeHtml(cancelState)}` : ''}</td>
              <td>
                ${canCancel ? `<button class="admin-button admin-button-secondary" type="button" data-cancel-transaction="${escapeHtml(row.transactionId || '')}">Request Cancel</button>` : '-'}
              </td>
            </tr>
          `;
        }).join('')}
      </tbody>
    </table>
  `;
}

function renderHandovers(rows) {
  if (!rows.length) {
    return '<div class="admin-module-empty">No handover history yet.</div>';
  }

  return `
    <table class="admin-table" style="width:100%;">
      <thead>
        <tr>
          <th>Ledger Date</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Resolved By</th>
          <th>Resolved At</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.ledgerDate || '-')}</td>
            <td>${formatCurrency(row.amount || 0)}</td>
            <td>${escapeHtml(row.status || '-')}</td>
            <td>${escapeHtml(row.resolvedByName || '-')}</td>
            <td>${formatDateTime(row.resolvedAt || row.requestedAt)}</td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;
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

function todayIso() {
  const now = new Date();
  const yyyy = now.getFullYear();
  const mm = String(now.getMonth() + 1).padStart(2, '0');
  const dd = String(now.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
