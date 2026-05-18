import { buildPhpActionUrl } from '../runtime-config.js';

const form = document.getElementById('adminLoginForm');
const usernameInput = document.getElementById('adminUsername');
const passwordInput = document.getElementById('adminPassword');
const statusNode = document.getElementById('adminLoginStatus');
const button = document.getElementById('adminLoginButton');
const bootstrapPanel = document.getElementById('adminBootstrapPanel');
const bootstrapButton = document.getElementById('adminBootstrapButton');

function setStatus(message, isError = false) {
  if (!statusNode) return;
  statusNode.textContent = message;
  statusNode.dataset.state = isError ? 'error' : 'default';
}

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    button.disabled = true;
    setStatus('Signing in...');

    try {
      const response = await fetch(buildPhpActionUrl('auth_login'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify({
          action: 'auth_login',
          username: usernameInput.value,
          password: passwordInput.value
        })
      });

      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Login failed.');
      }

      localStorage.setItem('awg_admin_token', payload.token);
      setStatus('Login successful. Redirecting...');
      window.location.href = '/admin/';
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Login failed.', true);
    } finally {
      button.disabled = false;
    }
  });
}

async function loadBootstrapState() {
  try {
    const response = await fetch(buildPhpActionUrl('auth_bootstrap_status'), {
      headers: { Accept: 'application/json' }
    });
    const payload = await response.json();
    if (response.ok && payload.ok && payload.bootstrapRequired && bootstrapPanel) {
      bootstrapPanel.classList.remove('hidden');
    }
  } catch (_) {
    // Login screen can still operate without bootstrap status.
  }
}

bootstrapButton?.addEventListener('click', async () => {
  bootstrapButton.disabled = true;
  setStatus('Creating initial superadmin...');

  try {
    const response = await fetch(buildPhpActionUrl('auth_bootstrap_superadmin'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify({ action: 'auth_bootstrap_superadmin' })
    });

    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      throw new Error(payload.message || 'Bootstrap failed.');
    }

    setStatus('Bootstrap completed. Sign in with the bootstrap credentials from the server environment.');
    bootstrapPanel?.classList.add('hidden');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Bootstrap failed.', true);
  } finally {
    bootstrapButton.disabled = false;
  }
});

loadBootstrapState();
