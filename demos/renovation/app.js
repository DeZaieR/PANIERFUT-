(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

  /* Cartes services : la couleur part du point d'entrée du curseur */
  $$('[data-fill]').forEach((el) => {
    const set = (e) => {
      const r = el.getBoundingClientRect();
      el.style.setProperty('--fx', (e.clientX - r.left) + 'px');
      el.style.setProperty('--fy', (e.clientY - r.top) + 'px');
    };
    el.addEventListener('pointerenter', set);
    el.addEventListener('pointerleave', set);
  });

  /* Avant / après */
  const ba = $('#ba');
  const range = $('.ba__range', ba);
  const setPos = (v) => ba.style.setProperty('--pos', v + '%');
  range.addEventListener('input', () => setPos(range.value));
  // Une petite démonstration du glissement quand la zone apparaît
  const io = new IntersectionObserver((e) => {
    if (!e[0].isIntersecting || window.DC.reduce) return;
    io.disconnect();
    const t0 = performance.now();
    const run = (t) => {
      const k = Math.min(1, (t - t0) / 2200);
      const v = 50 + Math.sin(k * Math.PI * 2) * 28 * (1 - k);
      setPos(v); range.value = v;
      if (k < 1) requestAnimationFrame(run);
    };
    requestAnimationFrame(run);
  }, { threshold: 0.6 });
  io.observe(ba);

  /* Zone : villes et repères liés */
  $$('[data-town]').forEach((el) => {
    const id = el.dataset.town;
    el.addEventListener('pointerenter', () => $$(`[data-town="${id}"]`).forEach((x) => x.classList.add('is-hot')));
    el.addEventListener('pointerleave', () => $$(`[data-town="${id}"]`).forEach((x) => x.classList.remove('is-hot')));
  });

  /* Estimation (prix fictifs) */
  const surf = $('#surface');
  const out = $('#surf');
  const val = $('#estv');
  let shown = 0;
  function estimate() {
    const s = +surf.value;
    out.textContent = s;
    surf.style.setProperty('--fill', ((s - surf.min) / (surf.max - surf.min)) * 100 + '%');
    let total = 0;
    $$('.est__types input:checked').forEach((c) => { total += (+c.dataset.rate) * s * 0.6 + (+c.dataset.fixed || 0); });
    const from = Math.round(total * 0.9 / 100) * 100;
    const to = Math.round(total * 1.15 / 100) * 100;
    const start = shown, t0 = performance.now();
    const fmt = (n) => n.toLocaleString('fr-FR') + ' €';
    if (!total) { val.textContent = 'Choisissez un type de travaux'; shown = 0; return; }
    const run = (t) => {
      const k = Math.min(1, (t - t0) / 500);
      const e = 1 - Math.pow(1 - k, 3);
      const f = start + (from - start) * e;
      val.textContent = fmt(Math.round(f / 100) * 100) + ' – ' + fmt(Math.round((f * to / from) / 100) * 100);
      if (k < 1) requestAnimationFrame(run); else shown = from;
    };
    requestAnimationFrame(run);
  }
  surf.addEventListener('input', estimate);
  $$('.est__types input').forEach((c) => c.addEventListener('change', estimate));
  estimate();
  $('#est').addEventListener('submit', (e) => { e.preventDefault(); $('.est__ok').hidden = false; });
})();
