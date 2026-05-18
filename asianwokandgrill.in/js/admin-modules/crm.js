import { adminApi, downloadBase64File } from './api-client.js';

/**
 * Shows a custom modal dialog asking for test push name and phone.
 * Returns { name, phone } or null if cancelled.
 */
function showTestPushModal(defaultName, defaultPhone) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = `
      <div style="background:var(--admin-surface,#fff);border-radius:12px;padding:28px 32px;min-width:320px;max-width:420px;box-shadow:0 8px 40px rgba(0,0,0,0.22);font-family:inherit;">
        <h3 style="margin:0 0 16px;font-size:1.1rem;">Send Test Push</h3>
        <label style="display:block;margin-bottom:12px;">
          <span style="display:block;font-size:0.82rem;margin-bottom:4px;font-weight:600;">Name</span>
          <input id="crmTestPushModalName" type="text" value="${defaultName}" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--admin-border,#ddd);border-radius:6px;font-size:0.95rem;" />
        </label>
        <label style="display:block;margin-bottom:20px;">
          <span style="display:block;font-size:0.82rem;margin-bottom:4px;font-weight:600;">Mobile</span>
          <input id="crmTestPushModalPhone" type="tel" value="${defaultPhone}" maxlength="15" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--admin-border,#ddd);border-radius:6px;font-size:0.95rem;" />
        </label>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
          <button id="crmTestPushModalCancel" type="button" style="padding:8px 18px;border:1px solid var(--admin-border,#ddd);background:transparent;border-radius:6px;cursor:pointer;font-size:0.92rem;">Cancel</button>
          <button id="crmTestPushModalSend" type="button" style="padding:8px 18px;background:var(--admin-accent,#c0392b);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.92rem;font-weight:600;">Send</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    const nameInput = overlay.querySelector('#crmTestPushModalName');
    const phoneInput = overlay.querySelector('#crmTestPushModalPhone');
    nameInput.focus();
    overlay.querySelector('#crmTestPushModalSend').addEventListener('click', () => {
      const name = nameInput.value.trim() || defaultName;
      const phone = phoneInput.value.trim() || defaultPhone;
      document.body.removeChild(overlay);
      resolve({ name, phone });
    });
    overlay.querySelector('#crmTestPushModalCancel').addEventListener('click', () => {
      document.body.removeChild(overlay);
      resolve(null);
    });
    overlay.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') overlay.querySelector('#crmTestPushModalSend').click();
      if (e.key === 'Escape') overlay.querySelector('#crmTestPushModalCancel').click();
    });
  });
}

export async function renderCrmPanel(container) {
  container.innerHTML = `
    <section class="admin-module-card crm-workspace">
      <h3>CRM Sync Panel</h3>
      <div class="admin-form-actions">
        <button id="crmRefresh" class="admin-button" type="button">Refresh</button>
        <button id="crmBackfill" class="admin-button admin-button-secondary" type="button">Backfill Contacts</button>
        <button id="crmExport" class="admin-button admin-button-secondary" type="button">Download Contacts Excel</button>
      </div>
      <div id="crmStatus" class="admin-form-status">Loading CRM workspace...</div>
      <div id="crmConfig"></div>
      <div id="crmDeployment"></div>
      <div id="crmSummary"></div>

      <section style="margin-top:16px;">
        <h4>CRM Trigger Settings</h4>
        <p class="admin-hero-copy" style="margin:6px 0 12px;">
          Configure a separate CRM endpoint and token for each trigger. The suggested data below matches the event or menu action and is sent using CRM fields like contact_name, contact_phone, and {%contact.custom_values.*%}.
        </p>
        <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:10px;">
          <input id="crmTriggerTestName" type="text" placeholder="Test push name" value="PARIN DAULAT" />
          <input id="crmTriggerTestPhone" type="tel" placeholder="Test push mobile" maxlength="15" value="+919330033000" />
        </div>
        <div id="crmTriggers"></div>
      </section>

      <section style="margin-top:16px;">
        <h4>Controlled CRM Test</h4>
        <form id="crmTestForm" class="admin-form-grid admin-form-grid-inline">
          <input id="crmTestName" type="text" placeholder="Name" value="PARIN DAULAT" />
          <input id="crmTestPhone" type="tel" placeholder="Phone" maxlength="15" value="+919330033000" />
          <input id="crmTestDob" type="text" placeholder="DOB DD/MM/YYYY" />
          <input id="crmTestDoa" type="text" placeholder="DOA DD/MM/YYYY" />
          <div class="admin-form-actions">
            <button class="admin-button" type="submit">Run CRM Test</button>
            <button id="crmDeleteLastTest" class="admin-button admin-button-secondary" type="button">Delete Last Test Lead</button>
            <button id="crmClearResults" class="admin-button admin-button-secondary" type="button">Clear Results</button>
          </div>
        </form>
        <div id="crmTestResult"></div>
      </section>

      <section style="margin-top:16px;">
        <h4>Contacts</h4>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="crmContactSearch" type="text" placeholder="Search contacts" />
          <input id="crmContactSource" type="text" placeholder="Source" />
          <select id="crmContactSync">
            <option value="">All Sync</option>
            <option value="Pending">Pending</option>
            <option value="Success">Success</option>
            <option value="Failed">Failed</option>
            <option value="Skipped">Skipped</option>
          </select>
          <button id="crmContactApply" class="admin-button" type="button">Apply</button>
        </div>
        <div id="crmContactsPage" class="admin-module-empty" style="margin:8px 0;"></div>
        <div id="crmContacts"></div>
        <div class="admin-form-actions" style="margin-top:10px;">
          <button id="crmContactsPrev" class="admin-button admin-button-secondary" type="button">Previous</button>
          <button id="crmContactsNext" class="admin-button admin-button-secondary" type="button">Next</button>
        </div>
      </section>

      <section style="margin-top:16px;">
        <h4>Push Logs</h4>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="crmLogSearch" type="text" placeholder="Search logs" />
          <input id="crmLogSource" type="text" placeholder="Source" />
          <select id="crmLogResult">
            <option value="">All Results</option>
            <option value="Success">Success</option>
            <option value="Failed">Failed</option>
            <option value="Skipped">Skipped</option>
          </select>
          <button id="crmLogApply" class="admin-button" type="button">Apply</button>
        </div>
        <div id="crmLogsPage" class="admin-module-empty" style="margin:8px 0;"></div>
        <div id="crmLogs"></div>
        <div class="admin-form-actions" style="margin-top:10px;">
          <button id="crmLogsPrev" class="admin-button admin-button-secondary" type="button">Previous</button>
          <button id="crmLogsNext" class="admin-button admin-button-secondary" type="button">Next</button>
        </div>
      </section>
    </section>
  `;

  const state = {
    contactsPage: 1,
    contactsPages: 1,
    logsPage: 1,
    logsPages: 1,
    lastLeadId: 0,
  };

  const nodes = {
    status: container.querySelector('#crmStatus'),
    config: container.querySelector('#crmConfig'),
    deployment: container.querySelector('#crmDeployment'),
    summary: container.querySelector('#crmSummary'),
    triggers: container.querySelector('#crmTriggers'),
    contacts: container.querySelector('#crmContacts'),
    contactsPage: container.querySelector('#crmContactsPage'),
    logs: container.querySelector('#crmLogs'),
    logsPage: container.querySelector('#crmLogsPage'),
    testResult: container.querySelector('#crmTestResult'),
  };

  function contactFilters() {
    return {
      search: value('#crmContactSearch'),
      source: value('#crmContactSource'),
      syncStatus: value('#crmContactSync'),
      page: state.contactsPage,
      pageSize: 25,
    };
  }

  function logFilters() {
    return {
      search: value('#crmLogSearch'),
      source: value('#crmLogSource'),
      result: value('#crmLogResult'),
      page: state.logsPage,
      pageSize: 25,
    };
  }

  async function loadPanel() {
    const [payload, triggerPayload] = await Promise.all([
      adminApi('admin_crm_panel_status', { method: 'POST', body: {} }),
      adminApi('admin_list_crm_trigger_configs', { method: 'POST', body: {} }),
    ]);
    const config = payload.configuration || {};
    const deployment = payload.deployment || {};
    const summary = payload.summary || {};
    const triggers = Array.isArray(triggerPayload.triggers) ? triggerPayload.triggers : [];

    nodes.config.innerHTML = `
      <div class="admin-detail-grid">
        <div><span>CRM Status</span><strong>${escapeHtml(payload.status || '-')}</strong></div>
        <div><span>Endpoint</span><strong>${config.endpointConfigured ? 'Configured' : 'Missing'}</strong></div>
        <div><span>Auth Token</span><strong>${config.tokenConfigured ? 'Configured' : 'Missing'}</strong></div>
        <div><span>Endpoint Host</span><strong>${escapeHtml(config.endpointHost || '-')}</strong></div>
      </div>
    `;

    nodes.deployment.innerHTML = `
      <div class="admin-detail-grid" style="margin-top:10px;">
        <div><span>Panel Action</span><strong>${escapeHtml(deployment.panelAction || 'admin_crm_panel_status')}</strong></div>
        <div><span>Test Action</span><strong>${escapeHtml(deployment.testAction || 'admin_test_crm_sync')}</strong></div>
        <div><span>Sync Utility</span><strong>${escapeHtml(deployment.syncAction || 'sync_crm_by_phone')}</strong></div>
        <div><span>API Base</span><strong>${escapeHtml(deployment.apiBase || '/index.php?action=')}</strong></div>
      </div>
    `;

    nodes.summary.innerHTML = `
      <div class="admin-detail-grid" style="margin-top:10px;">
        <div><span>Contacts</span><strong>${Number(summary.contacts || payload.contactsCount || 0)}</strong></div>
        <div><span>Push Logs</span><strong>${Number(summary.pushLogs || payload.logsCount || 0)}</strong></div>
        <div><span>Leads</span><strong>${Number(summary.leads || 0)}</strong></div>
        <div><span>Enabled Triggers</span><strong>${Number(summary.enabledTriggers || 0)} / ${Number(summary.triggers || triggers.length)}</strong></div>
      </div>
    `;

    nodes.triggers.innerHTML = buildTriggerSettings(triggers);
    bindTriggerSettings(triggers);
  }

  async function loadContacts() {
    const payload = await adminApi('admin_list_crm_contacts', { method: 'POST', body: contactFilters() });
    const rows = Array.isArray(payload.contacts) ? payload.contacts : [];
    const pagination = payload.pagination || {};
    state.contactsPage = Number(pagination.page || 1);
    state.contactsPages = Number(pagination.pages || 1);
    nodes.contactsPage.textContent = `Page ${state.contactsPage} of ${state.contactsPages} | Total contacts: ${Number(pagination.total || rows.length)}`;
    container.querySelector('#crmContactsPrev').disabled = state.contactsPage <= 1;
    container.querySelector('#crmContactsNext').disabled = state.contactsPage >= state.contactsPages;
    nodes.contacts.innerHTML = buildContactsTable(rows);
  }

  async function loadLogs() {
    const payload = await adminApi('admin_list_crm_push_logs', { method: 'POST', body: logFilters() });
    const rows = Array.isArray(payload.logs) ? payload.logs : [];
    const pagination = payload.pagination || {};
    state.logsPage = Number(pagination.page || 1);
    state.logsPages = Number(pagination.pages || 1);
    nodes.logsPage.textContent = `Page ${state.logsPage} of ${state.logsPages} | Total logs: ${Number(pagination.total || rows.length)}`;
    container.querySelector('#crmLogsPrev').disabled = state.logsPage <= 1;
    container.querySelector('#crmLogsNext').disabled = state.logsPage >= state.logsPages;
    nodes.logs.innerHTML = buildLogsTable(rows);
  }

  async function load() {
    try {
      await Promise.all([loadPanel(), loadContacts(), loadLogs()]);
      setStatus('CRM workspace loaded.', false);
    } catch (error) {
      setStatus(error.message || 'Unable to load CRM workspace.', true);
    }
  }

  container.querySelector('#crmRefresh').addEventListener('click', () => load());
  container.querySelector('#crmContactApply').addEventListener('click', async () => {
    state.contactsPage = 1;
    await loadContacts();
  });
  container.querySelector('#crmLogApply').addEventListener('click', async () => {
    state.logsPage = 1;
    await loadLogs();
  });
  container.querySelector('#crmContactsPrev').addEventListener('click', async () => {
    if (state.contactsPage <= 1) return;
    state.contactsPage -= 1;
    await loadContacts();
  });
  container.querySelector('#crmContactsNext').addEventListener('click', async () => {
    if (state.contactsPage >= state.contactsPages) return;
    state.contactsPage += 1;
    await loadContacts();
  });
  container.querySelector('#crmLogsPrev').addEventListener('click', async () => {
    if (state.logsPage <= 1) return;
    state.logsPage -= 1;
    await loadLogs();
  });
  container.querySelector('#crmLogsNext').addEventListener('click', async () => {
    if (state.logsPage >= state.logsPages) return;
    state.logsPage += 1;
    await loadLogs();
  });

  container.querySelector('#crmBackfill').addEventListener('click', async () => {
    try {
      const payload = await adminApi('admin_backfill_crm_contacts', { method: 'POST', body: {} });
      setStatus(`Backfill completed: ${Number(payload.processed || 0)} contacts processed.`, false);
      await load();
    } catch (error) {
      setStatus(error.message || 'Backfill failed.', true);
    }
  });

  container.querySelector('#crmExport').addEventListener('click', async () => {
    try {
      const payload = await adminApi('admin_export_crm_contacts', { method: 'POST', body: contactFilters() });
      downloadBase64File(payload.fileName, payload.mimeType, payload.base64);
      setStatus(`Contacts Excel generated (${Number(payload.count || 0)} rows).`, false);
    } catch (error) {
      setStatus(error.message || 'Contacts export failed.', true);
    }
  });

  container.querySelector('#crmTestForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const payload = await adminApi('admin_test_crm_sync', {
        method: 'POST',
        body: {
          lead: {
            name: value('#crmTestName'),
            phone: value('#crmTestPhone'),
            dob: value('#crmTestDob'),
            doa: value('#crmTestDoa'),
          },
        },
      });
      state.lastLeadId = Number(payload.leadId || 0);
      nodes.testResult.innerHTML = buildTestResult(payload);
      setStatus('Controlled CRM test completed.', false);
      await load();
    } catch (error) {
      setStatus(error.message || 'CRM test failed.', true);
    }
  });

  container.querySelector('#crmDeleteLastTest').addEventListener('click', async () => {
    if (!state.lastLeadId) {
      setStatus('No last CRM test lead is available to delete.', true);
      return;
    }

    try {
      await adminApi('admin_delete_crm_test_lead', { method: 'POST', body: { leadId: state.lastLeadId } });
      state.lastLeadId = 0;
      nodes.testResult.innerHTML = '';
      setStatus('Last CRM test lead deleted.', false);
      await load();
    } catch (error) {
      setStatus(error.message || 'Failed to delete test lead.', true);
    }
  });

  container.querySelector('#crmClearResults').addEventListener('click', () => {
    nodes.testResult.innerHTML = '';
  });

  function value(selector) {
    return String(container.querySelector(selector)?.value || '').trim();
  }

  function setStatus(message, isError) {
    nodes.status.textContent = message;
    nodes.status.classList.toggle('error', Boolean(isError));
  }

  function bindTriggerSettings(triggers) {
    const triggerByKey = new Map(triggers.map((trigger) => [String(trigger.key || ''), trigger]));

    async function persistTriggerConfig(key, block, trigger) {
      const selectedFields = Array.from(block.querySelectorAll('[data-crm-trigger-field]:checked'))
        .map((input) => input.value);

      await adminApi('admin_save_crm_trigger_config', {
        method: 'POST',
        body: {
          triggerKey: key,
          enabled: Boolean(block.querySelector('[data-crm-trigger-enabled]')?.checked),
          endpoint: block.querySelector('[data-crm-trigger-endpoint]')?.value || '',
          token: block.querySelector('[data-crm-trigger-token]')?.value || '',
          retryCount: Number(block.querySelector('[data-crm-trigger-retry]')?.value || 1),
          selectedFields,
        },
      });
      setStatus(`CRM trigger saved: ${trigger.label || key}`, false);
      await loadPanel();
    }

    Array.from(container.querySelectorAll('[data-crm-trigger-save]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const key = String(button.dataset.crmTriggerSave || '');
        const block = container.querySelector(`[data-crm-trigger-block="${cssEscape(key)}"]`);
        const trigger = triggerByKey.get(key);
        if (!block || !trigger) return;

        try {
          await persistTriggerConfig(key, block, trigger);
        } catch (error) {
          setStatus(error.message || 'Failed to save CRM trigger.', true);
        }
      });
    });

    Array.from(container.querySelectorAll('[data-crm-trigger-toggle]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const key = String(button.dataset.crmTriggerToggle || '');
        const block = container.querySelector(`[data-crm-trigger-block="${cssEscape(key)}"]`);
        const trigger = triggerByKey.get(key);
        if (!block || !trigger) return;

        const enabledInput = block.querySelector('[data-crm-trigger-enabled]');
        if (!enabledInput) return;
        enabledInput.checked = !enabledInput.checked;

        try {
          await persistTriggerConfig(key, block, trigger);
          setStatus(`CRM trigger ${enabledInput.checked ? 'enabled' : 'disabled'}: ${trigger.label || key}`, false);
        } catch (error) {
          setStatus(error.message || 'Failed to toggle CRM trigger.', true);
        }
      });
    });

    Array.from(container.querySelectorAll('[data-crm-trigger-test]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const key = String(button.dataset.crmTriggerTest || '');
        const defaultName = value('#crmTriggerTestName') || 'PARIN DAULAT';
        const defaultPhone = value('#crmTriggerTestPhone') || '+919330033000';

        const modalResult = await showTestPushModal(defaultName, defaultPhone);
        if (!modalResult) {
          setStatus('Test push cancelled.', true);
          return;
        }
        const testName = modalResult.name;
        const testPhone = modalResult.phone;

        try {
          const payload = await adminApi('admin_test_crm_trigger', {
            method: 'POST',
            body: { triggerKey: key, name: testName, phone: testPhone },
          });
          const target = container.querySelector(`[data-crm-trigger-test-output="${cssEscape(key)}"]`);
          if (target) {
            target.innerHTML = `<pre class="admin-code-block">${escapeHtml(JSON.stringify(payload, null, 2))}</pre>`;
          }
          setStatus(`CRM test push completed: ${key}`, false);
          await loadLogs();
        } catch (error) {
          setStatus(error.message || 'CRM trigger test failed.', true);
        }
      });
    });

    Array.from(container.querySelectorAll('[data-crm-trigger-reset]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const key = String(button.dataset.crmTriggerReset || '');
        if (!window.confirm(`Reset ${key} to default CRM trigger settings?`)) return;
        try {
          await adminApi('admin_reset_crm_trigger_to_default', { method: 'POST', body: { triggerKey: key } });
          setStatus(`CRM trigger reset: ${key}`, false);
          await loadPanel();
        } catch (error) {
          setStatus(error.message || 'Failed to reset CRM trigger.', true);
        }
      });
    });
  }

  await load();
}

export async function renderCrmLeads(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>CRM Leads</h3>
      <div class="admin-form-grid admin-form-grid-inline" style="margin-bottom:12px;">
        <input id="crmLeadsSearch" type="text" placeholder="Search" />
        <input id="crmLeadsSource" type="text" placeholder="Source" />
        <select id="crmLeadsOutcome"><option value="">All Outcomes</option><option value="Won">Won</option><option value="Try Again">Try Again</option></select>
        <select id="crmLeadsLeadStatus"><option value="">All Lead Status</option><option value="Unredeemed">Unredeemed</option><option value="Redeemed">Redeemed</option></select>
        <select id="crmLeadsSyncStatus"><option value="">All Sync Status</option><option value="Pending">Pending</option><option value="Success">Success</option><option value="Failed">Failed</option><option value="Skipped">Skipped</option></select>
        <input id="crmLeadsFromDate" type="date" />
        <input id="crmLeadsToDate" type="date" />
      </div>
      <div class="admin-form-actions" style="margin-bottom:12px;">
        <button id="crmLeadsApply" class="admin-button" type="button">Apply</button>
        <button id="crmLeadsReset" class="admin-button admin-button-secondary" type="button">Reset</button>
        <button id="crmLeadsExport" class="admin-button admin-button-secondary" type="button">Download Leads Excel</button>
      </div>
      <div id="crmLeadsStatus" class="admin-form-status">Loading CRM leads...</div>
      <div id="crmLeadsSummary"></div>
      <div id="crmLeadsPagination" class="admin-module-empty" style="margin:8px 0;"></div>
      <div id="crmLeadsTable"></div>
      <div class="admin-form-actions" style="margin-top:10px;">
        <button id="crmLeadsPrev" class="admin-button admin-button-secondary" type="button">Previous</button>
        <button id="crmLeadsNext" class="admin-button admin-button-secondary" type="button">Next</button>
      </div>
    </section>
  `;

  let page = 1;
  let pages = 1;
  const status = container.querySelector('#crmLeadsStatus');
  const summaryNode = container.querySelector('#crmLeadsSummary');
  const pageNode = container.querySelector('#crmLeadsPagination');
  const tableNode = container.querySelector('#crmLeadsTable');

  function filters() {
    return {
      search: value('#crmLeadsSearch'),
      source: value('#crmLeadsSource'),
      outcome: value('#crmLeadsOutcome'),
      leadStatus: value('#crmLeadsLeadStatus'),
      syncStatus: value('#crmLeadsSyncStatus'),
      fromDate: value('#crmLeadsFromDate'),
      toDate: value('#crmLeadsToDate'),
    };
  }

  async function load() {
    try {
      const [summaryPayload, listPayload] = await Promise.all([
        adminApi('admin_crm_leads_status', { method: 'POST', body: filters() }),
        adminApi('admin_list_crm_leads', { method: 'POST', body: { ...filters(), page, pageSize: 50 } }),
      ]);

      const summary = summaryPayload.summary || {};
      const rows = Array.isArray(listPayload.leads) ? listPayload.leads : [];
      const pagination = listPayload.pagination || {};
      page = Number(pagination.page || 1);
      pages = Number(pagination.pages || 1);

      summaryNode.innerHTML = `
        <div class="admin-detail-grid" style="margin-bottom:10px;">
          <div><span>Total Leads</span><strong>${Number(summary.totalLeads || 0)}</strong></div>
          <div><span>Won</span><strong>${Number(summary.totalWon || 0)}</strong></div>
          <div><span>Try Again</span><strong>${Number(summary.totalTryAgain || 0)}</strong></div>
          <div><span>Redeemed</span><strong>${Number(summary.totalRedeemed || 0)}</strong></div>
        </div>
      `;
      pageNode.textContent = `Page ${page} of ${pages} | Total leads: ${Number(pagination.total || 0)}`;
      container.querySelector('#crmLeadsPrev').disabled = page <= 1;
      container.querySelector('#crmLeadsNext').disabled = page >= pages;
      tableNode.innerHTML = buildLeadsTable(rows);
      setStatus(`Showing ${rows.length} lead(s).`, false);
    } catch (error) {
      setStatus(error.message || 'Failed to load CRM leads.', true);
    }
  }

  container.querySelector('#crmLeadsApply').addEventListener('click', async () => {
    page = 1;
    await load();
  });
  container.querySelector('#crmLeadsReset').addEventListener('click', async () => {
    ['#crmLeadsSearch', '#crmLeadsSource', '#crmLeadsOutcome', '#crmLeadsLeadStatus', '#crmLeadsSyncStatus', '#crmLeadsFromDate', '#crmLeadsToDate']
      .forEach((selector) => { container.querySelector(selector).value = ''; });
    page = 1;
    await load();
  });
  container.querySelector('#crmLeadsPrev').addEventListener('click', async () => {
    if (page <= 1) return;
    page -= 1;
    await load();
  });
  container.querySelector('#crmLeadsNext').addEventListener('click', async () => {
    if (page >= pages) return;
    page += 1;
    await load();
  });
  container.querySelector('#crmLeadsExport').addEventListener('click', async () => {
    try {
      const payload = await adminApi('admin_export_crm_leads', { method: 'POST', body: filters() });
      downloadBase64File(payload.fileName, payload.mimeType, payload.base64);
      setStatus(`Leads Excel generated (${Number(payload.count || 0)} rows).`, false);
    } catch (error) {
      setStatus(error.message || 'Leads export failed.', true);
    }
  });

  function value(selector) {
    return String(container.querySelector(selector)?.value || '').trim();
  }

  function setStatus(message, isError) {
    status.textContent = message;
    status.classList.toggle('error', Boolean(isError));
  }

  await load();
}

