import { adminApi } from './api-client.js';

export async function renderEventGuests(container) {
  container.innerHTML = `
    <section class="admin-module-card eg-page">
      <header class="eg-header">
        <div>
          <h3>Event Guests</h3>
          <p>Registration and guest reports</p>
        </div>
      </header>

      <section class="eg-toolbar">
        <div class="eg-toolbar-controls">
          <select id="egEvent" class="admin-input"></select>
          <select id="egStatusFilter" class="admin-input">
            <option value="all">All Guests</option>
            <option value="registered">Registered</option>
            <option value="checked-in">Checked-in</option>
            <option value="pending">Pending</option>
          </select>
          <button id="egRefresh" class="admin-button admin-button-secondary" type="button">Refresh</button>
        </div>
        <div id="egStatus" class="admin-form-status">Loading report...</div>
      </section>

      <section class="eg-metric-grid" id="egMetricGrid"></section>

      <section class="eg-panel">
        <div class="eg-panel-head">
          <h4>QR-wise Check-In Summary</h4>
          <span id="egQrCount">0 row(s)</span>
        </div>
        <div id="egQrSummaryTable"></div>
      </section>

      <section class="eg-panel">
        <div class="eg-panel-head">
          <h4>Guests</h4>
          <div class="admin-form-actions" style="margin:0;">
            <button id="egExportCsv" class="admin-button admin-button-secondary" type="button">Export CSV (Filtered)</button>
            <button id="egExportXls" class="admin-button admin-button-secondary" type="button">Export XLS (All)</button>
            <button id="egClearFilters" class="admin-button admin-button-secondary" type="button">Clear Filters</button>
          </div>
        </div>
        <input id="egGuestSearch" type="text" placeholder="Search all columns..." />
        <div id="egGuestTable"></div>
      </section>

      <section class="eg-panel">
        <div class="eg-panel-head">
          <h4>Mail Support Logs</h4>
          <div class="eg-toolbar-controls" style="gap:8px;">
            <select id="egMailDateFilter" class="admin-input"></select>
            <button id="egMailRefresh" class="admin-button admin-button-secondary" type="button">Refresh Logs</button>
          </div>
        </div>

        <section class="eg-mini-metric-grid" id="egMailMetricGrid"></section>
        <div id="egMailTable"></div>
      </section>

      <section class="eg-panel">
        <div class="eg-panel-head">
          <h4>Razorpay Reconciliation</h4>
          <span id="egReconCount">0 row(s)</span>
        </div>
        <input id="egReconSearch" type="text" placeholder="Search all columns..." />
        <div id="egReconTable"></div>
      </section>
    </section>
  `;

  const eventSelect = container.querySelector('#egEvent');
  const statusFilter = container.querySelector('#egStatusFilter');
  const refreshBtn = container.querySelector('#egRefresh');
  const mailRefreshBtn = container.querySelector('#egMailRefresh');
  const statusNode = container.querySelector('#egStatus');

  const metricGrid = container.querySelector('#egMetricGrid');
  const qrCountNode = container.querySelector('#egQrCount');
  const qrSummaryNode = container.querySelector('#egQrSummaryTable');

  const guestSearchInput = container.querySelector('#egGuestSearch');
  const guestTableNode = container.querySelector('#egGuestTable');
  const exportCsvBtn = container.querySelector('#egExportCsv');
  const exportXlsBtn = container.querySelector('#egExportXls');
  const clearFiltersBtn = container.querySelector('#egClearFilters');

  const mailDateFilter = container.querySelector('#egMailDateFilter');
  const mailMetricGrid = container.querySelector('#egMailMetricGrid');
  const mailTableNode = container.querySelector('#egMailTable');

  const reconSearchInput = container.querySelector('#egReconSearch');
  const reconCountNode = container.querySelector('#egReconCount');
  const reconTableNode = container.querySelector('#egReconTable');

  let guestRows = [];
  let filteredGuestRows = [];
  let mailRows = [];
  let filteredMailRows = [];
  let reconRows = [];
  let filteredReconRows = [];
  let guestSearchTimer = null;
  let reconSearchTimer = null;

  function setStatus(message, isError = false) {
    statusNode.textContent = message;
    statusNode.classList.toggle('error', Boolean(isError));
  }

  function buildMetrics(summary, mails, transactions) {
    const totalRegistrations = Number(summary.totalGuests || transactions.length || 0);
    const totalGuests = transactions.reduce((acc, row) => acc + Number(row.qty || 0), 0);
    const freeCount = transactions.filter((row) => Number(row.amount || 0) <= 0).length;
    const paidCount = transactions.filter((row) => Number(row.amount || 0) > 0).length;
    const checkedIn = transactions.reduce((acc, row) => acc + Number(row.checked_in_count || 0), 0);

    const sent = Number(mails.summary.total_sent || 0);
    const failed = Number(mails.summary.total_failed || 0);
    const pending = Math.max(0, totalRegistrations - sent - failed);

    const paidRows = transactions.filter((row) => Number(row.amount || 0) > 0);
    const revenue = paidRows.reduce((acc, row) => acc + Number(row.amount || 0), 0);
    const cashCollected = paidRows
      .filter((row) => String(row.gateway || '').toLowerCase().includes('cash') && isPaidStatus(row.status))
      .reduce((acc, row) => acc + Number(row.amount || 0), 0);
    const razorpayPending = paidRows
      .filter((row) => String(row.gateway || '').toLowerCase().includes('razorpay') && !isPaidStatus(row.status))
      .reduce((acc, row) => acc + Number(row.amount || 0), 0);

    const cards = [
      { label: 'registrations', value: totalRegistrations },
      { label: 'guests', value: totalGuests },
      { label: 'free', value: freeCount },
      { label: 'paid', value: paidCount },
      { label: 'checked in', value: checkedIn },
      { label: 'emails sent', value: sent },
      { label: 'email failed', value: failed },
      { label: 'email pending', value: pending },
      { label: 'razorpay collected', value: currencyInr(revenue) },
      { label: 'cash collected', value: currencyInr(cashCollected) },
      { label: 'razorpay pending', value: currencyInr(razorpayPending) },
    ];

    metricGrid.innerHTML = cards.map((card) => `
      <article class="eg-metric-card">
        <span>${escapeHtml(card.label)}</span>
        <strong>${escapeHtml(String(card.value))}</strong>
      </article>
    `).join('');
  }

  function applyGuestFilters() {
    const query = String(guestSearchInput.value || '').trim().toLowerCase();

    filteredGuestRows = guestRows.filter((row) => {
      if (!query) {
        return true;
      }
      const haystack = [
        row.event,
        row.guest,
        row.contact,
        row.type,
        row.tickets,
        row.attendees,
        row.status,
        row.registered,
        row.checkedIn,
      ].join(' ').toLowerCase();
      return haystack.includes(query);
    });

    guestTableNode.innerHTML = renderSimpleTable([
      'Event',
      'Guest',
      'Contact',
      'Type',
      'Tickets',
      'Attendees',
      'Status',
      'Registered',
      'Checked In',
    ], filteredGuestRows.map((row) => [
      row.event,
      row.guest,
      row.contact,
      row.type,
      row.tickets,
      row.attendees,
      row.status,
      row.registered,
      row.checkedIn,
    ]), 'No guest rows found.');
  }

  function applyMailFilters() {
    const day = String(mailDateFilter.value || 'all');

    filteredMailRows = mailRows.filter((row) => {
      if (day === 'all') {
        return true;
      }
      return String(row.dateKey || '') === day;
    });

    const attempts = filteredMailRows.length;
    const accepted = filteredMailRows.filter((row) => row.level === 'INFO').length;
    const failed = filteredMailRows.filter((row) => row.level === 'ERROR').length;
    const skipped = filteredMailRows.filter((row) => row.level === 'SKIPPED').length;

    mailMetricGrid.innerHTML = [
      { label: 'Attempts', value: attempts },
      { label: 'Accepted', value: accepted },
      { label: 'Failed', value: failed },
      { label: 'Skipped', value: skipped },
    ].map((card) => `
      <article class="eg-metric-card eg-metric-card-mini">
        <span>${escapeHtml(card.label)}</span>
        <strong>${escapeHtml(String(card.value))}</strong>
      </article>
    `).join('');

    mailTableNode.innerHTML = renderSimpleTable([
      'Time', 'Level', 'Kind', 'Recipient', 'Transaction', 'Message', 'Details'
    ], filteredMailRows.map((row) => [
      row.time,
      row.level,
      row.kind,
      row.recipient,
      row.transaction,
      row.message,
      row.details,
    ]), 'No mail log entries found.');
  }

  function applyReconFilters() {
    const query = String(reconSearchInput.value || '').trim().toLowerCase();

    filteredReconRows = reconRows.filter((row) => {
      if (!query) {
        return true;
      }
      const haystack = [
        row.event,
        row.guest,
        row.transaction,
        row.orderPayment,
        row.amount,
        row.status,
        row.refund,
        row.created,
        row.confirmed,
      ].join(' ').toLowerCase();
      return haystack.includes(query);
    });

    reconCountNode.textContent = `${filteredReconRows.length} row(s)`;

    reconTableNode.innerHTML = renderSimpleTable([
      'Event', 'Guest', 'Transaction', 'Order/Payment', 'Amount', 'Status', 'Refund', 'Created', 'Confirmed'
    ], filteredReconRows.map((row) => [
      row.event,
      row.guest,
      row.transaction,
      row.orderPayment,
      row.amount,
      row.status,
      row.refund,
      row.created,
      row.confirmed,
    ]), 'No reconciliation entries found.');
  }

  function buildQrSummaryTable(transactions) {
    qrCountNode.textContent = `${transactions.length} row(s)`;
    const rows = transactions.map((row) => {
      const qty = Number(row.qty || 0);
      const checked = Number(row.checked_in_count || 0);
      const remaining = Math.max(0, qty - checked);
      const attendees = attendeeNames(row);
      const history = checked > 0
        ? `Checked in: ${checked} at ${formatDateTime(row.checked_in_at)}`
        : '-';

      return [
        `${row.transaction_id || '-'}\n${row.qr_url ? 'Open verification link' : ''}`,
        row.event_title || '-',
        row.customer_name || '-',
        Number(row.amount || 0) > 0 ? 'Paid' : 'Free',
        `${checked} / ${qty}\nRemaining: ${remaining}`,
        `${row.status || '-'}\n${row.email_status || '-'}`,
        attendees,
        history,
      ];
    });

    qrSummaryNode.innerHTML = renderSimpleTable([
      'QR / Transaction',
      'Event',
      'Primary Guest',
      'Type',
      'Checked In',
      'Status',
      'Attendees',
      'History',
    ], rows, 'No transactions found for this event.');
  }

  function hydrateRows(transactions) {
    guestRows = transactions.map((row) => {
      const qty = Number(row.qty || 1);
      const checked = Number(row.checked_in_count || 0);
      const pending = Math.max(0, qty - checked);
      const isFree = Number(row.amount || 0) <= 0;
      const details = attendeeNames(row);
      return {
        event: row.event_title || '-',
        guest: row.customer_name || '-',
        contact: `${row.customer_email || '-'}\n${row.customer_phone || '-'}`,
        type: isFree ? 'Free' : 'Paid',
        tickets: qty,
        attendees: details,
        status: `${row.status || '-'}\nRemaining: ${pending}`,
        registered: formatDateTime(row.created_at),
        checkedIn: checked > 0 ? `${formatDateTime(row.checked_in_at)}\nChecked In: ${checked}` : '-',
        raw: row,
      };
    });

    reconRows = transactions
      .filter((row) => Number(row.amount || 0) > 0 || String(row.gateway || '').toLowerCase().includes('razorpay'))
      .map((row) => ({
        event: row.event_title || '-',
        guest: row.customer_name || '-',
        transaction: row.transaction_id || '-',
        orderPayment: `${row.order_id || '-'}\n${row.payment_id || '-'}`,
        amount: currencyInr(Number(row.amount || 0)),
        status: row.status || '-',
        refund: inferRefundState(row.status),
        created: formatDateTime(row.created_at),
        confirmed: formatDateTime(row.paid_at || row.checked_in_at),
      }));
  }

  function hydrateMailRows(logs) {
    mailRows = logs.map((row) => {
      const when = String(row.created_at || row.sent_at || '').trim();
      const dateKey = when ? when.slice(0, 10) : 'unknown';
      const status = String(row.status || '').toLowerCase();
      const level = status === 'sent' ? 'INFO' : status === 'failed' ? 'ERROR' : 'SKIPPED';
      const payload = parseJsonObject(row.payload_json);

      return {
        time: formatDateTime(when),
        level,
        kind: row.template || '-',
        recipient: row.recipient_email || '-',
        transaction: row.transaction_id || '-',
        message: status === 'sent' ? 'Event email sent successfully.' : 'Event email sending failed.',
        details: buildMailDetails(payload, row.error_message),
        dateKey,
      };
    });

    const uniqueDates = Array.from(new Set(mailRows.map((row) => row.dateKey))).filter((key) => key && key !== 'unknown');
    mailDateFilter.innerHTML = ['<option value="all">All dates</option>']
      .concat(uniqueDates.map((key) => `<option value="${escapeHtml(key)}">${escapeHtml(key)} logs</option>`))
      .join('');
  }

  async function loadEvents() {
    const payload = await adminApi('admin_list_events');
    const events = Array.isArray(payload.events) ? payload.events : [];
    if (!events.length) {
      eventSelect.innerHTML = '<option value="">No events</option>';
      return;
    }

    const sorted = events
      .slice()
      .sort((a, b) => String(b.start_date || b.date || '').localeCompare(String(a.start_date || a.date || '')));

    eventSelect.innerHTML = sorted
      .map((event) => `<option value="${escapeHtml(String(event.id || event.event_id || ''))}">${escapeHtml(event.title || event.id || 'Event')}</option>`)
      .join('');
  }

  async function loadReport() {
    const eventId = String(eventSelect.value || '');
    if (!eventId) {
      setStatus('Select an event to view report.', true);
      return;
    }

    const [guestPayload, mailPayload] = await Promise.all([
      adminApi('admin_event_guest_report', {
        query: {
          eventId,
          status: statusFilter.value,
        },
      }),
      adminApi('admin_mail_log_report', {
        query: {
          eventId,
          limit: 250,
        },
      }),
    ]);

    const summary = guestPayload.summary || {};
    const transactions = Array.isArray(guestPayload.guests) ? guestPayload.guests : [];
    const mails = {
      summary: mailPayload.summary || {},
      logs: Array.isArray(mailPayload.logs) ? mailPayload.logs : [],
    };

    buildMetrics(summary, mails, transactions);
    buildQrSummaryTable(transactions);

    hydrateRows(transactions);
    hydrateMailRows(mails.logs);

    applyGuestFilters();
    applyMailFilters();
    applyReconFilters();

    setStatus(`Loaded ${transactions.length} registration row(s).`);
  }

  refreshBtn.addEventListener('click', async () => {
    try {
      await loadReport();
    } catch (error) {
      setStatus(error.message || 'Failed to refresh event report.', true);
    }
  });

  mailRefreshBtn.addEventListener('click', async () => {
    try {
      await loadReport();
      setStatus('Mail logs refreshed.');
    } catch (error) {
      setStatus(error.message || 'Failed to refresh logs.', true);
    }
  });

  eventSelect.addEventListener('change', async () => {
    try {
      await loadReport();
    } catch (error) {
      setStatus(error.message || 'Failed to load event report.', true);
    }
  });

  statusFilter.addEventListener('change', async () => {
    try {
      await loadReport();
    } catch (error) {
      setStatus(error.message || 'Failed to apply status filter.', true);
    }
  });

  guestSearchInput.addEventListener('input', () => {
    if (guestSearchTimer) {
      window.clearTimeout(guestSearchTimer);
    }
    guestSearchTimer = window.setTimeout(() => {
      applyGuestFilters();
    }, 160);
  });

  reconSearchInput.addEventListener('input', () => {
    if (reconSearchTimer) {
      window.clearTimeout(reconSearchTimer);
    }
    reconSearchTimer = window.setTimeout(() => {
      applyReconFilters();
    }, 160);
  });

  mailDateFilter.addEventListener('change', () => {
    applyMailFilters();
  });

  clearFiltersBtn.addEventListener('click', () => {
    guestSearchInput.value = '';
    reconSearchInput.value = '';
    mailDateFilter.value = 'all';
    applyGuestFilters();
    applyReconFilters();
    applyMailFilters();
    setStatus('All filters cleared.');
  });

  exportCsvBtn.addEventListener('click', () => {
    if (!filteredGuestRows.length) {
      setStatus('No filtered guest rows to export.', true);
      return;
    }

    downloadCsv(`event-guests-${eventSelect.value || 'report'}-filtered.csv`, [
      ['Event', 'Guest', 'Contact', 'Type', 'Tickets', 'Attendees', 'Status', 'Registered', 'Checked In'],
      ...filteredGuestRows.map((row) => [
        row.event,
        row.guest,
        row.contact,
        row.type,
        row.tickets,
        row.attendees,
        row.status,
        row.registered,
        row.checkedIn,
      ]),
    ]);

    setStatus(`Exported ${filteredGuestRows.length} filtered rows.`);
  });

  exportXlsBtn.addEventListener('click', () => {
    if (!guestRows.length) {
      setStatus('No rows to export.', true);
      return;
    }

    downloadXls(`event-guests-${eventSelect.value || 'report'}-all.xls`, [
      ['Event', 'Guest', 'Contact', 'Type', 'Tickets', 'Attendees', 'Status', 'Registered', 'Checked In'],
      ...guestRows.map((row) => [
        row.event,
        row.guest,
        row.contact,
        row.type,
        row.tickets,
        row.attendees,
        row.status,
        row.registered,
        row.checkedIn,
      ]),
    ]);

    setStatus(`Exported ${guestRows.length} rows to XLS.`);
  });

  try {
    await loadEvents();
    await loadReport();
    setStatus('Event Guests report ready.');
  } catch (error) {
    setStatus(error.message || 'Failed to initialize Event Guests report.', true);
  }
}

