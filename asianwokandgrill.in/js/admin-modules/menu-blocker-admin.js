/**
 * Admin Module: Menu Blocker Settings
 * Controls menu blocker placement, staff code, WhatsApp number, and statistics
 */

const MenuBlockerAdminModule = (() => {
  let currentSettings = {};
  let statistics = null;
  let selectedDateRange = {
    startDate: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0],
  };

  const init = () => {
    renderUI();
    loadSettings();
    setupEventListeners();
  };

  const renderUI = () => {
    const container = document.getElementById('admin-content');
    if (!container) return;

    container.innerHTML = `
      <div class="admin-panel-menu-blocker">
        <h1>Menu Blocker Settings</h1>

        <!-- Settings Section -->
        <div class="mb-admin-section">
          <h2>Configuration</h2>
          
          <div class="mb-admin-row">
            <div class="mb-admin-field">
              <label>Staff Bypass Code</label>
              <input type="text" id="mb-staff-code" placeholder="e.g., AWG2024STAFF" maxlength="32" value="">
              <small>Staff can skip the spin wheel with this code</small>
            </div>
          </div>

          <div class="mb-admin-row">
            <div class="mb-admin-field">
              <label>Hotel WhatsApp Number</label>
              <input type="tel" id="mb-whatsapp-no" placeholder="+919876543210" value="">
              <small>Used for WhatsApp draft links (winner & try-again messages)</small>
            </div>
          </div>

          <div class="mb-admin-row">
            <div class="mb-admin-field mb-full-width">
              <label>Enable Menu Blocker</label>
              <div style="display: flex; gap: 10px; align-items: center; margin-top: 8px;">
                <input type="checkbox" id="mb-enabled" checked>
                <span id="mb-enabled-status">Enabled</span>
              </div>
            </div>
          </div>

          <div class="mb-admin-row">
            <div class="mb-admin-field mb-full-width">
              <label>Display on Pages</label>
              <div class="mb-page-selector" id="mb-page-selector">
                <!-- Populated by checkPages() -->
              </div>
            </div>
          </div>

          <div class="mb-admin-row">
            <button class="btn btn-primary" id="mb-save-settings">Save Settings</button>
            <button class="btn btn-secondary" id="mb-test-whatsapp">Test WhatsApp Link</button>
          </div>

          <div id="mb-settings-status" class="admin-status"></div>
        </div>

        <!-- Statistics Section -->
        <div class="mb-admin-section">
          <h2>Spin Statistics</h2>

          <div class="mb-admin-row">
            <div class="mb-admin-field">
              <label>Start Date</label>
              <input type="date" id="mb-stat-start-date" value="${selectedDateRange.startDate}">
            </div>
            <div class="mb-admin-field">
              <label>End Date</label>
              <input type="date" id="mb-stat-end-date" value="${selectedDateRange.endDate}">
            </div>
            <button class="btn btn-secondary" id="mb-refresh-stats">Refresh</button>
          </div>

          <div class="mb-stats-container">
            <div class="mb-stat-card">
              <div class="mb-stat-label">Total Spins</div>
              <div class="mb-stat-value" id="mb-total-spins">—</div>
            </div>
            <div class="mb-stat-card">
              <div class="mb-stat-label">Winners</div>
              <div class="mb-stat-value" id="mb-winners">—</div>
            </div>
            <div class="mb-stat-card">
              <div class="mb-stat-label">Try Again</div>
              <div class="mb-stat-value" id="mb-try-again">—</div>
            </div>
            <div class="mb-stat-card">
              <div class="mb-stat-label">Unique Players</div>
              <div class="mb-stat-value" id="mb-unique-players">—</div>
            </div>
          </div>

          <div class="mb-chart-container">
            <h3>Prize Distribution</h3>
            <div id="mb-prize-chart"></div>
          </div>
        </div>

        <!-- Customer Lookup Section -->
        <div class="mb-admin-section">
          <h2>Customer Lookup</h2>

          <div class="mb-admin-row">
            <div class="mb-admin-field">
              <label>Phone Number</label>
              <input type="tel" id="mb-lookup-phone" placeholder="9876543210" maxlength="20">
            </div>
            <button class="btn btn-secondary" id="mb-lookup-button">Search History</button>
          </div>

          <div class="mb-lookup-results" id="mb-lookup-results"></div>
        </div>
      </div>
    `;
  };

  const setupEventListeners = () => {
    document.getElementById('mb-save-settings')?.addEventListener('click', saveSettings);
    document.getElementById('mb-test-whatsapp')?.addEventListener('click', testWhatsAppLink);
    document.getElementById('mb-refresh-stats')?.addEventListener('click', refreshStats);
    document.getElementById('mb-lookup-button')?.addEventListener('click', lookupCustomer);
    document.getElementById('mb-enabled')?.addEventListener('change', updateEnabledStatus);
  };

  const loadSettings = async () => {
    try {
      const response = await fetch(buildPhpActionUrl('auth_get_menu_blocker_settings'));
      const result = await response.json();

      if (result.ok && result.settings) {
        currentSettings = result.settings;
        populateSettingsForm();
      }
    } catch (err) {
      console.error('Failed to load menu blocker settings:', err);
      showStatus('error', 'Failed to load settings');
    }
  };

  const populateSettingsForm = () => {
    const staffCodeInput = document.getElementById('mb-staff-code');
    const whatsappInput = document.getElementById('mb-whatsapp-no');
    const enabledCheckbox = document.getElementById('mb-enabled');
    const pageSelector = document.getElementById('mb-page-selector');

    if (staffCodeInput) staffCodeInput.value = currentSettings.menuBlockerStaffCode || '';
    if (whatsappInput) whatsappInput.value = currentSettings.hotelWhatsappNo || '';
    if (enabledCheckbox) enabledCheckbox.checked = currentSettings.enabled !== false;

    // Populate page selector
    if (pageSelector) {
      const availablePages = ['menu', 'home', 'cocktail', 'namaste_chef', 'namastemenu', 'reservation', 'contact', 'franchises'];
        const selectedPages = currentSettings.menuBlockerPages || {};

      pageSelector.innerHTML = availablePages.map(page => `
        <div class="mb-page-check">
            <input type="checkbox" value="${page}" ${selectedPages[page] ? 'checked' : ''}>
          <label>${capitalizeFirst(page)}</label>
        </div>
      `).join('');
    }

    updateEnabledStatus();
    refreshStats();
  };

  const saveSettings = async () => {
    const staffCode = document.getElementById('mb-staff-code')?.value || '';
    const whatsappNo = document.getElementById('mb-whatsapp-no')?.value || '';
    const enabled = document.getElementById('mb-enabled')?.checked !== false;
      const selectedPages = {};
      const availablePages = ['menu', 'home', 'cocktail', 'namaste_chef', 'namastemenu', 'reservation', 'contact', 'franchises'];
      availablePages.forEach(page => {
        const checkbox = Array.from(document.querySelectorAll('.mb-page-check input')).find(el => el.value === page);
        selectedPages[page] = checkbox ? checkbox.checked : false;
      });

    try {
      const response = await fetch(buildPhpActionUrl('auth_update_menu_blocker_settings'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          settings: {
            menuBlockerStaffCode: staffCode,
            hotelWhatsappNo: whatsappNo,
            menuBlockerPages: selectedPages,
            enabled: enabled,
          },
        }),
      });

      const result = await response.json();
      if (result.ok) {
        showStatus('success', 'Settings saved successfully');
        currentSettings = result.result;
      } else {
        showStatus('error', result.message || 'Failed to save settings');
      }
    } catch (err) {
      console.error('Error saving settings:', err);
      showStatus('error', 'Error saving settings');
    }
  };

  const testWhatsAppLink = () => {
    const whatsappNo = document.getElementById('mb-whatsapp-no')?.value || currentSettings.hotelWhatsappNo;
    const message = encodeURIComponent('Test message from Menu Blocker admin panel - System check ✓');
    const url = `https://wa.me/${whatsappNo.replace(/\D/g, '')}?text=${message}`;
    window.open(url, '_blank');
  };

  const refreshStats = async () => {
    const startDate = document.getElementById('mb-stat-start-date')?.value;
    const endDate = document.getElementById('mb-stat-end-date')?.value;

      try {
      const statsUrl = new URL(buildPhpActionUrl('auth_get_menu_blocker_stats'), window.location.origin);
      if (startDate) {
        statsUrl.searchParams.set('startDate', startDate);
      }
      if (endDate) {
        statsUrl.searchParams.set('endDate', endDate);
      }

      const response = await fetch(statsUrl.toString());
      const result = await response.json();

      if (result.ok && result.stats) {
        statistics = result.stats;
        renderStatistics();
      }
    } catch (err) {
      console.error('Failed to load statistics:', err);
      showStatus('error', 'Failed to load statistics');
    }
  };

  const renderStatistics = () => {
    if (!statistics || statistics.length === 0) {
      document.getElementById('mb-prize-chart').innerHTML = '<p>No data available</p>';
      return;
    }

    // Calculate totals
    const totalSpins = statistics.reduce((sum, row) => sum + row.prize_count, 0);
    const winners = statistics.filter(row => row.prize_label !== 'Try Again').reduce((sum, row) => sum + row.prize_count, 0);
    const tryAgain = statistics.find(row => row.prize_label === 'Try Again')?.prize_count || 0;
    const uniquePlayers = statistics[0]?.unique_players || 0;

    // Update cards
    document.getElementById('mb-total-spins').textContent = totalSpins.toLocaleString();
    document.getElementById('mb-winners').textContent = winners.toLocaleString();
    document.getElementById('mb-try-again').textContent = tryAgain.toLocaleString();
    document.getElementById('mb-unique-players').textContent = (uniquePlayers || '—');

    // Render pie chart
    renderPieChart(statistics);
  };

  const renderPieChart = (data) => {
    const container = document.getElementById('mb-prize-chart');
    if (!container) return;

    const prizeData = data.map(row => ({
      label: row.prize_label,
      value: row.prize_count,
    }));

    // Simple HTML table chart
    const chartHtml = `
      <div class="mb-chart-table">
        <table>
          <thead>
            <tr>
              <th>Prize</th>
              <th>Count</th>
              <th>Percentage</th>
            </tr>
          </thead>
          <tbody>
            ${prizeData.map(item => {
              const total = prizeData.reduce((sum, d) => sum + d.value, 0);
              const percentage = ((item.value / total) * 100).toFixed(1);
              return `
                <tr>
                  <td>${item.label}</td>
                  <td>${item.value}</td>
                  <td>
                    <div class="mb-bar">
                      <div class="mb-bar-fill" style="width: ${percentage}%"></div>
                      <span class="mb-bar-label">${percentage}%</span>
                    </div>
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;

    container.innerHTML = chartHtml;
  };

  const lookupCustomer = async () => {
    const phone = document.getElementById('mb-lookup-phone')?.value;
    if (!phone) {
      showStatus('error', 'Please enter a phone number');
      return;
    }

    try {
      const lookupUrl = new URL(buildPhpActionUrl('auth_get_menu_blocker_phone_history'), window.location.origin);
      lookupUrl.searchParams.set('phone', String(phone));

      const response = await fetch(lookupUrl.toString());
      const result = await response.json();

      if (result.ok && result.history) {
        renderLookupResults(result.history);
      } else {
        renderLookupResults([]);
      }
    } catch (err) {
      console.error('Failed to lookup customer:', err);
      showStatus('error', 'Failed to lookup customer');
    }
  };

  const renderLookupResults = (history) => {
    const container = document.getElementById('mb-lookup-results');
    if (!container) return;

    if (history.length === 0) {
      container.innerHTML = '<p>No spin history found</p>';
      return;
    }

    container.innerHTML = `
      <table class="mb-lookup-table">
        <thead>
          <tr>
            <th>Date & Time</th>
            <th>Prize</th>
            <th>Coupon Code</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          ${history.map(spin => `
            <tr>
              <td>${formatDate(spin.created_at)}</td>
              <td>${spin.prize_label}</td>
              <td><code>${spin.coupon_code}</code></td>
              <td><span class="status-badge status-${spin.status}">${capitalizeFirst(spin.status)}</span></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  };

  const updateEnabledStatus = () => {
    const enabled = document.getElementById('mb-enabled')?.checked;
    const statusEl = document.getElementById('mb-enabled-status');
    if (statusEl) {
      statusEl.textContent = enabled ? 'Enabled' : 'Disabled';
      statusEl.style.color = enabled ? '#65ff51' : '#ff6b6b';
    }
  };

  const showStatus = (type, message) => {
    const container = document.getElementById('mb-settings-status');
    if (!container) return;

    container.textContent = message;
    container.className = `admin-status status-${type}`;

    if (type === 'success') {
      setTimeout(() => {
        container.textContent = '';
        container.className = 'admin-status';
      }, 3000);
    }
  };

  const capitalizeFirst = (str) => str.charAt(0).toUpperCase() + str.slice(1);
  const formatDate = (dateStr) => new Date(dateStr).toLocaleString();

  return { init, loadSettings, refreshStats };
})();

// Register module
if (window.AdminModuleRegistry) {
  window.AdminModuleRegistry.register('menu-blocker', MenuBlockerAdminModule);
}