export async function renderCrm(container) {
  await renderCrmPanel(container);
}

function buildTriggerSettings(triggers) {
  if (!triggers.length) {
    return '<div class="admin-module-empty">No CRM triggers are available.</div>';
  }

  return `
    <div class="admin-module-card-grid">
      ${triggers.map((trigger) => `
        <article class="admin-mini-card" data-crm-trigger-block="${escapeHtml(trigger.key || '')}">
          <div class="admin-form-actions" style="justify-content:space-between;align-items:flex-start;">
            <div>
              <h4 style="margin:0;">${escapeHtml(trigger.label || trigger.key || '-')}</h4>
              <small style="display:block;color:var(--admin-ink-soft);margin-top:4px;">${escapeHtml(trigger.key || '')}</small>
            </div>
            <div class="admin-form-actions" style="gap:6px;justify-content:flex-end;">
              ${badge(trigger.enabled ? 'Enabled' : 'Disabled')}
              ${badge(triggerReadiness(trigger))}
            </div>
          </div>
          <p class="admin-hero-copy" style="margin:8px 0 12px;">${escapeHtml(trigger.description || '')}</p>
          <div class="admin-detail-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:12px;">
            <div><span>Endpoint</span><strong>${trigger.endpointConfigured ? 'Configured' : 'Missing'}</strong></div>
            <div><span>Token</span><strong>${trigger.tokenConfigured ? 'Configured' : 'Missing'}</strong></div>
            <div><span>Selected Fields</span><strong>${Array.isArray(trigger.selectedFields) ? trigger.selectedFields.length : 0} / ${Array.isArray(trigger.fields) ? trigger.fields.length : 0}</strong></div>
          </div>
          <label class="admin-checkbox"><input data-crm-trigger-enabled type="checkbox" ${trigger.enabled ? 'checked' : ''} /> Enabled</label>
          <div class="admin-form-grid" style="margin-top:10px;">
            <input data-crm-trigger-endpoint type="url" placeholder="CRM endpoint URL" value="${escapeHtml(trigger.endpoint || '')}" />
            <input data-crm-trigger-token type="password" placeholder="${trigger.tokenConfigured ? 'Token configured. Enter new token to replace.' : 'CRM API token'}" autocomplete="new-password" />
            <input data-crm-trigger-retry type="number" min="0" max="3" value="${Number(trigger.retryCount || 1)}" placeholder="Retry count" />
          </div>
          <div style="margin-top:12px;">
            <h4 style="margin:0 0 8px;">Suggested Data To Push</h4>
            <div class="admin-form-grid">
            ${(trigger.fields || []).map((field) => `
              <label class="admin-checkbox" title="${escapeHtml(field.param || '')}" style="align-items:flex-start;">
                <input data-crm-trigger-field type="checkbox" value="${escapeHtml(field.key || '')}" ${(trigger.selectedFields || []).includes(field.key) ? 'checked' : ''} />
                <span>
                  <strong>${escapeHtml(field.label || field.key || '')}</strong>
                  <small style="display:block;color:var(--admin-ink-soft);margin-top:2px;">${escapeHtml(field.param || '')}</small>
                </span>
              </label>
            `).join('')}
            </div>
          </div>
          <div class="admin-form-actions" style="margin-top:12px;">
            <button class="admin-button" type="button" data-crm-trigger-save="${escapeHtml(trigger.key || '')}">Save Trigger</button>
            <button class="admin-button admin-button-secondary" type="button" data-crm-trigger-toggle="${escapeHtml(trigger.key || '')}">${trigger.enabled ? 'Disable Trigger' : 'Enable Trigger'}</button>
            <button class="admin-button admin-button-secondary" type="button" data-crm-trigger-test="${escapeHtml(trigger.key || '')}">Send Test Push</button>
            <button class="admin-button admin-button-secondary" type="button" data-crm-trigger-reset="${escapeHtml(trigger.key || '')}">Reset</button>
          </div>
          <div class="crm-trigger-test-output" data-crm-trigger-test-output="${escapeHtml(trigger.key || '')}"></div>
        </article>
      `).join('')}
    </div>
  `;
}

