const runtimeHost = (window.location && window.location.hostname)
  ? String(window.location.hostname).toLowerCase()
  : '';

const runtimeProtocol = (window.location && window.location.protocol)
  ? String(window.location.protocol).toLowerCase()
  : '';

const runtimeOrigin = (window.location && window.location.origin)
  ? String(window.location.origin).replace(/\/+$/, '')
  : '';

const isLocalRuntime =
  runtimeProtocol === 'file:' ||
  runtimeHost === 'localhost' ||
  runtimeHost === '127.0.0.1';

function normalizeApiBase(url) {
  const value = String(url || '').trim();
  if (!value) {
    return '/';
  }

  const withoutQuery = value.split('?')[0].trim();
  return withoutQuery.replace(/\/?$/, '/');
}

const configuredPublicSiteBase = (window.AWG_RUNTIME_CONFIG && window.AWG_RUNTIME_CONFIG.publicSiteBase)
  ? String(window.AWG_RUNTIME_CONFIG.publicSiteBase).trim()
  : '';

const publicSiteBase = (configuredPublicSiteBase || runtimeOrigin || '').replace(/\/+$/, '');
const phpApiUrl = normalizeApiBase((publicSiteBase ? `${publicSiteBase}/` : '/'));

window.AWG_DATA_API = window.AWG_DATA_API || {};
Object.assign(window.AWG_DATA_API, {
  runtimeOrigin,
  publicSiteBase,
  isLocalRuntime,
  phpApiUrl,
  appsScriptUrl: phpApiUrl,
  resolveApiBaseForAction() {
    return phpApiUrl;
  },
  buildActionUrl(action) {
    const base = normalizeApiBase(phpApiUrl);
    if (!action) {
      return base;
    }

    return `${base}?action=${encodeURIComponent(String(action).trim())}`;
  }
});

export function getPhpApiBase() {
  return window.AWG_DATA_API.phpApiUrl;
}

export function buildPhpActionUrl(action) {
  return window.AWG_DATA_API.buildActionUrl(action);
}