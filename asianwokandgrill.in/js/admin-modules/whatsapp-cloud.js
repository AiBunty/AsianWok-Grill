import { adminApi } from './api-client.js';

export async function renderWhatsappCloud(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>WhatsApp Cloud API Workspace</h3>
      <div id="waStatus" class="admin-form-status">Loading workspace...</div>
      <label class="admin-checkbox" style="margin:10px 0 6px;display:inline-flex;gap:8px;align-items:center;">
        <input id="waShowTestRecords" type="checkbox" />
        Show test records
      </label>
      <form id="waForm" class="admin-form-grid">
        <input id="waPhoneNumberId" type="text" placeholder="Phone Number ID" />
        <input id="waBusinessAccountId" type="text" placeholder="Business Account ID" />
        <input id="waVerifyToken" type="text" placeholder="Verify Token" />
        <input id="waAccessToken" type="password" placeholder="Access Token (leave blank to keep existing)" />
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Save Meta Config</button>
          <button id="waSyncTemplates" class="admin-button admin-button-secondary" type="button">Sync Templates</button>
          <button id="waRunScheduler" class="admin-button admin-button-secondary" type="button">Run Scheduler</button>
        </div>
      </form>
      <div id="waWorkspaceMeta"></div>
    </section>

    <section class="admin-module-card">
      <h3>Event Mapping</h3>
      <form id="waMapForm" class="admin-form-grid">
        <select id="waEventSelect"></select>
        <select id="waTemplateSelect"></select>
        <label class="admin-checkbox"><input id="waMappingEnabled" type="checkbox" /> Enable mapping for selected event</label>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Save Mapping</button>
        </div>
      </form>
      <div id="waMappingList"></div>
    </section>

    <section class="admin-module-card">
      <h3>Template Draft Studio</h3>
      <form id="waDraftForm" class="admin-form-grid">
        <input id="waDraftId" type="hidden" />
        <input id="waDraftName" type="text" placeholder="Draft Label" required />
        <input id="waDraftTemplateName" type="text" placeholder="Template Name" required />
        <textarea id="waDraftBody" rows="5" placeholder="Body text with placeholders like {{1}}" required></textarea>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Save Draft</button>
          <button id="waSubmitDraft" class="admin-button admin-button-secondary" type="button">Submit Draft</button>
        </div>
      </form>
      <div id="waDraftsList"></div>
    </section>

    <section class="admin-module-card">
      <h3>Test Send</h3>
      <form id="waTestForm" class="admin-form-grid">
        <input id="waTestPhone" type="text" maxlength="10" placeholder="10-digit phone" required />
        <select id="waTestEventSelect"></select>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Send Test</button>
        </div>
      </form>
    </section>

    <section class="admin-module-card">
      <h3>Recent Logs</h3>
      <div id="waLogsList"></div>
    </section>
  `;

  const status = container.querySelector('#waStatus');
  const meta = container.querySelector('#waWorkspaceMeta');
  const form = container.querySelector('#waForm');
  const syncBtn = container.querySelector('#waSyncTemplates');
  const schedulerBtn = container.querySelector('#waRunScheduler');
  const mapForm = container.querySelector('#waMapForm');
  const draftForm = container.querySelector('#waDraftForm');
  const submitDraftBtn = container.querySelector('#waSubmitDraft');
  const testForm = container.querySelector('#waTestForm');
  const showTestsToggle = container.querySelector('#waShowTestRecords');

  let workspace = null;
  let showTests = false;

  function renderWorkspace() {
    const config = (workspace && workspace.config) || {};
    const eventKeys = Array.isArray(workspace?.eventKeys) ? workspace.eventKeys : [];
    const events = eventKeys.map((key) => ({ id: key, title: key }));
    const templatesRaw = Array.isArray(workspace?.templates) ? workspace.templates : [];
    const mappingsRaw = Array.isArray(workspace?.mappings) ? workspace.mappings : [];
    const draftsRaw = Array.isArray(workspace?.drafts) ? workspace.drafts : [];
    const logsRaw = Array.isArray(workspace?.logs) ? workspace.logs : [];
    const schedules = Array.isArray(workspace?.schedules) ? workspace.schedules : [];

    const templates = filterRecords(templatesRaw, showTests);
    const mappings = filterRecords(mappingsRaw, showTests);
    const drafts = filterRecords(draftsRaw, showTests);
    const logs = filterRecords(logsRaw, showTests);

    const hiddenCount =
      (templatesRaw.length - templates.length)
      + (mappingsRaw.length - mappings.length)
      + (draftsRaw.length - drafts.length)
      + (logsRaw.length - logs.length);

    container.querySelector('#waPhoneNumberId').value = config.phoneNumberId || '';
    container.querySelector('#waBusinessAccountId').value = config.businessAccountId || '';
    container.querySelector('#waVerifyToken').value = config.verifyToken || '';
    showTestsToggle.checked = showTests;

    meta.innerHTML = `
      <div class="admin-detail-grid" style="margin-top:12px;">
        <div><span>Access Token</span><strong>${config.accessTokenSet ? 'Configured' : 'Not set'}</strong></div>
        <div><span>Templates</span><strong>${templates.length}</strong></div>
        <div><span>Mappings</span><strong>${mappings.length}</strong></div>
        <div><span>Drafts</span><strong>${drafts.length}</strong></div>
        <div><span>Schedules</span><strong>${schedules.length}</strong></div>
        <div><span>Logs</span><strong>${logs.length}</strong></div>
      </div>
      ${!showTests && hiddenCount > 0 ? `<div class="admin-form-status" style="margin-top:10px;">${hiddenCount} test record(s) hidden. Enable "Show test records" to review them.</div>` : ''}
    `;

    const eventOptions = events.length
      ? events.map((event) => `<option value="${escapeHtml(event.id)}">${escapeHtml(event.title || event.id)}</option>`).join('')
      : '<option value="">No events available</option>';
    const templateOptions = templates.length
      ? templates.map((template) => {
        const name = template.template_name || template.name || '';
        const lang = template.language_code || template.language || 'en';
        const uid = template.template_uid || template.uid || '';
        const value = `${name}|${lang}|${uid}`;
        return `<option value="${escapeHtml(value)}">${escapeHtml(name)} (${escapeHtml(lang)} | ${escapeHtml(template.status || 'UNKNOWN')})</option>`;
      }).join('')
      : '<option value="">No templates available</option>';

    container.querySelector('#waEventSelect').innerHTML = eventOptions;
    container.querySelector('#waTestEventSelect').innerHTML = eventOptions;
    container.querySelector('#waTemplateSelect').innerHTML = templateOptions;

    container.querySelector('#waMappingList').innerHTML = renderMappings(mappings, events, templates);
    container.querySelector('#waDraftsList').innerHTML = renderDrafts(drafts);
    container.querySelector('#waLogsList').innerHTML = renderLogs(logs);

    Array.from(container.querySelectorAll('[data-draft-edit]')).forEach((button) => {
      button.addEventListener('click', () => {
        const draftId = button.getAttribute('data-draft-edit') || '';
        const draft = drafts.find((item) => String(item.id) === String(draftId));
        if (!draft) return;

        container.querySelector('#waDraftId').value = draft.id || '';
        container.querySelector('#waDraftName').value = draft.draft_name || draft.name || '';
        container.querySelector('#waDraftTemplateName').value = draft.template_name || draft.templateName || '';
        container.querySelector('#waDraftBody').value = draft.body_text || draft.bodyText || '';
      });
    });
  }

  showTestsToggle.addEventListener('change', () => {
    showTests = showTestsToggle.checked;
    renderWorkspace();
  });

  async function loadWorkspace() {
    try {
      const payload = await adminApi('auth_get_whatsapp_workspace', { method: 'POST', body: {} });
      workspace = payload.workspace || {};
      renderWorkspace();

      status.textContent = 'WhatsApp workspace loaded.';
      status.classList.remove('error');
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
      meta.innerHTML = '';
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await adminApi('auth_save_whatsapp_config', {
        method: 'POST',
        body: {
          config: {
            phoneNumberId: container.querySelector('#waPhoneNumberId').value,
            businessAccountId: container.querySelector('#waBusinessAccountId').value,
            verifyToken: container.querySelector('#waVerifyToken').value,
            accessToken: container.querySelector('#waAccessToken').value,
          },
        },
      });
      container.querySelector('#waAccessToken').value = '';
      status.textContent = 'WhatsApp config saved.';
      status.classList.remove('error');
      await loadWorkspace();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  syncBtn.addEventListener('click', async () => {
    try {
      await adminApi('auth_sync_whatsapp_templates', { method: 'POST', body: {} });
      status.textContent = 'Templates synchronized.';
      status.classList.remove('error');
      await loadWorkspace();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  schedulerBtn.addEventListener('click', async () => {
    try {
      const payload = await adminApi('auth_run_whatsapp_scheduler', { method: 'POST', body: {} });
      status.textContent = `${payload.message} Processed: ${payload.processed || 0}`;
      status.classList.remove('error');
      await loadWorkspace();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  mapForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
      const selected = String(container.querySelector('#waTemplateSelect').value || '');
      const [templateName, languageCode, mappedTemplateUid] = selected.split('|');

      await adminApi('auth_save_whatsapp_mapping', {
        method: 'POST',
        body: {
          mapping: {
            eventKey: container.querySelector('#waEventSelect').value,
            templateName: templateName || '',
            languageCode: languageCode || '',
            mappedTemplateUid: mappedTemplateUid || '',
            isEnabled: container.querySelector('#waMappingEnabled').checked,
          },
        },
      });

      status.textContent = 'Event mapping saved.';
      status.classList.remove('error');
      await loadWorkspace();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  draftForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
      await adminApi('auth_save_whatsapp_template_draft', {
        method: 'POST',
        body: {
          draft: {
            id: container.querySelector('#waDraftId').value,
            draftName: normalizeDraftName(
              container.querySelector('#waDraftName').value,
              container.querySelector('#waDraftTemplateName').value,
              container.querySelector('#waDraftBody').value,
            ),
            templateName: container.querySelector('#waDraftTemplateName').value,
            bodyText: container.querySelector('#waDraftBody').value,
            languageCode: 'en',
            category: 'UTILITY',
          },
        },
      });

      status.textContent = 'Draft saved.';
      status.classList.remove('error');
      container.querySelector('#waDraftId').value = '';
      await loadWorkspace();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  submitDraftBtn.addEventListener('click', async () => {
    const draftId = container.querySelector('#waDraftId').value;
    if (!draftId) {
      status.textContent = 'Select a draft first.';
      status.classList.add('error');
      return;
    }

    try {
      await adminApi('auth_submit_whatsapp_template_draft', {
        method: 'POST',
        body: { draftId },
      });
      status.textContent = 'Draft submitted.';
      status.classList.remove('error');
      await loadWorkspace();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  testForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await adminApi('auth_send_test_whatsapp_template', {
        method: 'POST',
        body: {
          payload: {
            phone: container.querySelector('#waTestPhone').value,
            eventKey: container.querySelector('#waTestEventSelect').value,
          },
        },
      });
      status.textContent = 'Test send executed.';
      status.classList.remove('error');
      await loadWorkspace();
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  await loadWorkspace();
}

function renderMappings(mappings, events, templates) {
  if (!Array.isArray(mappings) || !mappings.length) {
    return '<div class="admin-module-empty">No mappings configured yet.</div>';
  }

  const eventMap = new Map(events.map((event) => [String(event.id), event]));
  const templateMap = new Map(templates.map((template) => [String(template.template_uid || template.uid || ''), template]));

  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>Event</th><th>Template</th><th>Enabled</th><th>Updated</th></tr></thead>
        <tbody>
          ${mappings.map((mapping) => {
            const event = eventMap.get(String(mapping.event_key || mapping.eventId || ''));
            const template = templateMap.get(String(mapping.mapped_template_uid || mapping.templateUid || ''));
            const badge = isTestRecord(mapping) ? ' [TEST]' : '';
            return `
              <tr>
                <td>${escapeHtml(event?.title || mapping.event_key || mapping.eventId || '-')}${badge}</td>
                <td>${escapeHtml(template?.template_name || template?.name || mapping.template_name || mapping.templateUid || '-')}</td>
                <td>${mapping.is_enabled || mapping.enabled ? 'Yes' : 'No'}</td>
                <td>${escapeHtml(mapping.updated_at || mapping.updatedAt || '-')}</td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderDrafts(drafts) {
  if (!Array.isArray(drafts) || !drafts.length) {
    return '<div class="admin-module-empty">No drafts saved yet.</div>';
  }

  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>Label</th><th>Template</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
        <tbody>
          ${drafts.map((draft) => `
            <tr>
              <td>${escapeHtml(draft.draft_name || draft.name || '-')}${isTestRecord(draft) ? ' [TEST]' : ''}</td>
              <td>${escapeHtml(draft.template_name || draft.templateName || '-')}</td>
              <td>${escapeHtml(draft.status || '-')}</td>
              <td>${escapeHtml(draft.updated_at || draft.updatedAt || '-')}</td>
              <td><button class="admin-table-button" type="button" data-draft-edit="${escapeHtml(draft.id || '')}">Edit</button></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderLogs(logs) {
  if (!Array.isArray(logs) || !logs.length) {
    return '<div class="admin-module-empty">No log entries yet.</div>';
  }

  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>Time</th><th>Event</th><th>Phone</th><th>Template</th><th>Result</th><th>Message</th></tr></thead>
        <tbody>
          ${logs.slice(-50).reverse().map((log) => `
            <tr>
              <td>${escapeHtml(log.time || '-')}</td>
              <td>${escapeHtml(log.eventId || '-')}${isTestRecord(log) ? ' [TEST]' : ''}</td>
              <td>${escapeHtml(log.phone || '-')}</td>
              <td>${escapeHtml(log.templateUid || '-')}</td>
              <td>${escapeHtml(log.result || '-')}</td>
              <td>${escapeHtml(log.message || '-')}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
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

function isTestRecord(row) {
  if (!row || typeof row !== 'object') {
    return false;
  }

  if (row.isTest === true) {
    return true;
  }

  const values = [
    row.eventId,
    row.name,
    row.templateName,
    row.templateUid,
    row.bodyText,
    row.message,
    row.phone,
  ].map((value) => String(value || '').toLowerCase().trim());

  return values.some((value) => {
    if (!value) return false;
    if (value === '9876543210' || value === '9999999999') return true;
    return /(^|[^a-z])(test|smoke|qa|demo)([^a-z]|$)/i.test(value);
  });
}

function filterRecords(items, showTests) {
  if (showTests) {
    return items;
  }

  return items.filter((item) => !isTestRecord(item));
}

function normalizeDraftName(name, templateName, bodyText) {
  const value = String(name || '').trim();
  if (!value) {
    return value;
  }

  const probe = `${value} ${String(templateName || '')} ${String(bodyText || '')}`;
  if (/(^|[^a-z])(test|smoke|qa|demo)([^a-z]|$)/i.test(probe) && !/^\[TEST\]\s/i.test(value)) {
    return `[TEST] ${value}`;
  }

  return value;
}
