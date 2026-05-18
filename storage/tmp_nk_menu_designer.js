// admin-modules/menu-category-designer.js
// Portal wrapper for the current embedded menu category designer page.
(function (NK) {
  NK.MODULES = NK.MODULES || {};

  function buildEmbeddedPageModule(pagePath) {
    return {
      _container: null,
      _iframe: null,
      _onFrameLoad: null,

      init: function (container, authClient) {
        this._container = container;
        container.innerHTML = '';

        var wrap = document.createElement('div');
        wrap.style.height = 'calc(100vh - 190px)';
        wrap.style.minHeight = '720px';
        wrap.style.width = '100%';

        var frame = document.createElement('iframe');
        var url = new URL(pagePath, window.location.href);
        url.searchParams.set('embedded', '1');

        var token = authClient && typeof authClient.getToken === 'function'
          ? String(authClient.getToken() || '').trim()
          : '';
        if (token) {
          url.searchParams.set('authToken', token);
        }

        frame.src = url.toString();
        frame.title = 'Menu Category Designer';
        frame.loading = 'eager';
        frame.style.width = '100%';
        frame.style.height = '100%';
        frame.style.minHeight = '720px';
        frame.style.border = '1px solid rgba(123, 94, 67, 0.18)';
        frame.style.borderRadius = '18px';
        frame.style.background = '#fff';

        var self = this;
        this._onFrameLoad = function () {
          if (!frame.contentWindow || !authClient || typeof authClient.getToken !== 'function') {
            return;
          }

          var liveToken = String(authClient.getToken() || '').trim();
          if (!liveToken) {
            return;
          }

          frame.contentWindow.postMessage({
            type: 'NK_EMBEDDED_AUTH',
            token: liveToken
          }, window.location.origin);
        };
        frame.addEventListener('load', this._onFrameLoad);

        wrap.appendChild(frame);
        container.appendChild(wrap);
        this._iframe = frame;
      },

      destroy: function () {
        if (this._iframe && this._onFrameLoad) {
          this._iframe.removeEventListener('load', this._onFrameLoad);
        }
        if (this._container) {
          this._container.innerHTML = '';
        }
        this._onFrameLoad = null;
        this._iframe = null;
        this._container = null;
      }
    };
  }

  NK.MODULES['menu-category-designer'] = buildEmbeddedPageModule('admin-menu-category-designer.html');
})(window.NK || (window.NK = {}));
