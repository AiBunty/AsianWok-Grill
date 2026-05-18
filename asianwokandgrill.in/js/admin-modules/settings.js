import { adminApi } from './api-client.js';

export async function renderSettings(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Settings</h3>
      <form id="stForm" class="admin-form-grid">
        <div class="admin-form-group">
          <label for="stWhatsapp">Hotel WhatsApp Number</label>
          <input id="stWhatsapp" type="text" placeholder="e.g., +91 9999999999" />
          <small>Contact number for WhatsApp messaging and customer inquiries</small>
        </div>
        <div class="admin-form-group">
          <label for="stStaffCode">Menu Blocker Staff Code</label>
          <input id="stStaffCode" type="text" placeholder="Enter 4+ character code" />
          <small>Staff authentication code to access blocked menu items (minimum 4 characters)</small>
        </div>
        <div class="admin-form-group">
          <label for="stEventPasscode">Event Entry Passcode</label>
          <input id="stEventPasscode" type="text" placeholder="Enter 4+ character passcode" />
          <small>Passcode required for event registration and entry (minimum 4 characters)</small>
        </div>
        <div class="admin-form-actions">
          <button class="admin-button" type="submit">Save Settings</button>
        </div>
      </form>
      <div id="stStatus" class="admin-form-status">Loading settings...</div>
    </section>
  `;

  const status = container.querySelector('#stStatus');
  const form = container.querySelector('#stForm');

  async function load() {
    try {
      const payload = await adminApi('auth_get_app_settings');

      const settings = payload.settings || {};
      container.querySelector('#stWhatsapp').value = settings.hotelWhatsappNo || '';
      container.querySelector('#stStaffCode').value = settings.menuBlockerStaffCode || '';
      container.querySelector('#stEventPasscode').value = settings.eventEntryPasscode || '';

      status.textContent = 'Settings loaded.';
      status.classList.remove('error');
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const normalizedStaffCode = String(container.querySelector('#stStaffCode').value || '').trim().toUpperCase();
    const normalizedEventPasscode = String(container.querySelector('#stEventPasscode').value || '').trim().toUpperCase();
    if (normalizedStaffCode !== '' && normalizedStaffCode.length < 4) {
      status.textContent = 'Menu blocker staff code must be at least 4 characters.';
      status.classList.add('error');
      return;
    }
    if (normalizedEventPasscode !== '' && normalizedEventPasscode.length < 4) {
      status.textContent = 'Event entry passcode must be at least 4 characters.';
      status.classList.add('error');
      return;
    }

    try {
      await adminApi('auth_set_app_settings', {
        method: 'POST',
        body: {
          settings: {
            hotelWhatsappNo: container.querySelector('#stWhatsapp').value,
            menuBlockerStaffCode: normalizedStaffCode,
            eventEntryPasscode: normalizedEventPasscode,
          },
        },
      });
      status.textContent = 'Settings saved successfully.';
      status.classList.remove('error');
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
    }
  });

  await load();
}
