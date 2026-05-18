import { buildPhpActionUrl } from '../runtime-config.js?v=20260510c';
import { adminApi } from './api-client.js?v=20260511b';
import { renderDashboard } from './dashboard.js?v=20260510c';
import { renderVerification } from './verification.js?v=20260510n';
import { renderCrmPanel, renderCrmLeads } from './crm.js?v=20260513a';
import { renderUsers } from './users.js?v=20260511b';
import { renderSettings } from './settings.js?v=20260510c';
import { renderDiagnostics } from './diagnostics.js?v=20260510c';
import { renderEvents } from './events.js?v=20260510n';
import { renderWhatsappCloud } from './whatsapp-cloud.js?v=20260510c';
import { renderCashier } from './cashier.js?v=20260510h';
import { renderCashApprovals } from './cash-approvals.js?v=20260510h';
import { renderEventGuests } from './event-guests.js?v=20260512a';
import { renderEventEntryScanner } from './event-entry-scanner.js?v=20260512a';
import { renderDataImport } from './data-import.js?v=20260510i';
import { renderLandingRouting } from './landing-routing.js?v=20260512c';
import { renderQrCodeCenter } from './qr-code.js?v=20260512a';
import { renderMenuEditor } from './menu-editor-shell.js?v=20260511a';
import { renderMenuCategoryDesigner } from './menu-category-designer-shell.js?v=20260511a';
import { renderSpinOfferControl } from './spin-offer-control.js?v=20260510l';

const moduleGroupOrder = ['Operations', 'Events', 'Rewards', 'Menu', 'Growth', 'Routing & Access', 'System'];

const modules = [
  { key: 'dashboard', title: 'Dashboard', group: 'Operations', description: 'Overview and quick module launch.' },
  { key: 'cashier', title: 'Cashier', group: 'Operations', description: 'Issue cash passes and handovers.' },
  { key: 'verification', title: 'Coupon Verification', group: 'Operations', description: 'Verify and redeem coupons.' },
  { key: 'cash-approvals', title: 'Cash Approvals', group: 'Operations', description: 'SuperAdmin cash approval desk.' },
  { key: 'event-management', title: 'Event Management', group: 'Events', description: 'Create and manage events.' },
  { key: 'event-guests', title: 'Event Guests', group: 'Events', description: 'Registration and guest reports.' },
  { key: 'event-entry-scanner', title: 'Event Entry Scanner', group: 'Events', description: 'Batch QR scanner for event gate entry.' },
  { key: 'spin-offer-control', title: 'Spin Wheel Offer Control', group: 'Rewards', description: 'Manage weighted spin rewards and coupon behavior.' },
  { key: 'menu-editor', title: 'Menu Bulk Editor', group: 'Menu', description: 'Sheet-style editor for menu and cocktail rows.' },
  { key: 'menu-category-designer', title: 'Menu Category Designer', group: 'Menu', description: 'Design Food and Cocktail category/item order.' },
  { key: 'data-import', title: 'Data Import / Export', group: 'Menu', description: 'Import/export workflows and snapshots.' },
  { key: 'crm-panel', title: 'CRM Sync Panel', group: 'Growth', description: 'Send controlled lead to CRM and inspect payload/response.' },
  { key: 'crm-leads', title: 'CRM Leads', group: 'Growth', description: 'Review raw leads and export outcomes.' },
  { key: 'whatsapp-cloud', title: 'WhatsApp Cloud API', group: 'Growth', description: 'Configure Meta Cloud API and event mappings.' },
  { key: 'landing-routing', title: 'Routing & QR Center', group: 'Routing & Access', description: 'Control blocker and QR routing behavior.' },
  { key: 'qr-code-center', title: 'QR Code Center', group: 'Routing & Access', description: 'Generate, download, and share stable QR links.' },
  { key: 'settings', title: 'Passcode / WhatsApp Settings', group: 'Routing & Access', description: 'Integration settings and security controls.' },
  { key: 'user-management', title: 'User Management', group: 'System', description: 'Create users and assign access.' },
  { key: 'diagnostics', title: 'Diagnostics', group: 'System', description: 'Backend and infrastructure diagnostics.' }
];

