(function () {
  'use strict';

  var VISIT_COUNT_KEY = 'awg:menu-gate:visit-count';
  var COMPLETED_UNTIL_KEY = 'awg:menu-gate:completed-until';
  var DEFAULT_COOLDOWN_HOURS = 24;
  var API_BASE = (window.location && window.location.protocol === 'file:')
    ? 'http://127.0.0.1:8090/'
    : '/';
  var SETTINGS_ENDPOINT = API_BASE.replace(/\/?$/, '/') + '?action=get_blocker_settings';
  var PASSCODE_ENDPOINT = API_BASE.replace(/\/?$/, '/') + '?action=public_verify_blocker_passcode';
  var blockerSettingsPromise = null;

  function getNextVisitCount() {
    try {
      var current = parseInt(localStorage.getItem(VISIT_COUNT_KEY) || '0', 10);
      if (isNaN(current) || current < 0) current = 0;
      var next = current + 1;
      localStorage.setItem(VISIT_COUNT_KEY, String(next));
      return next;
    } catch (e) {
      return 1;
    }
  }

  function removeGate() {
    var overlay = document.getElementById('menuGateOverlay');
    if (overlay) {
      overlay.style.opacity = '0';
      overlay.style.transition = 'opacity 0.3s';
      setTimeout(function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }, 320);
    }
    document.body.style.overflow = '';
  }

  function getCompletedUntil() {
    try {
      var raw = localStorage.getItem(COMPLETED_UNTIL_KEY);
      var parsed = parseInt(raw || '0', 10);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    } catch (e) {
      return 0;
    }
  }

  function hasActiveCooldown() {
    return getCompletedUntil() > Date.now();
  }

  function getCurrentPageKey() {
    var path = (window.location.pathname || '').toLowerCase();
    if (path.endsWith('/home.html') || path === '/' || path.endsWith('/index.html')) {
      return 'home';
    }
    if (path.indexOf('cocktail') !== -1) {
      return 'cocktail';
    }
    if (path.indexOf('menu') !== -1 || path.indexOf('namastemenu') !== -1 || path.indexOf('namaste_chef') !== -1) {
      return 'menu';
    }
    return '';
  }

  function defaultBlockerSettings() {
    return {
      enabledPages: {
        home: true,
        menu: false,  // ✅ Restrict blocker to home page only
        cocktail: false  // ✅ Restrict blocker to home page only
      },
      settings: {
        globalDisable: false,
        cooldownHours: DEFAULT_COOLDOWN_HOURS
      }
    };
  }

  function loadBlockerSettings() {
    if (blockerSettingsPromise) {
      return blockerSettingsPromise;
    }

    blockerSettingsPromise = fetch(SETTINGS_ENDPOINT, {
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          return defaultBlockerSettings();
        }
        return payload;
      })
      .catch(function () {
        return defaultBlockerSettings();
      });

    return blockerSettingsPromise;
  }

  function shouldShowForPage(settings, pageKey) {
    if (!pageKey) {
      return false;
    }

    var config = settings && settings.settings ? settings.settings : {};
    var enabledPages = settings && settings.enabledPages ? settings.enabledPages : {};
    if (config.globalDisable) {
      return false;
    }
    return !!enabledPages[pageKey];
  }

  function persistCompletion(payload) {
    var cooldownHours = DEFAULT_COOLDOWN_HOURS;
    if (payload && payload.gateCooldownHours !== undefined) {
      var parsedHours = Number(payload.gateCooldownHours);
      if (Number.isFinite(parsedHours) && parsedHours > 0) {
        cooldownHours = parsedHours;
      }
    }

    var until = Date.now() + (cooldownHours * 60 * 60 * 1000);
    try {
      localStorage.setItem(COMPLETED_UNTIL_KEY, String(until));
    } catch (e) {
      // Ignore localStorage failures and continue unlocking this visit.
    }
  }

  function setLoaderState(isLoading) {
    var spinner = document.getElementById('gateLoader');
    var frame = document.getElementById('gateFormFrame');
    if (spinner) {
      spinner.style.display = isLoading ? 'flex' : 'none';
    }
    if (frame) {
      frame.style.opacity = isLoading ? '0' : '1';
    }
  }

  function ensureBypassUi(onVerify) {
    var host = document.getElementById('gateBypassHost');
    if (!host) {
      var card = document.querySelector('#menuGateOverlay .gate-card');
      if (!card) {
        return null;
      }

      host = document.createElement('div');
      host.id = 'gateBypassHost';
      host.style.marginTop = '10px';
      host.innerHTML = [
        '<div style="padding:10px;border:1px solid rgba(184,147,85,.35);border-radius:10px;background:rgba(0,0,0,.12);">',
        '  <div style="color:#F2EDE4;font-size:.78rem;opacity:.86;margin-bottom:8px;">Staff access bypass</div>',
        '  <div style="display:flex;gap:8px;align-items:center;">',
        '    <input id="gateBypassCode" type="password" placeholder="Enter staff passcode" style="flex:1;background:#120d08;border:1px solid rgba(184,147,85,.35);color:#F2EDE4;border-radius:8px;padding:10px 12px;outline:none;" />',
        '    <button id="gateBypassBtn" type="button" style="border:1px solid rgba(184,147,85,.45);background:#2a1b12;color:#F2EDE4;border-radius:8px;padding:10px 12px;cursor:pointer;">Bypass</button>',
        '  </div>',
        '  <div id="gateBypassStatus" style="margin-top:8px;font-size:.74rem;color:rgba(242,237,228,.7);"></div>',
        '</div>'
      ].join('');
      card.appendChild(host);
    }

    var btn = document.getElementById('gateBypassBtn');
    var input = document.getElementById('gateBypassCode');
    var status = document.getElementById('gateBypassStatus');
    if (!btn || !input || !status) {
      return null;
    }

    btn.onclick = function () {
      var passcode = String(input.value || '').trim();
      if (!passcode) {
        status.textContent = 'Enter passcode to continue.';
        status.style.color = '#f5b2b2';
        return;
      }

      btn.disabled = true;
      status.textContent = 'Verifying...';
      status.style.color = 'rgba(242,237,228,.7)';

      fetch(PASSCODE_ENDPOINT, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify({ action: 'public_verify_blocker_passcode', passcode: passcode })
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload || payload.ok !== true) {
            throw new Error((payload && payload.message) ? payload.message : 'Invalid passcode.');
          }

          status.textContent = 'Passcode verified. Unlocking...';
          status.style.color = '#cde8c9';
          onVerify();
        })
        .catch(function (error) {
          status.textContent = (error && error.message) ? error.message : 'Passcode verification failed.';
          status.style.color = '#f5b2b2';
        })
        .finally(function () {
          btn.disabled = false;
        });
    };

    return host;
  }

  function armGateLifecycle(onComplete) {
    var frame = document.getElementById('gateFormFrame');
    if (!frame) {
      onComplete();
      return;
    }

    var baseSrc = frame.getAttribute('data-base-src') || frame.getAttribute('src') || '';
    var visitCount = getNextVisitCount();
    if (baseSrc) {
      frame.setAttribute('data-base-src', baseSrc);
      var sep = baseSrc.indexOf('?') === -1 ? '?' : '&';
      frame.src = baseSrc + sep + 'visit_count=' + encodeURIComponent(String(visitCount)) + '&source=menu_gate&t=' + Date.now();
    }

    setLoaderState(true);

    function completeGate(payload) {
      window.removeEventListener('message', messageHandler);
      persistCompletion(payload || {});
      removeGate();
      onComplete(payload || {});
    }

    function messageHandler(event) {
      if (!event || !event.data || event.data.type !== 'awg:lead-gate:complete') {
        return;
      }

      if (event.origin !== window.location.origin) {
        return;
      }

      if (frame.contentWindow && event.source !== frame.contentWindow) {
        return;
      }

      var payload = event.data.payload || {};
      if (!payload.leadId) {
        return;
      }

      completeGate(payload);
    }

    window.addEventListener('message', messageHandler);

    frame.addEventListener('load', function () {
      setLoaderState(false);
    });
  }

  /* ── menu.html ─────────────────────────────────────────────────────────────
     Call once, with DOM available (non-deferred, placed at bottom of body).
     The gate overlay is already in the DOM (display:none).
     Always show gate on visit and await iframe redirect. */
  function initForMenuPage() {
    loadBlockerSettings().then(function (settings) {
      var pageKey = getCurrentPageKey();
      if (!shouldShowForPage(settings, pageKey) || hasActiveCooldown()) {
        removeGate();
        return;
      }

      var overlay = document.getElementById('menuGateOverlay');
      if (overlay) {
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      }

      ensureBypassUi(function () {
        persistCompletion({ gateCooldownHours: (settings && settings.settings && settings.settings.cooldownHours) || DEFAULT_COOLDOWN_HOURS });
        removeGate();
      });

      armGateLifecycle(function (payload) {
        var selector = payload && payload.redirectTo ? String(payload.redirectTo) : '';
        if (!selector) {
          return;
        }

        var target = document.querySelector(selector);
        if (target && typeof target.scrollIntoView === 'function') {
          target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    });
  }

  /* ── namastemenu.html / legacy namaste_chef.html ──────────────────────────
     Returns a Promise that resolves after iframe navigates after form submission.
     Use: await window.awgGate.waitForGate(); at the top of init(). */
  function waitForGate() {
    return new Promise(function (resolve) {
      loadBlockerSettings().then(function (settings) {
        var pageKey = getCurrentPageKey();
        if (!shouldShowForPage(settings, pageKey) || hasActiveCooldown()) {
          removeGate();
          resolve({
            skipped: true,
            reason: hasActiveCooldown() ? 'cooldown_active' : 'disabled_for_page'
          });
          return;
        }

        var overlay = document.getElementById('menuGateOverlay');
        if (overlay) {
          overlay.style.display = 'flex';
          document.body.style.overflow = 'hidden';
        }

        ensureBypassUi(function () {
          persistCompletion({ gateCooldownHours: (settings && settings.settings && settings.settings.cooldownHours) || DEFAULT_COOLDOWN_HOURS });
          removeGate();
          resolve({ skipped: true, reason: 'staff_bypass' });
        });

        armGateLifecycle(resolve);
      });
    });
  }

  window.awgGate = {
    initForMenuPage: initForMenuPage,
    waitForGate: waitForGate
  };

}());
