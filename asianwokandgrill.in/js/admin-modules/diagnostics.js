import { adminApi } from './api-client.js';

export async function renderDiagnostics(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Diagnostics</h3>
      <button id="dgRun" class="admin-button" type="button">Run Connectivity Diagnostic</button>
      <div id="dgStatus" class="admin-form-status">Ready.</div>
      <pre id="dgOutput" class="admin-code-block"></pre>
    </section>
  `;

  const status = container.querySelector('#dgStatus');
  const output = container.querySelector('#dgOutput');
  const runBtn = container.querySelector('#dgRun');

  runBtn.addEventListener('click', async () => {
    status.textContent = 'Running diagnostics...';
    status.classList.remove('error');
    output.textContent = '';

    try {
      const payload = await adminApi('server_connection_diagnostic');
      status.textContent = 'Diagnostics complete.';
      output.textContent = JSON.stringify(payload, null, 2);
    } catch (error) {
      status.textContent = error.message;
      status.classList.add('error');
      output.textContent = '';
    }
  });
}