const pageTitle = document.getElementById('adminPageTitle');
const moduleMount = document.getElementById('adminModuleMount');
const backendHealthTitle = document.getElementById('adminBackendHealthTitle');
const backendHealthBody = document.getElementById('adminBackendHealthBody');
const backendStatusText = document.getElementById('adminBackendStatusText');
const navRoot = document.getElementById('adminNavRoot');
const logoutButton = document.getElementById('adminLogoutButton');
const globalLoader = document.getElementById('adminGlobalLoader');
const adminToken = localStorage.getItem('awg_admin_token');
const ADMIN_IDLE_LOGOUT_MS = 30 * 60 * 1000;
let moduleRenderPending = 0;
let loaderTimer = null;
let idleLogoutTimer = null;
let idleLogoutInProgress = false;

if (!adminToken) {
  window.location.href = '/admin/login.html';
}

async function performIdleLogout() {
  if (idleLogoutInProgress) {
    return;
  }

  idleLogoutInProgress = true;
  try {
    await adminApi('auth_logout', { method: 'POST' });
  } catch (_) {
    // Clear client session even if backend logout fails.
  } finally {
    localStorage.removeItem('awg_admin_token');
    window.location.href = '/admin/login.html?idle=true';
  }
}

function resetIdleLogoutTimer() {
  if (idleLogoutInProgress) {
    return;
  }

  if (idleLogoutTimer) {
    window.clearTimeout(idleLogoutTimer);
  }

  idleLogoutTimer = window.setTimeout(() => {
    performIdleLogout();
  }, ADMIN_IDLE_LOGOUT_MS);
}

function setupIdleAutoLogout() {
  const activityEvents = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
  activityEvents.forEach((eventName) => {
    window.addEventListener(eventName, resetIdleLogoutTimer, { passive: true });
  });

  window.addEventListener('focus', resetIdleLogoutTimer);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      resetIdleLogoutTimer();
    }
  });

  resetIdleLogoutTimer();
}

function updateGlobalLoader() {
  if (!globalLoader) return;

  const apiPending = Number(globalLoader.dataset.apiPending || '0');
  const shouldShow = moduleRenderPending > 0 || apiPending > 0;

  if (shouldShow) {
    if (loaderTimer) {
      window.clearTimeout(loaderTimer);
      loaderTimer = null;
    }
    globalLoader.hidden = false;
    return;
  }

  loaderTimer = window.setTimeout(() => {
    globalLoader.hidden = true;
  }, 120);
}

window.addEventListener('awg:admin:loading', (event) => {
  const pending = Number(event?.detail?.pendingRequests || 0);
  if (globalLoader) {
    globalLoader.dataset.apiPending = String(pending);
  }
  updateGlobalLoader();
});

async function renderActiveModule(activeKey) {
  if (!moduleMount) return;
  const renderers = {
    dashboard: renderDashboard,
    cashier: renderCashier,
    verification: renderVerification,
    'cash-approvals': renderCashApprovals,
    'event-management': renderEvents,
    'event-guests': renderEventGuests,
    'event-entry-scanner': renderEventEntryScanner,
    'spin-offer-control': renderSpinOfferControl,
    'menu-editor': renderMenuEditor,
    'menu-category-designer': renderMenuCategoryDesigner,
    'data-import': renderDataImport,
    'crm-panel': renderCrmPanel,
    'crm-leads': renderCrmLeads,
    'landing-routing': renderLandingRouting,
    'qr-code-center': renderQrCodeCenter,
    'user-management': renderUsers,
    settings: renderSettings,
    'whatsapp-cloud': renderWhatsappCloud,
    diagnostics: renderDiagnostics
  };

  const renderer = renderers[activeKey];

  if (!renderer) {
    renderModuleList(activeKey);
    return;
  }

  moduleRenderPending += 1;
  updateGlobalLoader();
  try {
    await renderer(moduleMount);
  } finally {
    moduleRenderPending = Math.max(0, moduleRenderPending - 1);
    updateGlobalLoader();
  }
}

function renderModuleList(activeKey) {
  if (!moduleMount) return;

  moduleMount.innerHTML = modules
    .map((module) => {
      const status = module.key === activeKey ? 'Current shell focus' : 'Planned in next phases';
      return `
        <div class="admin-module-item">
          <div>
            <strong>${module.title}</strong>
            <div>${module.description}</div>
          </div>
          <span>${status}</span>
        </div>
      `;
    })
    .join('');
}