function triggerReadiness(trigger) {
  if (!trigger.enabled) return 'Inactive';
  if (!trigger.endpointConfigured || !trigger.tokenConfigured) return 'Needs Config';
  return 'Push Ready';
}

function buildContactsTable(rows) {
  if (!rows.length) return '<div class="admin-module-empty">No CRM contacts match the current filters.</div>';
  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>Mobile</th><th>Name</th><th>DOB</th><th>DOA</th><th>Total Entries</th><th>Last Seen</th><th>Source</th><th>Sync</th><th>Code</th></tr></thead>
        <tbody>${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.phone || '-')}</td>
            <td>${escapeHtml(row.name || '-')}</td>
            <td>${escapeHtml(row.date_of_birth || '-')}</td>
            <td>${escapeHtml(row.date_of_anniversary || '-')}</td>
            <td>${escapeHtml(row.total_submissions || 0)}</td>
            <td>${escapeHtml(row.last_seen_at || '-')}</td>
            <td>${escapeHtml(row.latest_source || '-')}</td>
            <td>${badge(row.latest_crm_sync_status || 'Pending')}</td>
            <td>${escapeHtml(row.latest_crm_sync_code || '-')}</td>
          </tr>
        `).join('')}</tbody>
      </table>
    </div>
  `;
}

