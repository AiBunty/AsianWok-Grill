/**
 * Menu Blocker + Spin Wheel System
 * Complete JavaScript Implementation with 1:1 Behavior Parity
 */

class MenuBlocker {
  constructor() {
    this.state = {
      phase: 'form', // form, spin, result-winner, result-tryagain, cooldown
      formData: null,
      wheelResult: null,
      bypassCode: '',
      lastSpinTime: null,
      canSpinAgain: true,
    };

    this.config = {
      wheelSegments: 4, // Will be set from admin offers
      spinDuration: 4200, // 4.2s exact
      spinEasing: 'quartic', // easing function
      cooldownHours: 24,
      wheelColors: [], // Will be populated from admin offers
      wheelPrizes: [], // Will be populated from admin offers
      wheelOffers: [], // Raw admin offers
    };

    this.settings = {
      menuBlockerPages: {},
      menuBlockerStaffCode: '',
      hotelWhatsappNo: '',
      enabled: true,
    };

    this.savedScrollY = 0;
    this.scrollLocked = false;

    this.init();
  }

  async init() {
    this.cacheDOM();
    await this.loadSettings();
    await this.loadSpinOffers(); // Load offers from admin before setup
    this.populateCountryCodes();
    this.setupEventListeners();
    this.restoreState();
    this.show();
  }

  cacheDOM() {
    // Overlay & Phases
    this.overlay = document.getElementById('mb-overlay');
    this.card = document.querySelector('.mb-card');
    this.formPhase = document.getElementById('mb-form-phase');
    this.spinPhase = document.getElementById('mb-spin-phase');
    this.resultWinnerPhase = document.getElementById('mb-result-winner-phase');
    this.resultTryAgainPhase = document.getElementById('mb-result-tryagain-phase');
    this.cooldownPhase = document.getElementById('mb-cooldown-phase');

    // Form Elements
    this.form = document.getElementById('mb-form');
    this.nameInput = document.getElementById('mb-name');
    this.countrySelect = document.getElementById('mb-country-code');
    this.phoneInput = document.getElementById('mb-phone');
    this.dobInput = document.getElementById('mb-dob');
    this.anniversaryInput = document.getElementById('mb-anniversary');
    this.formSubmit = document.getElementById('mb-submit');
    this.formStatus = document.getElementById('mb-form-status');

    // Bypass Elements
    this.bypassToggle = document.getElementById('mb-bypass-toggle');
    this.bypassPanel = document.getElementById('mb-bypass-panel');
    this.bypassCode = document.getElementById('mb-bypass-code');
    this.bypassSubmit = document.getElementById('mb-bypass-submit');
    this.bypassStatus = document.getElementById('mb-bypass-status');

    // Spin Wheel Elements
    this.wheelCanvas = document.getElementById('mb-wheel-canvas');
    this.wheelCtx = this.wheelCanvas.getContext('2d');
    this.spinButton = document.getElementById('mb-spin-button');
    this.spinStatus = document.getElementById('mb-spin-status');
    this.spinSubtitle = document.getElementById('mb-spin-subtitle');

    // Result Elements
    this.resultPrizeText = document.getElementById('mb-result-prize-text');
    this.resultMetaText = document.getElementById('mb-result-meta-text');
    this.couponSection = document.getElementById('mb-coupon-section');
    this.couponCode = document.getElementById('mb-coupon-code');
    this.copyCoupon = document.getElementById('mb-copy-coupon');
    this.sendWhatsApp = document.getElementById('mb-send-whatsapp');
    this.continueWinner = document.getElementById('mb-continue-winner');
    this.askSurprise = document.getElementById('mb-ask-surprise');
    this.tryAgainStatus = document.getElementById('mb-tryagain-status');
    this.continueTryAgain = document.getElementById('mb-continue-tryagain');

    // Cooldown Elements
    this.cooldownTimer = document.getElementById('mb-cooldown-timer');
    this.continueCooldown = document.getElementById('mb-continue-cooldown');
  }

  setupEventListeners() {
    this.form.addEventListener('submit', (e) => this.handleFormSubmit(e));
    this.bypassToggle.addEventListener('click', () => this.toggleBypassPanel());
    this.bypassSubmit.addEventListener('click', () => this.verifyBypassCode());
    this.spinButton.addEventListener('click', () => this.spinWheel());
    this.copyCoupon.addEventListener('click', () => this.copyCouponCode());
    this.sendWhatsApp.addEventListener('click', () => this.sendWinnerToWhatsApp());
    this.continueWinner.addEventListener('click', () => this.closeBlocker('#food-menu-card'));
    this.askSurprise.addEventListener('click', () => this.sendSurpriseRequest());
    this.continueTryAgain.addEventListener('click', () => this.closeBlocker('#food-menu-card'));
    this.continueCooldown.addEventListener('click', () => this.closeBlocker('#food-menu-card'));

    // Draw wheel after all setup is complete
    setTimeout(() => this.drawWheel(0), 100);
  }