function renderSimpleTable(headers, rows, emptyText) {
  if (!rows.length) {
    return `<div class="admin-module-empty">${escapeHtml(emptyText)}</div>`;
  }

  return `
    <div class="admin-module-table-wrap">
      <table class="admin-table eg-table">
        <thead>
          <tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr>
        </thead>
        <tbody>
          ${rows.map((row) => `
            <tr>${row.map((cell) => `<td>${formatCell(cell)}</td>`).join('')}</tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function formatCell(value) {
  const lines = String(value || '-').split('\n');
  if (lines.length === 1) {
    return escapeHtml(lines[0]);
  }
  return lines.map((line) => `<div>${escapeHtml(line)}</div>`).join('');
}

function attendeeNames(row) {
  const details = parseJsonArray(row.attendee_details);
  if (!details.length) {
    return '-';
  }

  const names = details
    .map((item) => {
      if (item && typeof item === 'object') {
        return String(item.name || item.guestName || item.fullName || '').trim();
      }
      return String(item || '').trim();
    })
    .filter((name) => name !== '');

  return names.length ? names.join(', ') : '-';
}

function isPaidStatus(status) {
  const value = String(status || '').toLowerCase();
  return value === 'paid' || value === 'checked_in';
}

function inferRefundState(status) {
  const value = String(status || '').toLowerCase();
  if (value.includes('cancel')) {
    return 'Requested';
  }
  if (value.includes('failed')) {
    return 'Not applicable';
  }
  return '-';
}

function buildMailDetails(payload, errorMessage) {
  const subject = payload && payload.event_title ? `Subject: ${payload.event_title}` : 'Subject: event notification';
  const reason = String(errorMessage || '').trim();
  if (!reason) {
    return `${subject}\nSMTP: delivered`;
  }
  return `${subject}\nSMTP: ${reason}`;
}

function parseJsonArray(raw) {
  if (Array.isArray(raw)) {
    return raw;
  }
  try {
    const parsed = JSON.parse(String(raw || '[]'));
    return Array.isArray(parsed) ? parsed : [];
  } catch (_) {
    return [];
  }
}

function parseJsonObject(raw) {
  try {
    const parsed = JSON.parse(String(raw || '{}'));
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (_) {
    return {};
  }
}

function formatDateTime(value) {
  const text = String(value || '').trim();
  if (!text) {
    return '-';
  }

  const parsed = new Date(text.replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) {
    return text;
  }

  return parsed.toLocaleString('en-IN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  });
}

function currencyInr(value) {
  const amount = Number(value || 0);
  return `INR ${amount.toFixed(0)}`;
}

function downloadCsv(filename, rows) {
  const csv = rows
    .map((row) => row.map((value) => {
      const text = String(value || '');
      if (text.includes(',') || text.includes('"') || text.includes('\n')) {
        return `"${text.replaceAll('"', '""')}"`;
      }
      return text;
    }).join(','))
    .join('\n');

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  triggerDownload(blob, filename);
}

function downloadXls(filename, rows) {
  const html = `
    <table border="1">
      ${rows.map((row) => `<tr>${row.map((value) => `<td>${escapeHtml(String(value || ''))}</td>`).join('')}</tr>`).join('')}
    </table>
  `;

  const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
  triggerDownload(blob, filename);
}

function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