function buildLogsTable(rows) {
  if (!rows.length) return '<div class="admin-module-empty">No CRM push logs match the current filters.</div>';
  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>When</th><th>Mobile</th><th>Name</th><th>Source</th><th>Result</th><th>HTTP</th><th>Attempts</th></tr></thead>
        <tbody>${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.created_at || '-')}</td>
            <td>${escapeHtml(row.phone || '-')}</td>
            <td>${escapeHtml(row.contact_name || '-')}</td>
            <td>${escapeHtml(row.trigger_source || '-')}</td>
            <td>${badge(resolveLogResult(row))}</td>
            <td>${escapeHtml(row.http_code || '-')}</td>
            <td>${escapeHtml(row.attempt_count || 0)} / retries ${escapeHtml(row.retry_count || 0)}</td>
          </tr>
        `).join('')}</tbody>
      </table>
    </div>
  `;
}

function buildLeadsTable(rows) {
  if (!rows.length) return '<div class="admin-module-empty">No leads match the current filters.</div>';
  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>When</th><th>Mobile</th><th>Name</th><th>{%contact.custom_values.prize%}</th><th>{%contact.custom_values.outcome%}</th><th>{%contact.custom_values.coupon_code%}</th><th>Status</th><th>Redeemed</th><th>Source</th><th>CRM</th></tr></thead>
        <tbody>${rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.createdAt || '-')}</td>
            <td>${escapeHtml(row.phone || '-')}</td>
            <td>${escapeHtml(row.name || '-')}</td>
            <td>${escapeHtml(row['{%contact.custom_values.prize%}'] || '-')}</td>
            <td>${badge(row['{%contact.custom_values.outcome%}'] || 'Pending')}</td>
            <td>${escapeHtml(row['{%contact.custom_values.coupon_code%}'] || '-')}</td>
            <td>${escapeHtml(row.couponCode || '-')}</td>
            <td>${badge(row.status || '-')}</td>
            <td>${escapeHtml(row.redeemedAt || '-')}</td>
            <td>${escapeHtml(row.source || '-')}</td>
            <td>${badge(row.crmSyncStatus || 'Pending')}</td>
          </tr>
        `).join('')}</tbody>
      </table>
    </div>
  `;
}

function buildTestResult(payload) {
  return `
    ${detailBlock('Data Received', payload.dataReceived)}
    ${detailBlock('CRM Payload Preview', payload.crmPayloadPreview)}
    ${detailBlock('Lead Stored In Database', payload.storedLead)}
    ${detailBlock('Canonical Contact', payload.canonicalContact)}
    ${detailBlock('CRM Push Confirmation', payload.crmSyncConfirmation)}
  `;
}

function detailBlock(title, data) {
  return `
    <div style="margin-top:10px;">
      <h4>${escapeHtml(title)}</h4>
      <pre class="admin-code-block">${escapeHtml(JSON.stringify(data || {}, null, 2))}</pre>
    </div>
  `;
}

function resolveLogResult(row) {
  if (Number(row.attempted || 0) === 0) return 'Skipped';
  return Number(row.success || 0) === 1 ? 'Success' : 'Failed';
}

function badge(value) {
  const text = escapeHtml(value || '-');
  return `<span class="admin-status-badge">${text}</span>`;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function cssEscape(value) {
  if (window.CSS && typeof window.CSS.escape === 'function') {
    return window.CSS.escape(value);
  }
  return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
}
