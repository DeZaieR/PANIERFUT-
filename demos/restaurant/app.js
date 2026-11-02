(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

  /* Onglets de la carte avec pastille glissante */
  const tabs = $$('[data-tab]');
  const pill = $('.tabs__pill');
  function movePill(btn) {
    pill.style.width = btn.offsetWidth + 'px';
    pill.style.transform = `translateX(${btn.offsetLeft}px)`;
  }
  function select(btn) {
    tabs.forEach((b) => {
      const on = b === btn;
      b.setAttribute('aria-selected', on);
      b.tabIndex = on ? 0 : -1;
      const panel = document.getElementById(b.getAttribute('aria-controls'));
      panel.hidden = !on;
      panel.classList.toggle('is-active', on);
    });
    movePill(btn);
  }
  tabs.forEach((b, i) => {
    b.addEventListener('click', () => select(b));
    b.addEventListener('keydown', (e) => {
      const k = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
      if (!k) return;
      const n = tabs[(i + k + tabs.length) % tabs.length];
      n.focus(); select(n);
    });
  });
  const firstTab = tabs.find((b) => b.getAttribute('aria-selected') === 'true');
  movePill(firstTab);
  addEventListener('resize', () => movePill(tabs.find((b) => b.getAttribute('aria-selected') === 'true')));
  document.fonts && document.fonts.ready.then(() => movePill(tabs.find((b) => b.getAttribute('aria-selected') === 'true')));

  /* Nombre de couverts */
  const out = $('#guests');
  $$('[data-step]').forEach((b) => b.addEventListener('click', () => {
    const v = Math.min(8, Math.max(1, parseInt(out.value || out.textContent, 10) + parseInt(b.dataset.step, 10)));
    out.textContent = v;
    out.animate([{ transform: 'scale(1.5)', color: '#e9b56f' }, { transform: 'scale(1)' }], { duration: 450, easing: 'cubic-bezier(.16,1,.3,1)' });
  }));

  /* Date minimale : aujourd'hui */
  const date = $('input[type="date"]');
  const today = new Date();
  date.min = today.toISOString().slice(0, 10);
  today.setDate(today.getDate() + 2);
  date.value = today.toISOString().slice(0, 10);

  /* Formulaire de démonstration : rien n'est envoyé */
  const form = $('#bform');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const ok = $('.bform__ok', form);
    ok.hidden = false;
    ok.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });

  /* Galerie : glisser pour tourner (en plus du scroll) */
  const ring = $('#ring');
  let drag = 0, startX = 0, base = 0, down = false;
  ring.addEventListener('pointerdown', (e) => { down = true; startX = e.clientX; base = drag; ring.setPointerCapture(e.pointerId); });
  ring.addEventListener('pointermove', (e) => { if (!down) return; drag = base + (e.clientX - startX) * 0.25; ring.style.setProperty('--drag', drag + 'deg'); });
  ring.addEventListener('pointerup', () => { down = false; });
})();