  async loadSettings() {
    try {
      const response = await fetch(buildPhpActionUrl('action=settings_get&setting_group=menuBlocker'));
      if (response.ok) {
        const result = await response.json();
        if ((result.success || result.ok) && result.settings) {
          this.settings = { ...this.settings, ...result.settings };
        }
      }
    } catch (err) {
      console.warn('Failed to load menu blocker settings:', err);
    }
  }

  async loadSpinOffers() {
    try {
      const response = await fetch(buildPhpActionUrl('public_spin_offers'));
      if (response.ok) {
        const result = await response.json();
        console.log('Spin offers loaded:', result);
        if ((result.ok || result.success) && Array.isArray(result.offers)) {
          const offers = result.offers.filter((o) => o.isActive !== false);
          console.log('Active offers:', offers);
          if (offers.length > 0) {
            // Update config with admin offers
            this.config.wheelSegments = offers.length;
            this.config.wheelOffers = offers;
            this.config.wheelColors = offers.map((o) => o.color || '#C7A46B');
            this.config.wheelPrizes = offers.map((o) => o.label);
            console.log('Updated wheel config:', this.config.wheelSegments, this.config.wheelPrizes);
          }
        }
      }
    } catch (err) {
      console.warn('Failed to load spin offers:', err);
      // Fall back to minimal defaults
      this.config.wheelSegments = 1;
      this.config.wheelColors = ['#C7A46B'];
      this.config.wheelPrizes = ['Spin Error - Retry Later'];
    }
  }

  populateCountryCodes() {
    if (!window.COUNTRY_CODES || !Array.isArray(window.COUNTRY_CODES)) {
      console.error('Country codes dataset not loaded');
      return;
    }

    const defaultIndex = window.COUNTRY_CODES.findIndex((c) => c.iso === 'IN');
    window.COUNTRY_CODES.forEach((country, index) => {
      const option = document.createElement('option');
      option.value = country.dial;
      option.textContent = `${country.country} ${country.dial}`;
      option.dataset.iso = country.iso;
      if (index === defaultIndex) {
        option.selected = true;
      }
      this.countrySelect.appendChild(option);
    });
  }

  toggleBypassPanel() {
    this.bypassPanel.classList.toggle('mb-hidden');
    if (!this.bypassPanel.classList.contains('mb-hidden')) {
      this.bypassCode.focus();
    }
  }

  async verifyBypassCode() {
    const code = this.bypassCode.value.trim();
    if (!code) {
      this.showStatus(this.bypassStatus, 'Please enter staff code', 'error');
      return;
    }

    // Refresh settings before verification so recently saved admin values work immediately.
    await this.loadSettings();

    const entered = String(code).trim().toUpperCase();
    const stored = String(this.settings.menuBlockerStaffCode || '').trim().toUpperCase();

    if (!stored) {
      this.showStatus(this.bypassStatus, 'Staff code not configured. Please contact admin.', 'error');
      return;
    }

    if (entered !== stored) {
      this.showStatus(this.bypassStatus, 'Invalid staff code', 'error');
      return;
    }

    this.showStatus(this.bypassStatus, 'Staff verified. Closing blocker...', 'success');
    this.state.bypassCode = entered;
    this.closeBlocker();
  }

  handleFormSubmit(e) {
    e.preventDefault();

    const name = this.nameInput.value.trim();
    const countryCode = this.countrySelect.value;
    const phone = this.phoneInput.value.trim();
    const dateOfBirth = this.dobInput.value;
    const dateOfAnniversary = this.anniversaryInput.value;

    // Validate
    if (!name || name.length < 2) {
      this.showStatus(this.formStatus, 'Please enter a valid name', 'error');
      return;
    }

    if (!phone || phone.length < 7) {
      this.showStatus(this.formStatus, 'Please enter a valid phone number', 'error');
      return;
    }

    this.state.formData = { name, countryCode, phone, dateOfBirth, dateOfAnniversary };
    this.transitionPhase('spin');
  }

