/**
 * side-nav.js
 * ===========
 * Common side navigation, floating quick actions, and scroll-to-top button.
 * Shared across ALL pages of asianwokandgrill.in.
 *
 * On menu / namastemenu pages the side-nav list is populated by their own
 * page-specific JS (menu-ui.js etc.). On all other pages this script
 * auto-populates the drawer with site-wide navigation links.
 */
(function () {
  'use strict';

  // -----------------------------------------------------------------------
  // Site-wide navigation links (used on non-menu pages)
  // -----------------------------------------------------------------------
  var SITE_NAV_LINKS = [
    { label: 'Home',         href: 'home.html' },
    { label: 'AWG Menu',         href: 'menu.html' },
    { label: 'Cocktails Menu',    href: 'cocktail.html' },
    { label: 'Namaste Menu', href: 'namastemenu.html' },
    { label: 'Reservations', href: 'reservation.html' },
    { label: 'Contact',      href: 'contact.html' },
    { label: 'Franchises',   href: 'franchises.html' }
  ];

  // -----------------------------------------------------------------------
  // Inject side-nav HTML into the page
  // -----------------------------------------------------------------------
  function injectSideNavHTML() {
    // Skip injection if page already has side-nav elements (menu.html, namastemenu.html, etc.)
    if (document.getElementById('floatingQuickActions') ||
        document.getElementById('sideNavContainer')) return;

    var html =
      '<div id="floatingQuickActions">' +
        '<div class="floating-action-item" id="menuTriggerBtn" onclick="SideNav.toggleSideNav(true)">MENU</div>' +
        '<a class="floating-contact-btn floating-action-item" id="floatingWhatsAppBtn" href="https://wa.me/917269899999" target="_blank" rel="noopener noreferrer" aria-label="Chat on WhatsApp" title="WhatsApp">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.301-.15-1.779-.879-2.053-.979-.275-.1-.475-.15-.675.15-.199.299-.775.979-.95 1.174-.175.199-.349.225-.649.075-.3-.15-1.267-.467-2.414-1.487-.893-.796-1.496-1.779-1.671-2.079-.175-.3-.019-.462.131-.611.135-.135.3-.349.45-.524.149-.175.199-.3.299-.5.1-.199.05-.374-.025-.524-.074-.15-.674-1.624-.924-2.224-.243-.585-.49-.505-.674-.514-.175-.008-.374-.01-.574-.01-.199 0-.524.075-.799.374-.275.3-1.049 1.024-1.049 2.499 0 1.474 1.074 2.899 1.224 3.099.149.199 2.114 3.229 5.122 4.529.716.309 1.275.493 1.711.63.718.228 1.372.196 1.889.119.576-.086 1.779-.726 2.03-1.426.249-.7.249-1.299.174-1.424-.074-.124-.274-.199-.574-.349z"></path></svg>' +
        '</a>' +
        '<a class="floating-contact-btn floating-action-item" id="floatingCallBtn" href="tel:+917269899999" aria-label="Call now" title="Call">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.62 10.79a15.053 15.053 0 0 0 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.61 21 3 13.39 3 4c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.24.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"></path></svg>' +
        '</a>' +
        '<button class="floating-hide-btn" id="quickActionToggleBtn" type="button" onclick="SideNav.toggleQuickActions()" aria-label="Hide quick actions" aria-pressed="false" title="Hide quick actions">' +
          '<span class="quick-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>' +
        '</button>' +
      '</div>' +
      '<button id="scrollTopBtn" aria-label="Scroll to top">&uarr;</button>' +
      '<div id="sideNavOverlay" onclick="SideNav.toggleSideNav(false)"></div>' +
      '<div id="sideNavContainer">' +
        '<div class="drawer-header"><div>' +
          '<p class="drawer-kicker">Browse</p>' +
          '<h3 class="drawer-title">Navigation</h3>' +
        '</div></div>' +
        '<div id="sideNavList"></div>' +
        '<a class="side-nav-back-link" href="https://asianwokandgrill.in">Back to Website</a>' +
      '</div>';

    var wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    while (wrapper.firstChild) {
      document.body.appendChild(wrapper.firstChild);
    }
  }

  // -----------------------------------------------------------------------
  // Populate nav links (only if page-specific JS hasn't already filled it)
  // -----------------------------------------------------------------------
  function populateSiteLinks() {
    var list = document.getElementById('sideNavList');
    if (!list || list.children.length > 0) return; // already populated by page JS

    var currentPath = window.location.pathname;

    SITE_NAV_LINKS.forEach(function (item) {
      var a = document.createElement('a');
      a.className = 'nav-link';
      a.href = item.href;
      a.textContent = item.label;
      if (currentPath.indexOf(item.href) !== -1) {
        a.classList.add('active');
      }
      list.appendChild(a);
    });
  }

  // -----------------------------------------------------------------------
  // Toggle side-nav drawer
  // -----------------------------------------------------------------------
  function toggleSideNav(show) {
    var drawer  = document.getElementById('sideNavContainer');
    var overlay = document.getElementById('sideNavOverlay');
    if (!drawer || !overlay) return;
    if (show) {
      document.body.classList.add('drawer-open');
      drawer.classList.add('open');
      overlay.style.display = 'block';
    } else {
      drawer.classList.remove('open');
      overlay.style.display = 'none';
      document.body.classList.remove('drawer-open');
    }
  }

  // -----------------------------------------------------------------------
  // Toggle floating quick-actions collapsed / expanded
  // -----------------------------------------------------------------------
  function toggleQuickActions(forceCollapsed) {
    var wrap = document.getElementById('floatingQuickActions');
    var toggleBtn = document.getElementById('quickActionToggleBtn');
    if (!wrap || !toggleBtn) return;

    var nextCollapsed = typeof forceCollapsed === 'boolean'
      ? forceCollapsed
      : !wrap.classList.contains('is-collapsed');

    wrap.classList.toggle('is-collapsed', nextCollapsed);
    toggleBtn.classList.toggle('is-collapsed', nextCollapsed);
    toggleBtn.setAttribute('aria-pressed', String(nextCollapsed));
    toggleBtn.setAttribute('aria-label', nextCollapsed ? 'Show quick actions' : 'Hide quick actions');
    toggleBtn.setAttribute('title', nextCollapsed ? 'Show quick actions' : 'Hide quick actions');
  }

  // -----------------------------------------------------------------------
  // Resolve WhatsApp/phone from footer (reuses first wa.me/ link found)
  // -----------------------------------------------------------------------
  function initFloatingQuickActions() {
    var waBtn    = document.getElementById('floatingWhatsAppBtn');
    var callBtn  = document.getElementById('floatingCallBtn');
    var toggleBtn = document.getElementById('quickActionToggleBtn');
    if (!waBtn || !callBtn || !toggleBtn) return;

    var primaryWa =
      document.querySelector('.concept-footer .property-footer-card:first-of-type a[href*="wa.me/"]') ||
      document.querySelector('.concept-footer a[href*="wa.me/"]') ||
      document.querySelector('a[href*="wa.me/"]');
    if (!primaryWa) return;

    var href = primaryWa.getAttribute('href') || '';
    var phoneMatch = href.match(/wa\.me\/(\d+)/i);
    if (!phoneMatch || !phoneMatch[1]) return;

    var phone = phoneMatch[1];
    waBtn.href = 'https://wa.me/' + phone;
    callBtn.href = 'tel:+' + phone;
    waBtn.setAttribute('aria-label', 'Chat on WhatsApp (' + phone + ')');
    callBtn.setAttribute('aria-label', 'Call ' + phone);
  }

  // -----------------------------------------------------------------------
  // Scroll-to-top button
  // -----------------------------------------------------------------------
  function setupScrollTopButton() {
    var btn = document.getElementById('scrollTopBtn');
    if (!btn) return;

    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    function toggleBtn() {
      btn.style.display = window.scrollY > 300 ? 'flex' : 'none';
    }

    window.addEventListener('scroll', toggleBtn, { passive: true });
    toggleBtn();
  }

  // -----------------------------------------------------------------------
  // Initialise on DOMContentLoaded
  // -----------------------------------------------------------------------
  function init() {
    injectSideNavHTML();
    // Let page-specific JS fill sideNavList first (e.g. menu-ui.js).
    // Use a short timeout so deferred scripts run before we auto-fill.
    setTimeout(function () {
      populateSiteLinks();
    }, 100);
    initFloatingQuickActions();
    setupScrollTopButton();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose globally so inline onclick handlers and page JS can call them
  window.SideNav = {
    toggleSideNav: toggleSideNav,
    toggleQuickActions: toggleQuickActions
  };

  // Also expose at top-level for backward compatibility with existing onclick attrs
  window.toggleSideNav = toggleSideNav;
  window.toggleQuickActions = toggleQuickActions;
})();
