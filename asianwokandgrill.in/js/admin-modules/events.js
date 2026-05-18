import { adminApi } from './api-client.js';

export async function renderEvents(container) {
  container.innerHTML = `
    <section class="admin-module-card">
      <h3>Event Management</h3>
      <div class="admin-form-actions" style="justify-content:flex-end;margin-bottom:10px;">
        <button id="evCreate" class="admin-button" type="button">Create Event</button>
        <button id="evCreateQr" class="admin-button admin-button-secondary" type="button">Create Event QR</button>
        <button id="evReload" class="admin-button admin-button-secondary" type="button">Reload</button>
      </div>

      <div id="evStatus" class="admin-form-status">Loading events...</div>

      <section class="admin-module-card" style="margin-top:10px;">
        <h4>Edit Event</h4>
        <form id="evForm" class="admin-form-grid">
          <input id="evId" type="hidden" />

          <div class="admin-form-grid admin-form-grid-inline">
            <label>Event ID (auto)
              <input id="evEventId" type="text" readonly />
            </label>
            <label>Title *
              <input id="evTitle" type="text" required />
            </label>
            <label>Schedule Summary (auto)
              <input id="evSubtitle" type="text" />
            </label>
            <label>Venue
              <input id="evVenue" type="text" placeholder="Venue" />
            </label>
            <label>Badge Text
              <input id="evBadgeText" type="text" placeholder="Free Event" />
            </label>
          </div>

          <div class="admin-form-grid admin-form-grid-inline">
            <label>Event Type
              <select id="evType">
                <option value="free">Free</option>
                <option value="paid">Paid</option>
              </select>
            </label>
            <label>Ticket Price
              <input id="evTicketPrice" type="number" min="0" step="0.01" />
            </label>
            <label>Currency
              <input id="evCurrency" type="text" value="INR" />
            </label>
            <label>Max Tickets
              <input id="evMaxTickets" type="number" min="0" step="1" value="0" />
            </label>
            <label>Priority
              <input id="evPriority" type="number" min="0" step="1" value="100" />
            </label>
            <label>Time Display Format
              <select id="evTimeFormat">
                <option value="12h">12h</option>
                <option value="24h">24h</option>
              </select>
            </label>
          </div>

          <div class="admin-form-grid admin-form-grid-inline">
            <label>Start Date *
              <input id="evStartDate" type="date" required />
            </label>
            <label>Start Time *
              <input id="evStartTime" type="time" required />
            </label>
            <label>End Date *
              <input id="evEndDate" type="date" required />
            </label>
            <label>End Time *
              <input id="evEndTime" type="time" required />
            </label>
            <label>CTA Text
              <input id="evCtaText" type="text" placeholder="I'm interested" />
            </label>
            <label>CTA URL
              <input id="evCtaUrl" type="text" placeholder="https://..." />
            </label>
          </div>

          <div class="admin-form-grid admin-form-grid-inline">
            <label>Popup Delay (hrs)
              <input id="evPopupDelay" type="number" min="0" step="1" value="0" />
            </label>
            <label>Popup Cooldown (hrs)
              <input id="evPopupCooldown" type="number" min="1" step="1" value="24" />
            </label>
          </div>

          <label>Description
            <textarea id="evDescription" rows="4" placeholder="Event details"></textarea>
          </label>

          <div class="admin-form-grid admin-form-grid-inline" style="align-items:end;">
            <label style="flex:1;">Image URL
              <input id="evImageUrl" type="text" placeholder="/assets/event-images/your-image.jpg" />
            </label>
            <label style="flex:1;">Image Upload
              <input id="evImageFile" type="file" accept="image/png,image/jpeg,image/webp" />
            </label>
            <div class="admin-form-actions" style="margin:0;">
              <button id="evUploadImage" class="admin-button admin-button-secondary" type="button">Upload</button>
            </div>
          </div>

          <div id="evImagePreview" style="margin-top:8px;"></div>

          <label>Video URL
            <input id="evVideoUrl" type="text" placeholder="https://..." />
          </label>

          <label>Cancellation Policy
            <textarea id="evCancellationPolicy" rows="2" placeholder="No refund once pass is purchased."></textarea>
          </label>

          <label>Refund Policy
            <textarea id="evRefundPolicy" rows="2" placeholder="No refund."></textarea>
          </label>

          <div class="admin-form-grid admin-form-grid-inline" style="grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;">
            <label class="admin-checkbox"><input id="evActive" type="checkbox" checked /> Active</label>
            <label class="admin-checkbox"><input id="evPaymentEnabled" type="checkbox" /> Payment Enabled</label>
            <label class="admin-checkbox"><input id="evPopupEnabled" type="checkbox" /> Popup Enabled</label>
            <label class="admin-checkbox"><input id="evShowOnce" type="checkbox" checked /> Show Once Per Session</label>
            <label class="admin-checkbox"><input id="evShowVideo" type="checkbox" /> Show Video</label>
          </div>

          <div class="admin-form-actions">
            <button class="admin-button" type="submit">Save Changes</button>
            <button id="evReset" class="admin-button admin-button-secondary" type="button">Cancel</button>
          </div>
        </form>

        <div id="evSelectedCard" style="margin-top:10px;"></div>
      </section>

      <section class="admin-module-card" style="margin-top:10px;">
        <h4>Events</h4>
        <div id="evList"></div>
      </section>
    </section>
  `;

  const status = container.querySelector('#evStatus');
  const form = container.querySelector('#evForm');
  const eventList = container.querySelector('#evList');
  const selectedCard = container.querySelector('#evSelectedCard');
  const imagePreview = container.querySelector('#evImagePreview');
  const typeNode = container.querySelector('#evType');
  const ticketNode = container.querySelector('#evTicketPrice');

  let eventsCache = [];

  function setStatus(message, isError = false) {
    status.textContent = message;
    status.classList.toggle('error', Boolean(isError));
  }

  function normalizeDate(value) {
    return String(value || '').slice(0, 10);
  }

  function normalizeTime(value) {
    const text = String(value || '');
    if (!text) return '';
    return text.length >= 5 ? text.slice(0, 5) : text;
  }

  function eventTypeLabel(eventType) {
    return String(eventType || '').toLowerCase() === 'paid' ? 'paid' : 'free';
  }

  function currentEventPayload() {
    const eventId = String(container.querySelector('#evEventId').value || '').trim();
    const id = String(container.querySelector('#evId').value || '').trim() || eventId;

    return {
      id,
      event_id: id,
      title: container.querySelector('#evTitle').value.trim(),
      subtitle: container.querySelector('#evSubtitle').value.trim(),
      description: container.querySelector('#evDescription').value.trim(),
      venue: container.querySelector('#evVenue').value.trim(),
      badge_text: container.querySelector('#evBadgeText').value.trim(),
      badgeText: container.querySelector('#evBadgeText').value.trim(),
      event_type: typeNode.value,
      eventType: typeNode.value,
      ticket_price: Number(container.querySelector('#evTicketPrice').value || 0),
      ticketPrice: Number(container.querySelector('#evTicketPrice').value || 0),
      currency: container.querySelector('#evCurrency').value.trim() || 'INR',
      max_tickets: Number(container.querySelector('#evMaxTickets').value || 0),
      maxTickets: Number(container.querySelector('#evMaxTickets').value || 0),
      priority: Number(container.querySelector('#evPriority').value || 0),
      time_display_format: container.querySelector('#evTimeFormat').value,
      start_date: normalizeDate(container.querySelector('#evStartDate').value),
      start_time: normalizeTime(container.querySelector('#evStartTime').value),
      end_date: normalizeDate(container.querySelector('#evEndDate').value),
      end_time: normalizeTime(container.querySelector('#evEndTime').value),
      cta_text: container.querySelector('#evCtaText').value.trim(),
      cta_url: container.querySelector('#evCtaUrl').value.trim(),
      popup_delay_hours: Number(container.querySelector('#evPopupDelay').value || 0),
      popup_cooldown_hours: Number(container.querySelector('#evPopupCooldown').value || 24),
      image_url: container.querySelector('#evImageUrl').value.trim(),
      video_url: container.querySelector('#evVideoUrl').value.trim(),
      cancellation_policy: container.querySelector('#evCancellationPolicy').value.trim(),
      refund_policy: container.querySelector('#evRefundPolicy').value.trim(),
      is_active: container.querySelector('#evActive').checked,
      isActive: container.querySelector('#evActive').checked,
      payment_enabled: container.querySelector('#evPaymentEnabled').checked,
      popup_enabled: container.querySelector('#evPopupEnabled').checked,
      show_once_per_session: container.querySelector('#evShowOnce').checked,
      show_video: container.querySelector('#evShowVideo').checked,
      date: normalizeDate(container.querySelector('#evStartDate').value),
      time: normalizeTime(container.querySelector('#evStartTime').value),
    };
  }

  function updatePreviewCard(event) {
    if (!event) {
      selectedCard.innerHTML = '';
      return;
    }

    const imageUrl = String(event.imageUrl || event.image_url || '').trim();
    /* ✅ CACHE BUSTING: Add timestamp to force browser to load fresh image */
    const cacheBustedImageUrl = imageUrl && (imageUrl.includes('?') 
      ? `${imageUrl}&t=${Date.now()}` 
      : `${imageUrl}?t=${Date.now()}`);
    
    const startDate = String(event.startDate || event.start_date || event.date || '-');
    const startTime = String(event.startTime || event.start_time || event.time || '');
    selectedCard.innerHTML = `
      <article class="admin-module-item" style="max-width:260px;">
        <div style="display:flex;gap:10px;align-items:flex-start;">
          <img src="${escapeHtml(cacheBustedImageUrl || '../assets/images/logo.svg')}" alt="Event" style="width:54px;height:72px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,0.15);" />
          <div>
            <div style="font-size:11px;opacity:0.85;">${eventTypeLabel(event.eventType || event.event_type) === 'paid' ? 'Paid Event' : 'Free Event'}</div>
            <div style="font-weight:700;line-height:1.35;">${escapeHtml(event.title || '-')}</div>
            <div style="font-size:12px;opacity:0.85;">${escapeHtml(startDate)} ${escapeHtml(startTime)}</div>
          </div>
        </div>
      </article>
    `;
  }

  function updateImagePreview() {
    const imageUrl = String(container.querySelector('#evImageUrl').value || '').trim();
    if (!imageUrl) {
      imagePreview.innerHTML = '';
      return;
    }

    /* ✅ CACHE BUSTING: Add timestamp to force browser to load fresh image on upload */
    const cacheBustedUrl = imageUrl.includes('?') 
      ? `${imageUrl}&t=${Date.now()}` 
      : `${imageUrl}?t=${Date.now()}`;
    
    imagePreview.innerHTML = `<img src="${escapeHtml(cacheBustedUrl)}" alt="Event image" style="width:140px;height:190px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,0.15);" />`;
  }

  function resetForm() {
    form.reset();
    container.querySelector('#evId').value = '';
    container.querySelector('#evEventId').value = '';
    container.querySelector('#evCurrency').value = 'INR';
    container.querySelector('#evPriority').value = '100';
    container.querySelector('#evPopupCooldown').value = '24';
    container.querySelector('#evPopupDelay').value = '0';
    container.querySelector('#evMaxTickets').value = '0';
    container.querySelector('#evType').value = 'free';
    container.querySelector('#evActive').checked = true;
    container.querySelector('#evShowOnce').checked = true;
    container.querySelector('#evPaymentEnabled').checked = false;
    container.querySelector('#evPopupEnabled').checked = false;
    container.querySelector('#evShowVideo').checked = false;
    ticketNode.disabled = true;
    ticketNode.value = '';
    updateImagePreview();
    updatePreviewCard(null);
  }

  function applyEventToForm(event) {
    container.querySelector('#evId').value = String(event.id || event.eventId || event.event_id || '');
    container.querySelector('#evEventId').value = String(event.eventId || event.event_id || event.id || '');
    container.querySelector('#evTitle').value = String(event.title || '');
    container.querySelector('#evSubtitle').value = String(event.subtitle || event.scheduleSummary || '');
    container.querySelector('#evVenue').value = String(event.venue || event.location || '');
    container.querySelector('#evBadgeText').value = String(event.badgeText || event.badge_text || '');
    container.querySelector('#evType').value = eventTypeLabel(event.eventType || event.event_type);
    container.querySelector('#evTicketPrice').value = String(event.ticketPrice ?? event.ticket_price ?? 0);
    container.querySelector('#evCurrency').value = String(event.currency || 'INR');
    container.querySelector('#evMaxTickets').value = String(event.maxTickets ?? event.max_tickets ?? 0);
    container.querySelector('#evPriority').value = String(event.priority ?? 0);
    container.querySelector('#evTimeFormat').value = String(event.timeDisplayFormat || event.time_display_format || '12h');
    container.querySelector('#evStartDate').value = normalizeDate(event.startDate || event.start_date || event.date || '');
    container.querySelector('#evStartTime').value = normalizeTime(event.startTime || event.start_time || event.time || '');
    container.querySelector('#evEndDate').value = normalizeDate(event.endDate || event.end_date || event.startDate || event.start_date || event.date || '');
    container.querySelector('#evEndTime').value = normalizeTime(event.endTime || event.end_time || event.startTime || event.start_time || event.time || '');
    container.querySelector('#evCtaText').value = String(event.ctaText || event.cta_text || '');
    container.querySelector('#evCtaUrl').value = String(event.ctaUrl || event.cta_url || '');
    container.querySelector('#evPopupDelay').value = String(event.popupDelayHours ?? event.popup_delay_hours ?? 0);
    container.querySelector('#evPopupCooldown').value = String(event.popupCooldownHours ?? event.popup_cooldown_hours ?? 24);
    container.querySelector('#evDescription').value = String(event.description || '');
    container.querySelector('#evImageUrl').value = String(event.imageUrl || event.image_url || '');
    container.querySelector('#evVideoUrl').value = String(event.videoUrl || event.video_url || '');
    container.querySelector('#evCancellationPolicy').value = String(event.cancellationPolicy || event.cancellation_policy || '');
    container.querySelector('#evRefundPolicy').value = String(event.refundPolicy || event.refund_policy || '');
    container.querySelector('#evActive').checked = event.isActive !== false && Number(event.is_active ?? 1) === 1;
    container.querySelector('#evPaymentEnabled').checked = Boolean(event.paymentEnabled ?? event.payment_enabled ?? false);
    container.querySelector('#evPopupEnabled').checked = Boolean(event.popupEnabled ?? event.popup_enabled ?? false);
    container.querySelector('#evShowOnce').checked = Boolean(event.showOncePerSession ?? event.show_once_per_session ?? true);
    container.querySelector('#evShowVideo').checked = Boolean(event.showVideo ?? event.show_video ?? false);

    ticketNode.disabled = container.querySelector('#evType').value !== 'paid';
    updateImagePreview();
    updatePreviewCard(event);
  }

  function buildEventsTable(events) {
    if (!events.length) {
      return '<div class="admin-module-empty">No events found.</div>';
    }

    const rows = events.map((event) => {
      const type = eventTypeLabel(event.eventType || event.event_type);
      const statusText = event.isActive === false || Number(event.is_active ?? 1) !== 1 ? 'Inactive' : 'Active';
      const startAt = `${escapeHtml(String(event.startDate || event.start_date || event.date || '-'))} ${escapeHtml(String(event.startTime || event.start_time || event.time || ''))}`;
      const encoded = encodeURIComponent(JSON.stringify(event));
      const price = type === 'paid'
        ? `${escapeHtml(String(event.currency || 'INR'))} ${escapeHtml(String(event.ticketPrice ?? event.ticket_price ?? 0))}`
        : 'Free';

      return `
        <tr>
          <td>
            <div style="font-weight:600;">${escapeHtml(event.title || '-')}</div>
            <div style="font-size:12px;opacity:.75;">${escapeHtml(event.subtitle || event.scheduleSummary || '')}</div>
          </td>
          <td>${startAt}</td>
          <td><span class="admin-pill">${type}</span></td>
          <td>${price}</td>
          <td>${Boolean(event.popupEnabled ?? event.popup_enabled ?? false) ? 'Yes' : 'No'}</td>
          <td><span class="admin-pill ${statusText === 'Active' ? 'admin-pill-success' : 'admin-pill-danger'}">${statusText}</span></td>
          <td>
            <button class="admin-table-button" data-edit-event="1" data-event-payload="${encoded}">Edit</button>
            <button class="admin-table-button" data-clone-event="${escapeHtml(event.id || event.eventId || '')}">Clone</button>
            <button class="admin-table-button" data-toggle-event="${escapeHtml(event.id || event.eventId || '')}" data-toggle-state="${statusText.toLowerCase()}">${statusText === 'Active' ? 'Deactivate' : 'Activate'}</button>
            <button class="admin-table-button admin-table-button-danger" data-delete-event="${escapeHtml(event.id || event.eventId || '')}">Delete</button>
          </td>
        </tr>
      `;
    }).join('');

    return `
      <div class="admin-module-table-wrap">
        <table class="admin-module-table">
          <thead><tr><th>Event</th><th>Start</th><th>Type</th><th>Price</th><th>Popup</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;
  }

  function bindRowActions() {
    Array.from(container.querySelectorAll('[data-edit-event]')).forEach((button) => {
      button.addEventListener('click', () => {
        const raw = button.dataset.eventPayload || '';
        const data = raw ? JSON.parse(decodeURIComponent(raw)) : {};
        applyEventToForm(data);
      });
    });

    Array.from(container.querySelectorAll('[data-delete-event]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const eventId = String(button.dataset.deleteEvent || '');
        if (!eventId || !window.confirm('Delete this event?')) {
          return;
        }

        try {
          await adminApi('admin_delete_event', { method: 'POST', body: { id: eventId, eventId: eventId, event_id: eventId } });
          setStatus('Event deleted.');
          await loadEvents();
        } catch (error) {
          setStatus(error.message || 'Delete failed.', true);
        }
      });
    });

    Array.from(container.querySelectorAll('[data-clone-event]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const eventId = String(button.dataset.cloneEvent || '');
        if (!eventId) {
          return;
        }

        try {
          await adminApi('admin_clone_event', { method: 'POST', body: { id: eventId, eventId: eventId, event_id: eventId } });
          setStatus('Event cloned.');
          await loadEvents();
        } catch (error) {
          setStatus(error.message || 'Clone failed.', true);
        }
      });
    });

    Array.from(container.querySelectorAll('[data-toggle-event]')).forEach((button) => {
      button.addEventListener('click', async () => {
        const eventId = String(button.dataset.toggleEvent || '');
        if (!eventId) {
          return;
        }

        const isActive = String(button.dataset.toggleState || '') !== 'active';

        try {
          await adminApi('admin_toggle_event', { method: 'POST', body: { id: eventId, eventId: eventId, event_id: eventId, isActive } });
          setStatus('Event status updated.');
          await loadEvents();
        } catch (error) {
          setStatus(error.message || 'Status update failed.', true);
        }
      });
    });
  }

  async function loadEvents() {
    try {
      const payload = await adminApi('admin_list_events');
      eventsCache = Array.isArray(payload.events) ? payload.events : [];
      eventList.innerHTML = buildEventsTable(eventsCache);
      bindRowActions();
      setStatus(`Loaded ${eventsCache.length} event(s).`);
    } catch (error) {
      setStatus(error.message || 'Failed to load events.', true);
      eventList.innerHTML = '';
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const payload = currentEventPayload();
    if (!payload.title || !payload.start_date || !payload.start_time || !payload.end_date || !payload.end_time) {
      setStatus('Title, start, and end schedule are required.', true);
      return;
    }

    try {
      await adminApi('admin_save_event', { method: 'POST', body: payload });
      setStatus('Event saved successfully.');
      await loadEvents();
    } catch (error) {
      setStatus(error.message || 'Save failed.', true);
    }
  });

  typeNode.addEventListener('change', () => {
    ticketNode.disabled = typeNode.value !== 'paid';
    if (typeNode.value !== 'paid') {
      ticketNode.value = '';
      container.querySelector('#evPaymentEnabled').checked = false;
    }
  });

  container.querySelector('#evTitle').addEventListener('input', () => {
    const title = String(container.querySelector('#evTitle').value || '').trim();
    const date = normalizeDate(container.querySelector('#evStartDate').value);
    const time = normalizeTime(container.querySelector('#evStartTime').value);
    if (title && date) {
      container.querySelector('#evSubtitle').value = `${new Date(`${date}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}, ${time || '00:00'} onwards`;
    }
  });

  container.querySelector('#evImageUrl').addEventListener('input', updateImagePreview);

  container.querySelector('#evUploadImage').addEventListener('click', async () => {
    const eventId = String(container.querySelector('#evId').value || container.querySelector('#evEventId').value || '').trim();
    const fileInput = container.querySelector('#evImageFile');
    const file = fileInput.files && fileInput.files[0];

    if (!eventId) {
      setStatus('Please save event first, then upload image.', true);
      return;
    }
    if (!file) {
      setStatus('Select an image file first.', true);
      return;
    }

    const formData = new FormData();
    formData.append('event_id', eventId);
    formData.append('eventImage', file);

    try {
      const payload = await adminApi('admin_event_image_upload', {
        method: 'POST',
        body: formData,
      });

      container.querySelector('#evImageUrl').value = String(payload.image_url || '');
      fileInput.value = '';
      updateImagePreview();
      setStatus('Image uploaded and linked to event.');
      await loadEvents();
    } catch (error) {
      setStatus(error.message || 'Image upload failed.', true);
    }
  });

  container.querySelector('#evCreate').addEventListener('click', () => {
    resetForm();
    setStatus('New event form ready.');
  });

  container.querySelector('#evReload').addEventListener('click', async () => {
    await loadEvents();
  });

  container.querySelector('#evCreateQr').addEventListener('click', async () => {
    const eventId = String(container.querySelector('#evId').value || container.querySelector('#evEventId').value || '').trim();
    if (!eventId) {
      setStatus('Select or save an event first, then create its QR.', true);
      return;
    }

    try {
      const payload = await adminApi('admin_generate_event_qr', {
        method: 'POST',
        body: {
          id: eventId,
          eventId: eventId,
          event_id: eventId,
        },
      });

      const slug = String(payload.slug || '').trim();
      const publicUrl = slug ? `${window.location.origin}/qr/${encodeURIComponent(slug)}` : '';
      setStatus(publicUrl ? `Event QR created: ${publicUrl}` : 'Event QR created successfully.', false);
    } catch (error) {
      setStatus(error.message || 'Failed to create event QR.', true);
    }
  });

  container.querySelector('#evReset').addEventListener('click', () => {
    resetForm();
    setStatus('Edit cancelled.');
  });

  resetForm();
  await loadEvents();
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
