import { adminApi } from './api-client.js';

export async function renderDashboard(container) {
  container.innerHTML = '<div class="admin-module-loading">Loading dashboard...</div>';

  try {
    const payload = await adminApi('admin_dashboard_summary');
    const summary = payload.summary || {};
    const recent = Array.isArray(payload.recentLeads) ? payload.recentLeads : [];

    container.innerHTML = `
      <section class="admin-module-card-grid">
        <article class="admin-mini-card"><h4>Total Leads</h4><strong>${summary.totalLeads || 0}</strong></article>
        <article class="admin-mini-card"><h4>Redeemed</h4><strong>${summary.redeemedLeads || 0}</strong></article>
        <article class="admin-mini-card"><h4>Unredeemed</h4><strong>${summary.unredeemedLeads || 0}</strong></article>
      </section>
      <section class="admin-module-card">
        <h3>Recent Leads</h3>
        ${buildRecentTable(recent)}
      </section>
    `;
  } catch (error) {
    container.innerHTML = `<div class="admin-menu-error">${error.message}</div>`;
  }
}

function buildRecentTable(rows) {
  if (!rows.length) {
    return '<div class="admin-module-empty">No leads yet.</div>';
  }

  const body = rows.slice(0, 15).map((item) => `
    <tr>
      <td>${escapeHtml(item.name)}</td>
      <td>${escapeHtml(item.phone)}</td>
      <td>${escapeHtml(item.activeRewardLabel || item.prize || '-')}</td>
      <td>${escapeHtml(item.status)}</td>
      <td>${escapeHtml(item.source || '-')}</td>
      <td>${escapeHtml(item.createdAt || '-')}</td>
    </tr>
  `).join('');

  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>Name</th><th>Phone</th><th>Reward</th><th>Status</th><th>Source</th><th>Created</th></tr></thead>
        <tbody>${body}</tbody>
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
