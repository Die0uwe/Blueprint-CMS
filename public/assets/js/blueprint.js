/* Blueprint CMS — Core JS v1.0.0
   Copyright (C) 2026 DieOuwe — GPL-3.0-or-later */
'use strict';
document.querySelectorAll('.cf-nav-link').forEach(link => {
  if (link.href === window.location.href) link.classList.add('active');
});
setTimeout(() => {
  // Foutmeldingen blijven staan (de bezoeker moet ze kunnen lezen/oplossen); alleen succes/info verdwijnt.
  document.querySelectorAll('.cf-alert:not(.cf-alert-error):not(.cf-alert-warning)').forEach(el => {
    el.style.transition = 'opacity .4s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 400);
  });
}, 5000);

/* Sliders (galerij- en downloads-blokken): knoppen, toetsenbord en optionele autoplay. */
document.querySelectorAll('[data-cf-slider]').forEach(slider => {
  const track = slider.querySelector('.cf-slider-track');
  if (!track) return;
  const step = dir => {
    const max = track.scrollWidth - track.clientWidth;
    if (dir > 0 && track.scrollLeft >= max - 4) track.scrollTo({ left: 0 });
    else if (dir < 0 && track.scrollLeft <= 4) track.scrollTo({ left: max });
    else track.scrollBy({ left: dir * track.clientWidth });
  };
  slider.querySelector('.cf-slider-prev')?.addEventListener('click', () => step(-1));
  slider.querySelector('.cf-slider-next')?.addEventListener('click', () => step(1));
  track.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight') { step(1); e.preventDefault(); }
    if (e.key === 'ArrowLeft') { step(-1); e.preventDefault(); }
  });
  const ms = parseInt(slider.dataset.autoplay || '0', 10);
  if (ms >= 2000 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    let paused = false;
    ['mouseenter', 'focusin', 'touchstart'].forEach(ev => slider.addEventListener(ev, () => { paused = true; }, { passive: true }));
    ['mouseleave', 'focusout'].forEach(ev => slider.addEventListener(ev, () => { paused = false; }));
    setInterval(() => { if (!paused && !document.hidden) step(1); }, ms);
  }
});