  transitionPhase(phase) {
    this.state.phase = phase;

    // Hide all phases
    this.formPhase.classList.add('mb-hidden');
    this.spinPhase.classList.add('mb-hidden');
    this.resultWinnerPhase.classList.add('mb-hidden');
    this.resultTryAgainPhase.classList.add('mb-hidden');
    this.cooldownPhase.classList.add('mb-hidden');

    // Show target phase
    switch (phase) {
      case 'form':
        this.formPhase.classList.remove('mb-hidden');
        break;
      case 'spin':
        this.spinPhase.classList.remove('mb-hidden');
        this.spinButton.disabled = false;
        this.spinStatus.textContent = 'Tap Spin Now to reveal your result.';
        break;
      case 'result-winner':
        this.resultWinnerPhase.classList.remove('mb-hidden');
        this.displayWinnerResult();
        break;
      case 'result-tryagain':
        this.resultTryAgainPhase.classList.remove('mb-hidden');
        this.displayTryAgainResult();
        break;
      case 'cooldown':
        this.cooldownPhase.classList.remove('mb-hidden');
        this.startCooldownTimer();
        break;
    }

    this.saveState();
  }

  spinWheel() {
    if (!this.spinButton || this.spinButton.disabled) return;

    this.spinButton.disabled = true;
    this.spinStatus.textContent = 'Spinning...';

    // Get backend-driven prize (ensures fairness, prevents cheating)
    this.getSpinResult();
  }

