/**
 * NK Admin SPA Module: CRM Leads
 */
(function (NK, window) {
  'use strict';

  NK.MODULES = NK.MODULES || {};

  NK.MODULES['crm-leads'] = {
    _container: null,
    _authClient: null,
    _phpApiUrl: null,
    _page: 1,
    _pageSize: 25,

    init: function (container, authClient) {
      this._container = container;
      this._authClient = authClient;
      this._phpApiUrl = (window.NK_DATA_API && window.NK_DATA_API.phpApiUrl) || '';
      this._page = 1;

      container.innerHTML = this._buildHtml();
      this._bindEvents();
      this._loadWorkspace();
    },

    destroy: function () {
      this._container = null;
      this._authClient = null;
      this._phpApiUrl = null;
      this._page = 1;
    },

    _buildHtml: function () {
      return ''
        + '<main class="page" style="max-width:1340px;">'
        + '  <section class="hero">'
        + '    <h1>CRM Leads</h1>'
        + '    <p>Review every raw lead received, see the exact prize text, identify Won versus Try Again outcomes, and export filtered lead history to Excel.</p>'
        + '  </section>'
        + '  <section class="panel">'
        + '    <div class="row" style="justify-content:space-between;align-items:flex-start;gap:18px;">'
        + '      <div>'
        + '        <h3 style="margin:0 0 6px;">Lead Summary</h3>'
        + '        <div id="crmLeadsSummary" class="status muted">Loading lead summary…</div>'
        + '      </div>'
        + '      <div class="row" style="gap:10px;flex-wrap:wrap;">'
        + '        <button id="crmLeadsRefreshBtn" class="secondary" type="button">Refresh Leads</button>'
        + '        <button id="crmLeadsExportBtn" class="secondary" type="button">Download Excel</button>'
        + '      </div>'
        + '    </div>'
        + '  </section>'
        + '  <section class="panel">'
        + '    <div class="row" style="justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">'
        + '      <h3 style="margin:0;">Lead Filters</h3>'
        + '      <div class="row" style="gap:10px;flex-wrap:wrap;">'
        + '        <input id="crmLeadsFilterSearch" type="text" maxlength="100" placeholder="Search by mobile, name, coupon">'
        + '        <input id="crmLeadsFilterSource" type="text" maxlength="60" placeholder="Source">'
        + '        <select id="crmLeadsFilterOutcome">'
        + '          <option value="">All Outcomes</option>'
        + '          <option value="Won">Won</option>'
        + '          <option value="Try Again">Try Again</option>'
        + '        </select>'
        + '        <select id="crmLeadsFilterLeadStatus">'
        + '          <option value="">All Lead Status</option>'
        + '          <option value="Unredeemed">Unredeemed</option>'
        + '          <option value="Redeemed">Redeemed</option>'
        + '        </select>'
        + '        <select id="crmLeadsFilterSyncStatus">'
        + '          <option value="">All CRM Sync Status</option>'
        + '          <option value="Pending">Pending</option>'
        + '          <option value="Success">Success</option>'
        + '          <option value="Failed">Failed</option>'
        + '          <option value="Skipped">Skipped</option>'
        + '        </select>'
        + '        <input id="crmLeadsFilterFromDate" type="date">'
        + '        <input id="crmLeadsFilterToDate" type="date">'
        + '        <button id="crmLeadsApplyBtn" type="button">Apply</button>'
        + '        <button id="crmLeadsResetBtn" class="secondary" type="button">Reset</button>'
        + '      </div>'
        + '    </div>'
        + '    <div id="crmLeadsStatus" class="status muted" style="margin-top:10px;">Loading CRM leads…</div>'
        + '  </section>'
        + '  <section class="panel">'
        + '    <div class="row" style="justify-content:space-between;align-items:center;gap:14px;">'
        + '      <h3 style="margin:0;">All Leads Received</h3>'
        + '      <div id="crmLeadsPagination" class="status muted">Loading leads…</div>'
        + '    </div>'
        + '    <div class="table-wrap" style="margin-top:12px;">'
        + '      <table>'
        + '        <thead>'
        + '          <tr>'
        + '            <th>When</th>'
        + '            <th>Mobile</th>'
        + '            <th>Name</th>'
        + '            <th>Prize</th>'
        + '            <th>Outcome</th>'
        + '            <th>Coupon</th>'
        + '            <th>Status</th>'
        + '            <th>Redeemed</th>'
        + '            <th>Source</th>'
        + '            <th>CRM</th>'
        + '          </tr>'
        + '        </thead>'
        + '        <tbody id="crmLeadsRows"></tbody>'
        + '      </table>'
        + '    </div>'
        + '    <div class="row" style="justify-content:flex-end;gap:10px;margin-top:12px;">'
        + '      <button id="crmLeadsPrevBtn" class="secondary" type="button">Previous</button>'
        + '      <button id="crmLeadsNextBtn" class="secondary" type="button">Next</button>'
        + '    </div>'
        + '  </section>'
        + '</main>';
    },

    _bindEvents: function () {
      var c = this._container;
      if (!c) return;
      var self = this;
      var triggerApply = function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          self._page = 1;
          self._loadWorkspace();
        }
      };

      c.querySelector('#crmLeadsRefreshBtn').addEventListener('click', function () {
        self._loadWorkspace();
      });
      c.querySelector('#crmLeadsApplyBtn').addEventListener('click', function () {
        self._page = 1;
        self._loadWorkspace();
      });
      c.querySelector('#crmLeadsResetBtn').addEventListener('click', function () {
        self._resetFilters();
        self._page = 1;
        self._loadWorkspace();
      });
      c.querySelector('#crmLeadsPrevBtn').addEventListener('click', function () {
        if (self._page > 1) {
          self._page -= 1;
          self._loadLeads();
        }
      });
      c.querySelector('#crmLeadsNextBtn').addEventListener('click', function () {
        self._page += 1;
        self._loadLeads();
      });
      c.querySelector('#crmLeadsExportBtn').addEventListener('click', function () {
        self._downloadExport();
      });

      ['#crmLeadsFilterSearch', '#crmLeadsFilterSource', '#crmLeadsFilterFromDate', '#crmLeadsFilterToDate'].forEach(function (selector) {
        var element = c.querySelector(selector);
        if (element) {
          element.addEventListener('keydown', triggerApply);
        }
      });
    },

    _loadWorkspace: async function () {
      await Promise.all([this._loadSummary(), this._loadLeads()]);
    },

    _collectFilters: function () {
      var c = this._container;
      if (!c) return {};
      return {
        search: c.querySelector('#crmLeadsFilterSearch').value.trim(),
        source: c.querySelector('#crmLeadsFilterSource').value.trim(),
        outcome: c.querySelector('#crmLeadsFilterOutcome').value,
        leadStatus: c.querySelector('#crmLeadsFilterLeadStatus').value,
        syncStatus: c.querySelector('#crmLeadsFilterSyncStatus').value,
        fromDate: c.querySelector('#crmLeadsFilterFromDate').value,
        toDate: c.querySelector('#crmLeadsFilterToDate').value
      };
    },

    _resetFilters: function () {
      var c = this._container;
      if (!c) return;
      c.querySelector('#crmLeadsFilterSearch').value = '';
      c.querySelector('#crmLeadsFilterSource').value = '';
      c.querySelector('#crmLeadsFilterOutcome').value = '';
      c.querySelector('#crmLeadsFilterLeadStatus').value = '';
      c.querySelector('#crmLeadsFilterSyncStatus').value = '';
      c.querySelector('#crmLeadsFilterFromDate').value = '';
      c.querySelector('#crmLeadsFilterToDate').value = '';
    },

    _loadSummary: async function () {
      var c = this._container;
      if (!c) return;
      var summaryEl = c.querySelector('#crmLeadsSummary');
      summaryEl.textContent = 'Loading lead summary…';
      try {
        var payload = await this._authClient.apiPost(Object.assign({ action: 'admin_crm_leads_status' }, this._collectFilters()));
        var summary = payload && payload.summary ? payload.summary : {};
        summaryEl.textContent = 'Total leads: ' + String(summary.totalLeads || 0)
          + ' | Won: ' + String(summary.totalWon || 0)
          + ' | Try Again: ' + String(summary.totalTryAgain || 0)
          + ' | Redeemed: ' + String(summary.totalRedeemed || 0);
      } catch (err) {
        summaryEl.textContent = 'Failed to load lead summary: ' + (err.message || err);
      }
    },

    _loadLeads: async function () {
      var c = this._container;
      if (!c) return;
      var statusEl = c.querySelector('#crmLeadsStatus');
      statusEl.textContent = 'Loading CRM leads…';

      try {
        var payload = await this._authClient.apiPost(Object.assign({
          action: 'admin_list_crm_leads',
          page: this._page,
          pageSize: this._pageSize
        }, this._collectFilters()));

        var pagination = payload && payload.pagination ? payload.pagination : {};
        var leads = payload && payload.leads ? payload.leads : [];
        if (pagination.pages && this._page > pagination.pages) {
          this._page = pagination.pages;
          return this._loadLeads();
        }

        this._renderLeads(leads);
        this._renderPagination(pagination);
        statusEl.textContent = 'Showing ' + String(leads.length || 0) + ' lead(s) on this page.';
      } catch (err) {
        this._renderLeads([]);
        this._renderPagination({ page: this._page, pages: this._page, total: 0 });
        statusEl.textContent = 'Failed to load CRM leads: ' + (err.message || err);
      }
    },

    _renderLeads: function (leads) {
      var c = this._container;
      if (!c) return;
      var bodyEl = c.querySelector('#crmLeadsRows');
      var esc = NK.MODULE_BASE.escHtml.bind(NK.MODULE_BASE);

      if (!Array.isArray(leads) || !leads.length) {
        bodyEl.innerHTML = '<tr><td colspan="10" class="muted">No leads match the current filters.</td></tr>';
        return;
      }

      bodyEl.innerHTML = leads.map(function (row) {
        return '<tr>'
          + '<td style="white-space:nowrap;">' + esc(String(row.createdAt || '-')) + '</td>'
          + '<td>' + esc(String(row.phone || '-')) + '</td>'
          + '<td>' + esc(String(row.name || '-')) + '</td>'
          + '<td style="min-width:220px;">' + esc(String(row.prize || '-')) + '</td>'
          + '<td>' + NK.MODULES['crm-leads']._badgeHtml(String(row.outcomeBadge || 'Pending'), 'outcome') + '</td>'
          + '<td>' + esc(String(row.couponCode || '-')) + '</td>'
          + '<td>' + NK.MODULES['crm-leads']._badgeHtml(String(row.status || '-'), 'leadStatus') + '</td>'
          + '<td style="white-space:nowrap;">' + esc(String(row.redeemedAt || '-')) + '</td>'
          + '<td>' + esc(String(row.source || '-')) + '</td>'
          + '<td>' + NK.MODULES['crm-leads']._badgeHtml(String(row.crmSyncStatus || '-'), 'sync') + '</td>'
          + '</tr>';
      }).join('');
    },

    _badgeHtml: function (label, type) {
      var normalized = String(label || '-');
      var tone = '#54616f';

      if (type === 'outcome') {
        tone = normalized === 'Won' ? '#0f9d58' : (normalized === 'Try Again' ? '#c62828' : '#8a6d1f');
      } else if (type === 'leadStatus') {
        tone = normalized === 'Redeemed' ? '#1565c0' : '#b26a00';
      } else if (type === 'sync') {
        tone = normalized === 'Success'
          ? '#0f9d58'
          : (normalized === 'Failed' ? '#c62828' : (normalized === 'Pending' ? '#b26a00' : '#54616f'));
      }

      return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:' + tone + '14;color:' + tone + ';font-weight:700;">'
        + NK.MODULE_BASE.escHtml(normalized)
        + '</span>';
    },

    _renderPagination: function (pagination) {
      var c = this._container;
      if (!c) return;
      var page = Number(pagination.page || 1);
      var pages = Number(pagination.pages || 1);
      var total = Number(pagination.total || 0);

      c.querySelector('#crmLeadsPagination').textContent = 'Page ' + page + ' of ' + pages + ' | Total leads: ' + total;
      c.querySelector('#crmLeadsPrevBtn').disabled = page <= 1;
      c.querySelector('#crmLeadsNextBtn').disabled = page >= pages;
    },

    _downloadExport: async function () {
      var c = this._container;
      if (!c || !this._phpApiUrl) return;
      var statusEl = c.querySelector('#crmLeadsStatus');
      statusEl.textContent = 'Preparing CRM lead export…';

      try {
        var blob = await this._blobPost(Object.assign({ action: 'admin_export_crm_leads' }, this._collectFilters()));
        var url = window.URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'crm_leads.xlsx';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
        statusEl.textContent = 'CRM lead export downloaded.';
      } catch (err) {
        statusEl.textContent = 'Export failed: ' + (err.message || err);
      }
    },

    _blobPost: async function (payload) {
      var token = this._authClient && this._authClient.token ? this._authClient.token : '';
      if (!token) {
        throw new Error('Admin session expired. Please login again.');
      }

      var response = await window.fetch(this._phpApiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + token
        },
        body: JSON.stringify(payload)
      });

      if (!response.ok) {
        throw new Error('HTTP ' + response.status + ' while downloading export');
      }

      return response.blob();
    }
  };
})(window.NK || (window.NK = {}), window);