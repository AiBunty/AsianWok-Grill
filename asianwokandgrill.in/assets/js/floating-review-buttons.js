(function setupScrollTopButton() {
  'use strict';
  const btn = document.getElementById('scrollTopBtn');
  if (!btn) return;

  btn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  function toggleBtn() {
    btn.style.display = window.scrollY > 300 ? 'flex' : 'none';
  }

  window.addEventListener('scroll', toggleBtn, { passive: true });
  toggleBtn();
})();
