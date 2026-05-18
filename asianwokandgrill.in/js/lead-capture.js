import { buildPhpActionUrl } from './runtime-config.js';

const params = new URLSearchParams(window.location.search);

// ✅ RESTRICT SPIN WHEEL TO HOME PAGE ONLY
// Spin wheel should only appear on home_popup page
// On other pages (menu, cocktail), only show the lead capture form
const currentPage = params.get('page') || params.get('source') || '';
const isHomePage = currentPage === 'home_popup' || currentPage === 'home_popup_crm_test' || currentPage === 'home_popup_test';

const leadFormStage = document.getElementById('leadFormStage');
const spinStage = document.getElementById('spinStage');

// Hide spin wheel if not on home page
if (!isHomePage && spinStage) {
  spinStage.classList.add('hidden');
}

const leadForm = document.getElementById('leadCaptureForm');
const leadSubmitButton = document.getElementById('leadSubmitButton');
const leadFormStatus = document.getElementById('leadFormStatus');
const spinButton = document.getElementById('spinButton');
const spinStatus = document.getElementById('spinStatus');
const spinWheel = document.getElementById('spinWheel');
const wheelOffersNode = document.getElementById('wheelOffers');
const rewardBox = document.getElementById('rewardBox');
const rewardTitle = document.getElementById('rewardTitle');
const rewardMeta = document.getElementById('rewardMeta');
const continueActions = document.getElementById('continueActions');
const continueButton = document.getElementById('continueButton');
const GATE_COOLDOWN_HOURS = 24;

let activeLead = null;
let wheelOffers = [];
let wheelRotation = 0;

function setStatus(node, message, isError = false) {
  if (!node) return;
  node.textContent = message;
  node.classList.toggle('error', isError);
}

function normalizeDigits(value) {
  return String(value || '').replace(/\D/g, '');
}

async function loadOfferPreview() {
  if (!wheelOffersNode || !spinWheel) {
    return;
  }

  try {
    const response = await fetch(buildPhpActionUrl('public_spin_offers'), {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const payload = await response.json();
    const offers = Array.isArray(payload.offers) ? payload.offers.slice(0, 6) : [];

    if (!offers.length) {
      wheelOffers = [
        { label: 'Try Again' },
        { label: 'Free Mocktail' },
        { label: '10% Off Main Course' },
        { label: 'Dessert Shot' },
      ];
      wheelOffersNode.innerHTML = '<span>Try Again</span><span>Free Mocktail</span><span>10% Off Main Course</span><span>Dessert Shot</span>';
      return;
    }

    wheelOffers = offers;

    wheelOffersNode.innerHTML = offers
      .map((offer) => `<span>${escapeHtml(offer.label || 'Offer')}</span>`)
      .join('');

    const totalWeight = offers.reduce((carry, offer) => carry + Math.max(0, Number(offer.weight || 0)), 0);
    let running = 0;
    const slices = offers.map((offer, index) => {
      const color = offer.color || (index % 2 === 0 ? '#8d531a' : '#6a1b1b');
      const weight = Math.max(0, Number(offer.weight || 0));
      const start = running;
      if (totalWeight > 0) {
        running += (weight / totalWeight) * 360;
      } else {
        running += 360 / offers.length;
      }
      const end = running;
      return `${color} ${start}deg ${end}deg`;
    });

    spinWheel.style.background = `conic-gradient(from -18deg, ${slices.join(', ')})`;
  } catch (_) {
    wheelOffers = [
      { label: 'Try Again' },
      { label: 'Free Mocktail' },
      { label: '10% Off Main Course' },
      { label: 'Dessert Shot' },
    ];
    wheelOffersNode.innerHTML = '<span>Try Again</span><span>Free Mocktail</span><span>10% Off Main Course</span><span>Dessert Shot</span>';
  }
}

async function animateWheelToPrize(prizeLabel) {
  if (!spinWheel) {
    return;
  }

  const labels = (wheelOffers.length ? wheelOffers : [{ label: 'Try Again' }]).map((item) => String(item.label || '').toLowerCase());
  const normalizedPrize = String(prizeLabel || '').toLowerCase();
  let targetIndex = labels.findIndex((label) => label === normalizedPrize);
  if (targetIndex === -1) {
    targetIndex = labels.findIndex((label) => label.includes('try again'));
  }
  if (targetIndex === -1) {
    targetIndex = 0;
  }

  const segment = 360 / Math.max(1, labels.length);
  const segmentCenter = (targetIndex * segment) + (segment / 2);
  const pointerOffset = 90;
  const landingAngle = 360 - segmentCenter + pointerOffset;
  const extraTurns = 360 * 6;
  wheelRotation += extraTurns + landingAngle;

  spinWheel.style.transition = 'transform 2.2s cubic-bezier(0.18, 0.9, 0.2, 1)';
  spinWheel.style.transform = `rotate(${wheelRotation}deg)`;

  await new Promise((resolve) => window.setTimeout(resolve, 2250));
}

function postCompletion(payload) {
  if (window.parent && window.parent !== window) {
    window.parent.postMessage({ type: 'awg:lead-gate:complete', payload }, '*');
  }
}

function setRewardContent(payload) {
  rewardBox.classList.remove('hidden');
  continueActions.classList.remove('hidden');

  if (payload.prize === 'Try Again') {
    rewardTitle.textContent = 'Try Again';
    rewardMeta.innerHTML = 'You have completed today\'s spin. Please try again after 24 hours for another chance.';
    return;
  }

  rewardTitle.textContent = payload.prize;
  rewardMeta.innerHTML = payload.couponCode
    ? `Coupon Code: <strong>${payload.couponCode}</strong><br />Show this code at the restaurant while redeeming.`
    : 'Reward unlocked successfully.';
}

leadForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  leadSubmitButton.disabled = true;
  setStatus(leadFormStatus, 'Submitting your details...');

  const formPayload = {
    action: 'submit_lead',
    name: document.getElementById('leadName')?.value || '',
    countryCode: document.getElementById('leadCountryCode')?.value || '91',
    phone: document.getElementById('leadPhone')?.value || '',
    dateOfBirth: document.getElementById('leadDob')?.value || '',
    dateOfAnniversary: document.getElementById('leadAnniversary')?.value || '',
    source: params.get('source') || params.get('page') || 'menu_gate',
    visitCount: params.get('visit_count') || '1'
  };

  try {
    const response = await fetch(buildPhpActionUrl('submit_lead'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify(formPayload)
    });

    const payload = await response.json();
    if (!response.ok && !payload.ok) {
      throw new Error(payload.message || 'Lead submission failed.');
    }

    if (payload.result === 'cooldown_active' || payload.error === 'COOLDOWN_ACTIVE') {
      const retryAfterSeconds = Number(payload.retryAfterSeconds || 0);
      const hours = Math.max(1, Math.ceil(retryAfterSeconds / 3600));
      activeLead = {
        ...(payload || {}),
        prize: payload.prize || 'Try Again',
        gateCompletedAt: new Date().toISOString(),
      };

      leadFormStage.classList.add('hidden');
      spinStage.classList.remove('hidden');
      spinButton.disabled = true;
      spinButton.classList.add('hidden');
      setStatus(spinStatus, `You already completed a spin recently. You can spin again in about ${hours} hour${hours === 1 ? '' : 's'}.`);
      setRewardContent(activeLead);
      return;
    }

    if (!payload.ok) {
      throw new Error(payload.message || 'Lead submission failed.');
    }

    activeLead = payload;
    setStatus(leadFormStatus, 'Details saved. Your spin is ready.');
    leadFormStage.classList.add('hidden');
    spinStage.classList.remove('hidden');
  } catch (error) {
    setStatus(leadFormStatus, error instanceof Error ? error.message : 'Lead submission failed.', true);
  } finally {
    leadSubmitButton.disabled = false;
  }
});

