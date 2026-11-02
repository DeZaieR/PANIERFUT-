(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const fmt = (n) => Math.round(n).toLocaleString('fr-FR') + ' €';
  const fill = (r) => r.style.setProperty('--fill', ((r.value - r.min) / (r.max - r.min)) * 100 + '%');

  /* Filtres des annonces (type, commune, budget) */
  const props = $$('.prop');
  const state = { type: 'all', town: 'all', max: Infinity };
  function apply() {
    let n = 0;
    props.forEach((p) => {
      const ok = (state.type === 'all' || p.dataset.type === state.type) && (state.town === 'all' || p.dataset.town === state.town) && +p.dataset.price <= state.max;
      p.classList.toggle('is-out', !ok);
      if (ok) {
        p.style.animationDelay = (n * 70) + 'ms';
        p.classList.remove('is-enter'); void p.offsetWidth; p.classList.add('is-enter');
        n++;
      }
    });
    $('#count').textContent = n;
    $('#empty').hidden = n > 0;
    $$('[data-filter]').forEach((b) => b.classList.toggle('is-on', b.dataset.filter === state.type));
    $('#s-type').value = state.type;
  }
  $$('[data-filter]').forEach((b) => b.addEventListener('click', () => { state.type = b.dataset.filter; apply(); }));
  const budget = $('#s-budget');
  budget.addEventListener('input', () => { $('#s-bout').textContent = fmt(budget.value); fill(budget); });
  fill(budget);
  $('#search').addEventListener('submit', (e) => {
    e.preventDefault();
    state.type = $('#s-type').value;
    state.town = $('#s-town').value;
    state.max = +budget.value;
    apply();
    $('#biens').scrollIntoView({ behavior: window.DC.reduce ? 'auto' : 'smooth' });
  });

  /* Favoris */
  $$('.fav').forEach((b) => b.addEventListener('click', (e) => {
    e.stopPropagation();
    const on = b.classList.toggle('is-on');
    b.setAttribute('aria-pressed', on);
  }));

  /* Langues : simple démonstration visuelle */
  $$('.langs button').forEach((b) => b.addEventListener('click', () => {
    $$('.langs button').forEach((x) => x.classList.toggle('is-on', x === b));
  }));

  /* Heure qui avance avec le scroll du héros (14:00 → 21:30) */
  const clock = $('.hero__clock');
  const hero = $('#hero');
  window.DC.scrollTasks.add(() => {
    const p = window.DC.progressOf(hero);
    const mins = 14 * 60 + Math.round(p * 7.5 * 60 / 5) * 5;
    clock.textContent = String(Math.floor(mins / 60)).padStart(2, '0') + ':' + String(mins % 60).padStart(2, '0');
  });

  /* Estimation (prix fictifs) */
  const surf = $('#e-surf');
  const out = $('#e-val');
  let shown = 0;
  function estimate() {
    fill(surf);
    $('#e-sout').textContent = surf.value;
    const rate = +$('input[name="k"]:checked').value * +$('#e-town').value;
    const v = rate * +surf.value;
    const start = shown, t0 = performance.now();
    const run = (t) => {
      const k = Math.min(1, (t - t0) / 600);
      const cur = start + (v - start) * (1 - Math.pow(1 - k, 3));
      out.textContent = fmt(Math.round(cur * 0.94 / 1000) * 1000) + ' – ' + fmt(Math.round(cur * 1.06 / 1000) * 1000);
      if (k < 1) requestAnimationFrame(run); else shown = v;
    };
    requestAnimationFrame(run);
  }
  surf.addEventListener('input', estimate);
  $('#e-town').addEventListener('change', estimate);
  $$('input[name="k"]').forEach((r) => r.addEventListener('change', estimate));
  estimate();
  $('#est').addEventListener('submit', (e) => { e.preventDefault(); $('.est__ok').hidden = false; });
})();
