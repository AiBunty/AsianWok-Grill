import { adminApi } from './api-client.js';

export async function renderUsers(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>User Management</h3>
      <form id="usrChangePasswordForm" class="admin-form-grid" style="margin-bottom:12px;">
        <h4>Change My Password</h4>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="usrCurrentPassword" type="password" placeholder="Current password" required />
          <input id="usrNextPassword" type="password" placeholder="New password (min 8 chars)" required />
          <input id="usrConfirmPassword" type="password" placeholder="Confirm new password" required />
        </div>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Update Password</button>
        </div>
      </form>

      <form id="usrCreateForm" class="admin-form-grid" style="margin-bottom:12px;">
        <h4>Create User</h4>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="usrNewUsername" type="text" placeholder="Username (mobile)" required />
          <input id="usrNewDisplay" type="text" placeholder="Display name" required />
        </div>
        <div class="admin-form-grid admin-form-grid-inline">
          <input id="usrNewPassword" type="password" placeholder="Temporary password" required />
          <select id="usrNewRole">
            <option value="admin">Admin</option>
            <option value="superadmin">SuperAdmin</option>
          </select>
        </div>
        <div id="usrPermGrid" class="admin-form-grid admin-form-grid-inline">
          <label class="admin-checkbox"><input type="checkbox" value="dashboard" checked /> Dashboard</label>
          <label class="admin-checkbox"><input type="checkbox" value="cashier" checked /> Cashier</label>
          <label class="admin-checkbox"><input type="checkbox" value="verification" checked /> Verification</label>
          <label class="admin-checkbox"><input type="checkbox" value="eventManagement" checked /> Event Mgmt</label>
          <label class="admin-checkbox"><input type="checkbox" value="eventGuests" checked /> Event Guests</label>
          <label class="admin-checkbox"><input type="checkbox" value="eventScanner" checked /> Scanner</label>
          <label class="admin-checkbox"><input type="checkbox" value="menuEditor" checked /> Menu</label>
          <label class="admin-checkbox"><input type="checkbox" value="whatsapp" /> WhatsApp</label>
          <label class="admin-checkbox"><input type="checkbox" value="crm" /> CRM</label>
          <label class="admin-checkbox"><input type="checkbox" value="userManagement" /> User Management</label>
        </div>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Create User</button>
        </div>
      </form>

      <div id="usrStatus" class="admin-form-status">Loading users...</div>
      <div id="usrDetail"></div>
      <div id="usrPermEditor"></div>
      <div id="usrTable"></div>
    </section>
  `;

  const createForm = container.querySelector('#usrCreateForm');
  const changePasswordForm = container.querySelector('#usrChangePasswordForm');
  const status = container.querySelector('#usrStatus');
  const detail = container.querySelector('#usrDetail');
  const permEditor = container.querySelector('#usrPermEditor');
  const tableNode = container.querySelector('#usrTable');
  let usersCache = [];

  changePasswordForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const currentPassword = container.querySelector('#usrCurrentPassword').value;
    const newPassword = container.querySelector('#usrNextPassword').value;
    const confirmPassword = container.querySelector('#usrConfirmPassword').value;

    if (newPassword.length < 8) {
      status.textContent = 'New password must be at least 8 characters long.';
      status.classList.add('error');
      return;
    }

    if (newPassword !== confirmPassword) {
      status.textContent = 'New password and confirm password do not match.';
      status.classList.add('error');
      return;
    }

    try {
      await adminApi('auth_change_password', {
        method: 'POST',
        body: {
          currentPassword,
          newPassword,
        },
      });

      changePasswordForm.reset();
      status.textContent = 'Password changed successfully.';
      status.classList.remove('error');
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Failed to change password.';
      status.classList.add('error');
    }
  });

  async function loadUsers() {
    try {
      const [mePayload, listPayload] = await Promise.all([
        adminApi('auth_me'),
        adminApi('auth_list_users'),
      ]);

      const me = mePayload.user || {};
      const users = Array.isArray(listPayload.users) ? listPayload.users : [];
      usersCache = users;

      detail.innerHTML = `
        <div class="admin-detail-grid">
          <div><span>Username</span><strong>${escapeHtml(me.username || '-')}</strong></div>
          <div><span>Display Name</span><strong>${escapeHtml(me.displayName || '-')}</strong></div>
          <div><span>Role</span><strong>${escapeHtml(me.role || '-')}</strong></div>
          <div><span>Status</span><strong>${escapeHtml(me.status || '-')}</strong></div>
        </div>
      `;

      tableNode.innerHTML = buildUsersTable(users);
      bindUserActions(users);
      status.textContent = `${users.length} user(s) loaded.`;
      status.classList.remove('error');
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Unable to load users.';
      status.classList.add('error');
      tableNode.innerHTML = '';
    }
  }

  function bindUserActions(users) {
    const byId = new Map(users.map((user) => [String(user.id || ''), user]));

    Array.from(container.querySelectorAll('[data-user-perms]')).forEach((button) => {
      button.addEventListener('click', () => {
        const id = String(button.dataset.userPerms || '');
        const row = byId.get(id);
        if (!row) return;

        renderPermissionEditor(row);
      });
    });

    Array.from(container.querySelectorAll('[data-user-toggle]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const id = String(button.dataset.userToggle || '');
        const row = byId.get(id);
        if (!row) return;

        const nextStatus = String(row.status || '').toLowerCase() === 'active' ? 'disabled' : 'active';
        try {
          await adminApi('auth_set_user_status', {
            method: 'POST',
            body: { id: Number(id), status: nextStatus },
          });
          status.textContent = 'User status updated.';
          status.classList.remove('error');
          await loadUsers();
        } catch (error) {
          status.textContent = error instanceof Error ? error.message : 'Failed to update status.';
          status.classList.add('error');
        }
      });
    });

    Array.from(container.querySelectorAll('[data-user-reset]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const id = String(button.dataset.userReset || '');
        const tempPassword = window.prompt('Enter temporary password for this user:');
        if (!id || !tempPassword) return;

        try {
          await adminApi('auth_reset_password', {
            method: 'POST',
            body: { id: Number(id), newPassword: tempPassword },
          });
          status.textContent = 'Password reset completed.';
          status.classList.remove('error');
        } catch (error) {
          status.textContent = error instanceof Error ? error.message : 'Failed to reset password.';
          status.classList.add('error');
        }
      });
    });

    Array.from(container.querySelectorAll('[data-user-delete]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const id = String(button.dataset.userDelete || '');
        if (!id || !window.confirm('Delete this user account?')) return;

        try {
          await adminApi('auth_delete_user', {
            method: 'POST',
            body: { id: Number(id) },
          });
          status.textContent = 'User deleted.';
          status.classList.remove('error');
          await loadUsers();
        } catch (error) {
          status.textContent = error instanceof Error ? error.message : 'Failed to delete user.';
          status.classList.add('error');
        }
      });
    });
  }

  function renderPermissionEditor(user) {
    const current = Array.isArray(user.permissions) ? user.permissions : [];
    const options = [
      'dashboard', 'cashier', 'verification', 'eventManagement', 'eventGuests', 'eventScanner',
      'menuEditor', 'crm', 'whatsapp', 'settings', 'userManagement', 'diagnostics'
    ];

    permEditor.innerHTML = `
      <section class="admin-module-card" style="margin-top:10px;">
        <h4>Edit Permissions - ${escapeHtml(user.username || user.displayName || 'user')}</h4>
        <div id="usrEditPermGrid" class="admin-form-grid admin-form-grid-inline">
          ${options.map((key) => `<label class="admin-checkbox"><input type="checkbox" value="${escapeHtml(key)}" ${current.includes(key) ? 'checked' : ''} /> ${escapeHtml(key)}</label>`).join('')}
        </div>
        <div class="admin-form-actions">
          <button id="usrSavePerms" class="admin-button" type="button">Save Permissions</button>
        </div>
      </section>
    `;

    permEditor.querySelector('#usrSavePerms')?.addEventListener('click', async () => {
      const selected = Array.from(permEditor.querySelectorAll('#usrEditPermGrid input[type="checkbox"]:checked')).map((node) => node.value);
      try {
        await adminApi('auth_set_user_permissions', {
          method: 'POST',
          body: { id: Number(user.id || 0), permissions: selected },
        });
        status.textContent = 'User permissions updated.';
        status.classList.remove('error');
        await loadUsers();
      } catch (error) {
        status.textContent = error instanceof Error ? error.message : 'Failed to save permissions.';
        status.classList.add('error');
      }
    });
  }

  createForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const permissions = Array.from(container.querySelectorAll('#usrPermGrid input[type="checkbox"]:checked')).map((node) => node.value);
    try {
      await adminApi('auth_create_user', {
        method: 'POST',
        body: {
          username: container.querySelector('#usrNewUsername').value.trim(),
          displayName: container.querySelector('#usrNewDisplay').value.trim(),
          password: container.querySelector('#usrNewPassword').value,
          role: container.querySelector('#usrNewRole').value,
          permissions,
        },
      });

      createForm.reset();
      Array.from(container.querySelectorAll('#usrPermGrid input[type="checkbox"]')).forEach((node) => {
        node.checked = ['dashboard', 'cashier', 'verification', 'eventManagement', 'eventGuests', 'eventScanner', 'menuEditor'].includes(node.value);
      });

      status.textContent = 'User created successfully.';
      status.classList.remove('error');
      await loadUsers();
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Failed to create user.';
      status.classList.add('error');
    }
  });

  await loadUsers();
}

function buildUsersTable(users) {
  if (!users.length) {
    return '<div class="admin-module-empty">No users found.</div>';
  }

  const rows = users.map((user) => {
    const id = Number(user.id || 0);
    return `
      <tr>
        <td>${escapeHtml(user.username || '-')}</td>
        <td>${escapeHtml(user.displayName || '-')}</td>
        <td>${escapeHtml(user.role || '-')}</td>
        <td>${escapeHtml(user.status || '-')}</td>
        <td>
          <button class="admin-table-button" data-user-perms="${id}">Permissions</button>
          <button class="admin-table-button" data-user-toggle="${id}">Toggle Status</button>
          <button class="admin-table-button" data-user-reset="${id}">Reset Password</button>
          <button class="admin-table-button admin-table-button-danger" data-user-delete="${id}">Delete</button>
        </td>
      </tr>
    `;
  }).join('');

  return `
    <div class="admin-module-table-wrap">
      <table class="admin-module-table">
        <thead><tr><th>Username</th><th>Display Name</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>${rows}</tbody>
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