spinButton?.addEventListener('click', async () => {
  if (!activeLead) {
    setStatus(spinStatus, 'Your lead was not created yet.', true);
    return;
  }

  spinButton.disabled = true;
  setStatus(spinStatus, 'Revealing your reward...');

  try {
    const response = await fetch(buildPhpActionUrl('complete_spin'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify({
        action: 'complete_spin',
        leadId: activeLead.leadId,
        phone: document.getElementById('leadPhone')?.value || '',
        countryCode: document.getElementById('leadCountryCode')?.value || '91'
      })
    });

    const payload = await response.json();
    if (!response.ok && !payload.ok) {
      throw new Error(payload.message || 'Spin completion failed.');
    }

    if (!payload.ok) {
      throw new Error(payload.message || 'Spin completion failed.');
    }

    await animateWheelToPrize(payload.prize);

    setStatus(spinStatus, 'Reward unlocked successfully.');
    setRewardContent(payload);
    activeLead = { ...activeLead, ...payload };
  } catch (error) {
    setStatus(spinStatus, error instanceof Error ? error.message : 'Spin completion failed.', true);
    spinButton.disabled = false;
  }
});

continueButton?.addEventListener('click', () => {
  if (!activeLead) {
    return;
  }

  const fallbackEpochMs = Date.now() + (GATE_COOLDOWN_HOURS * 60 * 60 * 1000);
  const retryAfterEpochMs = Number(activeLead.retryAfterEpochMs || fallbackEpochMs);
  const hoursUntilRetry = Math.max(1, Math.ceil((retryAfterEpochMs - Date.now()) / (60 * 60 * 1000)));

  postCompletion({
    ...(activeLead || {}),
    redirectTo: '#food-menu-card',
    gateCooldownHours: hoursUntilRetry,
    retryAfterEpochMs,
    gateCompletedAt: new Date().toISOString(),
  });
});

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// ✅ Only load spin wheel offers on home page
if (isHomePage) {
  loadOfferPreview().catch(() => {});
}