function renderNav() {
  if (!navRoot) return;

  const groups = moduleGroupOrder.map((groupName) => ({
    groupName,
    items: modules.filter((module) => module.group === groupName)
  })).filter((group) => group.items.length > 0);

  navRoot.innerHTML = groups.map((group) => `
    <section class="admin-nav-group">
      <div class="admin-nav-group-title">${group.groupName}</div>
      ${group.items.map((item) => `
        <button class="admin-nav-link" type="button" data-module="${item.key}">
          <span>${item.title}</span>
          <small>${item.description}</small>
        </button>
      `).join('')}
    </section>
  `).join('');

  Array.from(navRoot.querySelectorAll('.admin-nav-link')).forEach((button) => {
    button.addEventListener('click', () => {
      setActiveModule(button.dataset.module || 'dashboard');
    });
  });
}

function setActiveModule(key) {
  const activeModule = modules.find((module) => module.key === key) || modules[0];
  if (pageTitle) {
    pageTitle.textContent = activeModule.title;
  }

  Array.from(document.querySelectorAll('.admin-nav-link')).forEach((button) => {
    button.classList.toggle('is-active', button.dataset.module === activeModule.key);
  });

  renderActiveModule(activeModule.key).catch((error) => {
    if (!moduleMount) return;
    moduleMount.innerHTML = `
      <div class="admin-menu-error">
        ${error instanceof Error ? error.message : 'Unable to render the selected module.'}
      </div>
    `;
  });
}

async function bootstrapSession() {
  const response = await fetch(buildPhpActionUrl('auth_me'), {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${adminToken || ''}`
    }
  });

  const payload = await response.json();
  if (!response.ok || !payload.ok) {
    localStorage.removeItem('awg_admin_token');
    window.location.href = '/admin/login.html';
    throw new Error(payload.message || 'Admin session is not valid.');
  }

  return payload.user;
}

async function checkBackend() {
  try {
    await bootstrapSession();
    const response = await fetch(buildPhpActionUrl('auth_bootstrap_status'), {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${adminToken || ''}`
      }
    });

    const payload = await response.json();
    if (!response.ok) {
      throw new Error(payload.message || 'Backend bootstrap check failed.');
    }

    if (payload.ok) {
      if (backendHealthTitle) {
        backendHealthTitle.textContent = payload.bootstrapRequired ? 'Bootstrap required' : 'Auth layer ready';
      }
      if (backendHealthBody) {
        backendHealthBody.textContent = payload.bootstrapRequired
          ? `Database is reachable. Users: ${payload.userCount}. Superadmins: ${payload.superadminCount}. Initial admin bootstrap is still required.`
          : `Database is reachable. Users: ${payload.userCount}. Superadmins: ${payload.superadminCount}.`;
      }
      if (backendStatusText) {
        backendStatusText.textContent = payload.bootstrapRequired ? 'Backend connected, bootstrap pending' : 'Backend connected';
      }
      return;
    }

    if (backendHealthTitle) {
      backendHealthTitle.textContent = 'Backend configured, DB pending';
    }
    if (backendHealthBody) {
      backendHealthBody.textContent = payload.message || 'Database configuration is not ready yet.';
    }
    if (backendStatusText) {
      backendStatusText.textContent = 'Backend connected, database pending';
    }
  } catch (error) {
    if (backendHealthTitle) {
      backendHealthTitle.textContent = 'Backend not ready';
    }
    if (backendHealthBody) {
      backendHealthBody.textContent = error instanceof Error ? error.message : 'Unable to reach backend.';
    }
    if (backendStatusText) {
      backendStatusText.textContent = 'Backend connection failed';
    }
  }
}

renderNav();
setActiveModule('dashboard');
checkBackend();
setupIdleAutoLogout();

logoutButton?.addEventListener('click', async () => {
  if (idleLogoutTimer) {
    window.clearTimeout(idleLogoutTimer);
    idleLogoutTimer = null;
  }

  try {
    await adminApi('auth_logout', { method: 'POST' });
  } catch (_) {
    // Clear client session even if backend logout fails.
  } finally {
    localStorage.removeItem('awg_admin_token');
    window.location.href = '/admin/login.html';
  }
});
