export async function renderMenuEditor(container) {
  container.innerHTML = '';

  const wrap = document.createElement('div');
  wrap.style.height = 'calc(100vh - 190px)';
  wrap.style.minHeight = '720px';
  wrap.style.width = '100%';

  const frame = document.createElement('iframe');
  const url = new URL('/admin-menu-price-editor.html', window.location.origin);
  url.searchParams.set('embedded', '1');

  const token = String(localStorage.getItem('awg_admin_token') || '').trim();
  if (token) {
    url.searchParams.set('authToken', token);
  }

  frame.src = url.toString();
  frame.title = 'Menu Bulk Editor';
  frame.loading = 'eager';
  frame.style.width = '100%';
  frame.style.height = '100%';
  frame.style.minHeight = '720px';
  frame.style.border = '1px solid rgba(123, 94, 67, 0.18)';
  frame.style.borderRadius = '18px';
  frame.style.background = '#fff';

  frame.addEventListener('load', () => {
    const liveToken = String(localStorage.getItem('awg_admin_token') || '').trim();
    if (!liveToken || !frame.contentWindow) {
      return;
    }

    frame.contentWindow.postMessage({
      type: 'NK_EMBEDDED_AUTH',
      token: liveToken,
    }, window.location.origin);
  });

  wrap.appendChild(frame);
  container.appendChild(wrap);
}
