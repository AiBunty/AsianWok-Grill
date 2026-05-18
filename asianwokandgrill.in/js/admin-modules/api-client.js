import { buildPhpActionUrl } from '../runtime-config.js';

let pendingRequests = 0;

function emitLoadingState() {
  window.dispatchEvent(new CustomEvent('awg:admin:loading', {
    detail: { pendingRequests },
  }));
}

export function getAdminToken() {
  return localStorage.getItem('awg_admin_token') || '';
}

export async function adminApi(action, options = {}) {
  pendingRequests += 1;
  emitLoadingState();

  const method = options.method || 'GET';
  const token = getAdminToken();
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {}),
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  let body;
  const isFormData = options.body instanceof FormData;
  if (method !== 'GET') {
    if (isFormData) {
      body = options.body;
      if (!body.has('action')) {
        body.append('action', action);
      }
    } else {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify({ action, ...(options.body || {}) });
    }
  }

  const url = method === 'GET'
    ? withQuery(buildPhpActionUrl(action), options.query || {})
    : buildPhpActionUrl(action);

  try {
    const response = await fetch(url, {
      method,
      headers,
      body,
    });

    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      const message = payload && payload.message ? payload.message : 'Request failed.';
      throw new Error(message);
    }

    return payload;
  } finally {
    pendingRequests = Math.max(0, pendingRequests - 1);
    emitLoadingState();
  }
}

export async function adminMultipart(action, formData) {
  if (!(formData instanceof FormData)) {
    throw new Error('formData must be a FormData instance.');
  }

  return adminApi(action, {
    method: 'POST',
    body: formData,
  });
}

export function downloadBase64File(fileName, mimeType, base64Data) {
  const binary = atob(String(base64Data || ''));
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }

  const blob = new Blob([bytes], { type: mimeType || 'application/octet-stream' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName || 'download.bin';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function withQuery(url, query) {
  const params = new URLSearchParams();
  Object.entries(query).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') {
      return;
    }
    params.set(key, String(value));
  });

  const queryString = params.toString();
  if (!queryString) {
    return url;
  }

  return `${url}&${queryString}`;
}
