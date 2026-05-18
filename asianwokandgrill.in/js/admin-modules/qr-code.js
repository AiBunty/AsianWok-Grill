import { adminApi } from './api-client.js';

export async function renderQrCodeCenter(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>QR Code Center</h3>
      <p class="admin-module-empty">Generate/download cards for stable public URLs. Only QRs created in Routing &amp; QR Center appear here.</p>
      <div style="margin-bottom:10px;">
        <button id="qrReloadBtn" class="admin-button admin-button-secondary" type="button">Reload</button>
      </div>
      <div id="qrCodeStatus" class="admin-form-status">Loading QR records...</div>
      <div id="qrCodeGrid" class="admin-form-grid admin-form-grid-inline"></div>
    </section>
  `;
  // Apply responsive grid styling
  const styleSheet = document.createElement('style');
  styleSheet.textContent = `
    #qrCodeGrid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)) !important;
      gap: 16px !important;
      padding: 0 !important;
    }

    @media (max-width: 768px) {
      #qrCodeGrid {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)) !important;
        gap: 12px !important;
      }
    }

    @media (max-width: 480px) {
      #qrCodeGrid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
      }
    }

    #qrCodeGrid .admin-module-item {
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      text-align: center !important;
      gap: 12px !important;
      padding: 16px !important;
    }

    #qrCodeGrid .admin-module-item > div:first-child {
      width: 100%;
      word-break: break-word;
    }

    #qrCodeGrid .admin-module-item img {
      max-width: 100%;
      width: 140px !important;
      height: 140px !important;
    }

    @media (max-width: 480px) {
      #qrCodeGrid .admin-module-item img {
        width: 120px !important;
        height: 120px !important;
      }
    }

    #qrCodeGrid .admin-module-item > div:last-child {
      display: flex !important;
      flex-direction: row !important;
      flex-wrap: wrap !important;
      gap: 6px !important;
      justify-content: center !important;
      width: 100% !important;
      align-items: center !important;
    }

    #qrCodeGrid .admin-module-item .admin-button {
      flex: 1 1 auto;
      min-width: 70px;
      white-space: nowrap;
      padding: 8px 10px !important;
      font-size: 12px !important;
    }

    @media (max-width: 480px) {
      #qrCodeGrid .admin-module-item .admin-button {
        flex: 1 1 calc(50% - 6px);
        font-size: 11px !important;
      }
    }
  `;
  document.head.appendChild(styleSheet);

  const statusEl = container.querySelector('#qrCodeStatus');
  const gridEl = container.querySelector('#qrCodeGrid');
  const reloadBtn = container.querySelector('#qrReloadBtn');

  function setStatus(message, isError = false) {
    statusEl.textContent = message;
    statusEl.classList.toggle('error', isError);
  }

  function buildPublicUrl(slug) {
    return `${window.location.origin}/qr/${encodeURIComponent(String(slug || '').trim())}`;
  }

  function loadImage(src) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.crossOrigin = 'anonymous';
      image.onload = () => resolve(image);
      image.onerror = reject;
      image.src = src;
    });
  }

  function downloadBlob(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
  }

  function deriveDesignerTitle(row) {
    const slug = String(row?.slug || '').toLowerCase();
    const title = String(row?.title || row?.name || '').toLowerCase();
    if (slug.includes('admin') || title.includes('admin')) {
      return 'Admin QR';
    }
    return 'Guest QR';
  }

  async function renderDesignerQrBlob(row) {
    const publicUrl = buildPublicUrl(String(row?.slug || ''));
    if (!publicUrl) {
      throw new Error('Unable to build public URL for this QR record.');
    }

    const qrImg = await loadImage(`/?action=event_qr_image&data=${encodeURIComponent(publicUrl)}`);
    const canvas = document.createElement('canvas');
    canvas.width = 1400;
    canvas.height = 1900;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      throw new Error('Unable to render designer canvas.');
    }

    const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
    gradient.addColorStop(0, '#fcf9f2');
    gradient.addColorStop(1, '#ebe1d0');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.fillStyle = '#94592b';
    ctx.fillRect(84, 84, canvas.width - 168, canvas.height - 168);
    ctx.fillStyle = '#fffdf8';
    ctx.fillRect(114, 114, canvas.width - 228, canvas.height - 228);

    ctx.fillStyle = '#2f241b';
    ctx.textAlign = 'center';
    ctx.font = '700 58px Georgia';
    ctx.fillText('Asian Wok & Grill', canvas.width / 2, 250);

    ctx.fillStyle = '#7d6a59';
    ctx.font = '600 34px Segoe UI';
    ctx.fillText('Scan to Open', canvas.width / 2, 340);

    const qrCardX = 250;
    const qrCardY = 460;
    const qrCardSize = 900;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(qrCardX, qrCardY, qrCardSize, qrCardSize);
    ctx.strokeStyle = '#b67b45';
    ctx.lineWidth = 16;
    ctx.strokeRect(qrCardX, qrCardY, qrCardSize, qrCardSize);
    ctx.drawImage(qrImg, qrCardX + 60, qrCardY + 60, qrCardSize - 120, qrCardSize - 120);

    const displayTitle = String(row?.title || row?.name || deriveDesignerTitle(row));
    ctx.fillStyle = '#2f241b';
    ctx.font = '700 44px Segoe UI';
    ctx.fillText(displayTitle, canvas.width / 2, 1520);

    ctx.fillStyle = '#7d6a59';
    ctx.font = '600 28px Segoe UI';
    ctx.fillText(deriveDesignerTitle(row), canvas.width / 2, 1590);

    ctx.fillStyle = '#94592b';
    ctx.font = '500 22px Segoe UI';
    ctx.fillText(publicUrl, canvas.width / 2, 1685);

    return new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), 'image/png');
    });
  }

  function renderCards(rows) {
    if (!Array.isArray(rows) || !rows.length) {
      gridEl.innerHTML = '<div class="admin-module-empty">No QR records found. Create QRs in the Routing &amp; QR Center first.</div>';
      return;
    }

    gridEl.innerHTML = rows.map((row) => {
      const slug = String(row.slug || '');
      const name = String(row.name || row.title || slug || 'QR');
      const publicUrl = buildPublicUrl(slug);
      const imageUrl = `/?action=event_qr_image&data=${encodeURIComponent(publicUrl)}`;
      const isSystem = Boolean(row.isSystem || row.is_system);
      return `
        <article class="admin-module-item" data-slug="${escapeHtml(slug)}">
          <div>
            <strong>${escapeHtml(name)}</strong>${isSystem ? ' <span style="font-size:10px;background:#ffecd0;color:#8b5e2a;border-radius:4px;padding:1px 5px;">Permanent</span>' : ''}
            <div style="font-size:11px;color:#7d6a59;margin-top:2px;">${escapeHtml(publicUrl)}</div>
            <div style="font-size:11px;">${Number(row.is_active || 0) === 1 ? '✅ Active' : '⚪ Inactive'}</div>
          </div>
          <img src="${imageUrl}" alt="QR ${escapeHtml(slug)}" style="width:140px;height:140px;border-radius:8px;border:1px solid rgba(0,0,0,.1);" />
          <div>
            <button class="admin-button admin-button-secondary" type="button" data-copy-url="${escapeHtml(publicUrl)}">Copy URL</button>
            <button class="admin-button admin-button-secondary" type="button" data-download-url="${escapeHtml(imageUrl)}" data-file-name="qr-${escapeHtml(slug || 'redirect')}.png">Download QR</button>
            <button class="admin-button admin-button-secondary" type="button" data-designer-slug="${escapeHtml(slug)}" data-designer-title="${escapeHtml(name)}">Designer PNG</button>
            <button class="admin-button admin-button-secondary" type="button" data-open-url="${escapeHtml(publicUrl)}">Open</button>
          </div>
        </article>
      `;
    }).join('');

    Array.from(gridEl.querySelectorAll('[data-copy-url]')).forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(String(btn.getAttribute('data-copy-url') || ''));
          setStatus('URL copied.', false);
        } catch (_e) {
          setStatus('Failed to copy URL.', true);
        }
      });
    });

    Array.from(gridEl.querySelectorAll('[data-download-url]')).forEach((btn) => {
      btn.addEventListener('click', () => {
        const a = document.createElement('a');
        a.href = String(btn.getAttribute('data-download-url') || '');
        a.download = String(btn.getAttribute('data-file-name') || 'qr.png');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
      });
    });

    Array.from(gridEl.querySelectorAll('[data-designer-slug]')).forEach((btn) => {
      btn.addEventListener('click', async () => {
        const slug = String(btn.getAttribute('data-designer-slug') || '');
        const title = String(btn.getAttribute('data-designer-title') || '');
        if (!slug) {
          setStatus('Unable to identify QR record for designer download.', true);
          return;
        }
        try {
          btn.textContent = 'Rendering...';
          btn.disabled = true;
          setStatus('Rendering designer PNG...', false);
          const blob = await renderDesignerQrBlob({ slug, title });
          if (!blob) {
            throw new Error('Unable to generate PNG.');
          }
          downloadBlob(blob, `qr-${slug}-designer.png`);
          setStatus('Designer QR PNG downloaded.', false);
        } catch (error) {
          setStatus(String(error?.message || 'Failed to render designer PNG.'), true);
        } finally {
          btn.textContent = 'Designer PNG';
          btn.disabled = false;
        }
      });
    });

    Array.from(gridEl.querySelectorAll('[data-open-url]')).forEach((btn) => {
      btn.addEventListener('click', () => {
        window.open(String(btn.getAttribute('data-open-url') || ''), '_blank', 'noopener');
      });
    });
  }

  async function loadQrRecords() {
    setStatus('Loading QR records...', false);
    const payload = await adminApi('auth_list_qr_redirects');
    // Show exactly the same QRs as Routing & QR Center (no hidden slugs filter)
    const rows = Array.isArray(payload.redirects) ? payload.redirects : [];
    renderCards(rows);
    setStatus(`QR Code Center ready. ${rows.length} record(s) loaded.`, false);
  }

  reloadBtn.addEventListener('click', async () => {
    try {
      await loadQrRecords();
    } catch (error) {
      setStatus(String(error?.message || 'Failed to reload QR records.'), true);
    }
  });

  try {
    await loadQrRecords();
  } catch (error) {
    setStatus(String(error?.message || 'Failed to load QR records.'), true);
  }
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