  async getSpinResult() {
    try {
      const payload = {
        name: this.state.formData.name,
        phone: this.state.formData.phone,
        country_code: this.state.formData.countryCode,
        date_of_birth: this.state.formData.dateOfBirth || '',
        date_of_anniversary: this.state.formData.dateOfAnniversary || '',
        source: 'spinwheel_menu_blocker',
      };

      const response = await fetch(buildPhpActionUrl('action=qr_spin_wheel_get_prize'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      // Parse JSON first so we can show the real backend message on errors,
      // rather than a generic "Connection error".
      let result;
      try {
        result = await response.json();
      } catch (_) {
        throw new Error(`HTTP ${response.status}`);
      }

      if (!response.ok) {
        this.spinStatus.textContent = result.message || 'Server error. Please try again.';
        this.spinButton.disabled = false;
        return;
      }

      if (result.success) {
        this.state.wheelResult = result.outcome; // { prizeIndex, prizeText, couponCode, message }
        this.performWheelAnimation(result.outcome.prizeIndex);
      } else {
        if (result.cooledDown) {
          this.transitionPhase('cooldown');
        } else {
          this.spinStatus.textContent = result.message || 'Error spinning wheel';
          this.spinButton.disabled = false;
        }
      }
    } catch (err) {
      console.error('Error getting spin result:', err);
      this.spinStatus.textContent = 'Connection error. Please try again.';
      this.spinButton.disabled = false;
    }
  }

  performWheelAnimation(targetSegmentIndex) {
    const startTime = performance.now();
    const baseRotation = 0;
    const segmentAngle = 360 / this.config.wheelSegments;
    const pointerAtTop = Math.PI / 2; // Pointer at 12 o'clock

    // Calculate total rotation: full spins + target segment offset
    const totalRotations = 5; // 5 full rotations
    const targetRotation = totalRotations * 360 + (360 - targetSegmentIndex * segmentAngle - segmentAngle / 2);

    const animate = (currentTime) => {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / this.config.spinDuration, 1);

      // Quartic easing (acceleration then deceleration)
      const easeProgress = progress < 0.5
        ? 8 * progress ** 4
        : 1 - 8 * (1 - progress) ** 4;

      const currentRotation = baseRotation + targetRotation * easeProgress;
      this.drawWheel(currentRotation % 360);

      if (progress < 1) {
        requestAnimationFrame(animate);
      } else {
        // Animation complete
        this.spinStatus.textContent = '';
        this.spinButton.disabled = false;

        // Determine result type
        const isWinner = !this.isTryAgainPrize(this.state.wheelResult);
        setTimeout(() => {
          this.transitionPhase(isWinner ? 'result-winner' : 'result-tryagain');
        }, 400);
      }
    };

    requestAnimationFrame(animate);
  }

  drawWheel(rotation = 0) {
    const canvas = this.wheelCanvas;
    const ctx = this.wheelCtx;
    const centerX = canvas.width / 2;
    const centerY = canvas.height / 2;
    const radius = Math.min(canvas.width, canvas.height) / 2 - 12;

    // Clear canvas
    ctx.fillStyle = '#1b1111';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.save();
    ctx.translate(centerX, centerY);
    ctx.rotate((rotation * Math.PI) / 180);

    // Outer glow rings for premium neon wheel depth
    const ringGradient = ctx.createRadialGradient(0, 0, radius * 0.76, 0, 0, radius * 1.12);
    ringGradient.addColorStop(0, 'rgba(119, 246, 255, 0)');
    ringGradient.addColorStop(0.55, 'rgba(119, 246, 255, 0.22)');
    ringGradient.addColorStop(1, 'rgba(119, 246, 255, 0)');
    ctx.beginPath();
    ctx.arc(0, 0, radius * 1.06, 0, Math.PI * 2);
    ctx.fillStyle = ringGradient;
    ctx.fill();

    ctx.beginPath();
    ctx.arc(0, 0, radius + 1.5, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(145, 224, 255, 0.7)';
    ctx.lineWidth = 2;
    ctx.stroke();

    // Draw segments
    const segmentAngle = (2 * Math.PI) / this.config.wheelSegments;
    for (let i = 0; i < this.config.wheelSegments; i++) {
      const startAngle = i * segmentAngle;
      const endAngle = startAngle + segmentAngle;

      // Segment arc
      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.arc(0, 0, radius, startAngle, endAngle);
      ctx.closePath();
      ctx.fillStyle = this.config.wheelColors[i];
      ctx.fill();

      // Neon glow effect
      ctx.strokeStyle = this.config.wheelColors[i];
      ctx.lineWidth = 3;
      ctx.globalAlpha = 0.6;
      ctx.stroke();
      ctx.globalAlpha = 1;

      // Text label
      const textAngle = startAngle + segmentAngle / 2;
      const textRadius = radius * 0.68;
      const textX = Math.cos(textAngle) * textRadius;
      const textY = Math.sin(textAngle) * textRadius;
      const displayLabel = this.getWheelLabel(this.config.wheelPrizes[i]);

      ctx.save();
      ctx.translate(textX, textY);
      ctx.rotate(textAngle + Math.PI / 2);
      ctx.fillStyle = '#fff8e7';
      ctx.strokeStyle = 'rgba(0, 0, 0, 0.35)';
      ctx.lineWidth = 2;
      ctx.font = 'bold 13px Arial';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.strokeText(displayLabel, 0, 0);
      ctx.fillText(displayLabel, 0, 0);
      ctx.restore();
    }

    // Center circle
    ctx.beginPath();
    ctx.arc(0, 0, 18, 0, 2 * Math.PI);
    ctx.fillStyle = '#f0c48f';
    ctx.fill();
    ctx.strokeStyle = '#1b1111';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.restore();
  }

  getWheelLabel(prizeText) {
    // Truncate long labels to fit on wheel segments
    if (!prizeText) return '';
    
    // Replace "Free" with nothing to shorten labels
    let label = prizeText.replace(/^Free\s+/i, '').trim();
    
    // Truncate to max 15 chars
    if (label.length > 15) {
      label = label.substring(0, 12) + '...';
    }
    
    return label || prizeText;
  }

  displayWinnerResult() {
    if (!this.state.wheelResult) return;

    const { prizeText, couponCode, message } = this.state.wheelResult;
    const isWinnerWithCoupon = !this.isTryAgainPrize(this.state.wheelResult) && Boolean(couponCode);

    this.resultPrizeText.textContent = prizeText;
    this.resultMetaText.textContent = message || 'Congratulations! Show the coupon code to our staff to claim your prize.';
    this.resultMetaText.classList.remove('mb-error', 'mb-success', 'mb-info');

    this.toggleElement(this.couponSection, isWinnerWithCoupon);
    this.toggleElement(this.copyCoupon, isWinnerWithCoupon);
    this.toggleElement(this.sendWhatsApp, isWinnerWithCoupon);
    this.toggleElement(this.askSurprise, false);

    if (isWinnerWithCoupon) {
      this.couponCode.textContent = couponCode;
    } else {
      this.couponCode.textContent = '-';
    }
  }

  displayTryAgainResult() {
    this.toggleElement(this.couponSection, false);
    this.toggleElement(this.copyCoupon, false);
    this.toggleElement(this.sendWhatsApp, false);
    this.toggleElement(this.askSurprise, true);

    if (this.tryAgainStatus) {
      this.tryAgainStatus.textContent = '';
      this.tryAgainStatus.classList.remove('mb-error', 'mb-success', 'mb-info');
    }
  }

  copyCouponCode() {
    const code = this.couponCode.textContent;
    navigator.clipboard.writeText(code).then(() => {
      this.couponCode.classList.add('mb-copied');
      const originalBg = this.copyCoupon.style.background;
      this.copyCoupon.textContent = 'Copied!';
      setTimeout(() => {
        this.couponCode.classList.remove('mb-copied');
        this.copyCoupon.textContent = 'Copy Code';
      }, 2000);
    });
  }

  async sendWinnerToWhatsApp() {
    if (!this.state.wheelResult || !this.state.formData) return;

    const couponCode = String(this.state.wheelResult.couponCode || '').trim();
    if (!couponCode) {
      this.showStatus(this.resultMetaText, 'Unable to prepare WhatsApp draft.', 'error');
      return;
    }

    const hotelNumber = this.resolveHotelWhatsappNumber();
    if (!hotelNumber) {
      this.showStatus(this.resultMetaText, 'Hotel WhatsApp number is not configured.', 'error');
      return;
    }

    try {
      const message = this.buildWinnerWhatsappMessage({
        ...this.state.formData,
        prize: this.state.wheelResult.prizeText,
        couponCode,
      });
      this.openWhatsappDraft(hotelNumber, message);
      this.showStatus(this.resultMetaText, 'Opening WhatsApp with your redemption details.', 'info');
    } catch (err) {
      console.error('Failed to prepare winner WhatsApp draft:', err);
      this.showStatus(this.resultMetaText, 'Unable to prepare WhatsApp draft.', 'error');
    }
  }

  async sendSurpriseRequest() {
    if (!this.state.formData) return;

    const hotelNumber = this.resolveHotelWhatsappNumber();
    if (!hotelNumber) {
      this.showStatus(this.tryAgainStatus, 'Hotel WhatsApp number is not configured.', 'error');
      return;
    }

    try {
      const message = this.buildTryAgainWhatsappMessage({
        ...this.state.formData,
        prize: 'Try Again',
      });
      this.openWhatsappDraft(hotelNumber, message);
      this.showStatus(this.tryAgainStatus, 'Opening WhatsApp so you can ask the captain about your surprise offer.', 'info');
    } catch (err) {
      console.error('Failed to prepare try-again WhatsApp draft:', err);
      this.showStatus(this.tryAgainStatus, 'Unable to prepare WhatsApp draft.', 'error');
    }
  }

  buildWinnerWhatsappMessage(payload) {
    const couponCode = String(payload.couponCode || '').trim();
    if (!couponCode) {
      throw new Error('Missing coupon code');
    }

    return [
      'Hello Admin, I want to redeem my Spin & Win coupon.',
      '',
      `Name: ${String(payload.name || '').trim()}`,
      `Mobile: ${this.formatCustomerMobile(payload.countryCode, payload.phone)}`,
      `DOB: ${payload.dateOfBirth || payload.dob || '-'}`,
      `Anniversary: ${payload.dateOfAnniversary || payload.anniversary || '-'}`,
      `Prize: ${String(payload.prize || '').trim()}`,
      `Coupon Code: ${couponCode}`,
      `Requested At: ${this.getLocalTimestamp()}`,
    ].join('\n');
  }

  buildTryAgainWhatsappMessage(payload) {
    return [
      'Hi Captain,',
      '',
      `I got "Try Again" on the Spin & Win at ${this.getHotelName()}.`,
      '',
      `Name: ${String(payload.name || '').trim()}`,
      `Mobile: ${this.formatCustomerMobile(payload.countryCode, payload.phone)}`,
      `DOB: ${payload.dateOfBirth || payload.dob || '-'}`,
      `Anniversary: ${payload.dateOfAnniversary || payload.anniversary || '-'}`,
      '',
      'I got Try Again, and I want a surprise.',
      'Please let me know the surprise offer available for me.',
      '',
      'Thank you.',
    ].join('\n');
  }

  formatCustomerMobile(countryCode, phone) {
    const countryDigits = String(countryCode || '').replace(/\D/g, '');
    const localDigits = String(phone || '').replace(/\D/g, '');

    if (!localDigits) {
      throw new Error('Missing phone');
    }

    if (countryDigits && localDigits.startsWith(countryDigits) && localDigits.length > 10) {
      return `+${localDigits}`;
    }

    return `+${countryDigits}${localDigits}`;
  }

  resolveHotelWhatsappNumber() {
    const settingsNumber = this.normalizeWhatsappNumber(this.settings.hotelWhatsappNo);
    if (settingsNumber) {
      return settingsNumber;
    }

    const footerTel = document.querySelector('footer a[href^="tel:"]') || document.querySelector('a[href^="tel:"]');
    const footerNumber = this.normalizeWhatsappNumber(footerTel ? footerTel.getAttribute('href') : '');
    if (footerNumber) {
      return footerNumber;
    }

    return this.normalizeWhatsappNumber('+919876543210');
  }

  normalizeWhatsappNumber(value) {
    return String(value || '').replace(/\D/g, '');
  }

  openWhatsappDraft(hotelNumber, message) {
    if (!message || !String(message).trim()) {
      throw new Error('Missing message');
    }

    const whatsappUrl = `https://wa.me/${hotelNumber}?text=${encodeURIComponent(message)}`;
    window.open(whatsappUrl, '_blank', 'noopener');
  }

  getLocalTimestamp() {
    return new Date().toLocaleString();
  }

  getHotelName() {
    return String(this.settings.hotelName || 'Asian Wok & Grill').trim();
  }

  isTryAgainPrize(result) {
    // Check if prize text contains "try again" (case-insensitive)
    const prizeText = String(result?.prizeText || '').trim().toLowerCase();
    return prizeText === 'try again' || prizeText.includes('try again');
  }

  toggleElement(element, shouldShow) {
    if (!element) return;
    element.classList.toggle('mb-hidden', !shouldShow);
  }

  startCooldownTimer() {
    const lastSpinTime = this.state.lastSpinTime || Date.now();
    const cooldownMs = this.config.cooldownHours * 60 * 60 * 1000;
    const nextSpinTime = lastSpinTime + cooldownMs;

    const updateTimer = () => {
      const now = Date.now();
      const remaining = Math.max(0, nextSpinTime - now);

      if (remaining <= 0) {
        this.cooldownTimer.textContent = '00:00:00';
        return;
      }

      const hours = Math.floor(remaining / (1000 * 60 * 60));
      const minutes = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
      const seconds = Math.floor((remaining % (1000 * 60)) / 1000);

      this.cooldownTimer.textContent = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

      if (remaining > 0) {
        setTimeout(updateTimer, 1000);
      }
    };

    updateTimer();
  }

  showStatus(element, message, type = 'info') {
    if (!element) return;

    element.textContent = message;
    element.classList.remove('mb-error', 'mb-success', 'mb-info');
    element.classList.add(`mb-${type}`);

    if (type === 'success') {
      setTimeout(() => {
        element.classList.remove('mb-success');
        element.textContent = '';
      }, 3000);
    }
  }

  saveState() {
    sessionStorage.setItem('mb_state', JSON.stringify(this.state));
  }

  restoreState() {
    const saved = sessionStorage.getItem('mb_state');
    if (saved) {
      try {
        this.state = { ...this.state, ...JSON.parse(saved) };
      } catch (err) {
        console.warn('Failed to restore blocker state:', err);
      }
    }
  }

  show() {
    this.overlay.classList.remove('mb-hidden');
    this.lockScrollToTop();
  }

  closeBlocker(targetSelector = '') {
    this.overlay.classList.add('mb-hidden');
    this.state.lastSpinTime = Date.now();
    this.saveState();
    this.unlockScroll();

    const selector = String(targetSelector || '').trim();
    if (selector) {
      requestAnimationFrame(() => {
        const target = document.querySelector(selector);
        if (target && typeof target.scrollIntoView === 'function') {
          target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    }
  }

  lockScrollToTop() {
    if (this.scrollLocked) return;

    this.savedScrollY = window.scrollY || window.pageYOffset || 0;
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
    document.body.style.position = 'fixed';
    document.body.style.top = `-${this.savedScrollY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';

    window.scrollTo(0, 0);
    this.scrollLocked = true;
  }

  unlockScroll() {
    if (!this.scrollLocked) return;

    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';

    window.scrollTo(0, this.savedScrollY || 0);
    this.scrollLocked = false;
  }
}

function buildPhpActionUrl(queryString) {
  const qs = String(queryString || '').replace(/^\?+/, '');
  // If it doesn't already start with "action=", add it
  const finalQs = qs.startsWith('action=') ? qs : 'action=' + qs;
  return '/?' + finalQs;
}

// Initialize on load
document.addEventListener('DOMContentLoaded', () => {
  window.menuBlocker = new MenuBlocker();
});
