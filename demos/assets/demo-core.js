/* DYRO WEB — socle commun des sites de démonstration.
   Curseur, aimantation, inclinaison 3D, textes découpés, révélations et progression au scroll.
   Sans dépendance. Respecte « mouvement réduit » et les écrans tactiles. */
(function () {
  'use strict';
  const d = document;
  const root = d.documentElement;
  const $ = (s, c = d) => c.querySelector(s);
  const $$ = (s, c = d) => Array.from(c.querySelectorAll(s));
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const lerp = (a, b, k) => a + (b - a) * k;
  root.classList.add('js');
  if (reduce) root.classList.add('reduce');

  const DC = (window.DC = { reduce, fine, clamp, lerp, mouse: { x: 0, y: 0, nx: 0, ny: 0 }, scrollY: 0, vh: innerHeight, tasks: new Set(), scrollTasks: new Set() });

  /* Boucle d'animation unique */
  let last = performance.now();
  function frame(t) {
    const dt = Math.min(3, (t - last) / 16.67);
    last = t;
    DC.tasks.forEach((fn) => fn(t, dt));
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  /* Textes découpés en lettres (data-split="chars") ou mots */
  $$('[data-split]').forEach((el) => {
    const mode = el.dataset.split || 'words';
    let i = 0;
    const walk = (node) => {
      Array.from(node.childNodes).forEach((n) => {
        if (n.nodeType === 3) {
          const frag = d.createDocumentFragment();
          n.textContent.split(/(\s+)/).forEach((word) => {
            if (!word) return;
            if (/^\s+$/.test(word)) { frag.appendChild(d.createTextNode(' ')); return; }
            const w = d.createElement('span');
            w.className = 'sw';
            if (mode === 'chars') {
              Array.from(word).forEach((ch) => {
                const c = d.createElement('span');
                c.className = 'sc';
                c.style.setProperty('--i', i++);
                c.textContent = ch;
                w.appendChild(c);
              });
            } else {
              const c = d.createElement('span');
              c.className = 'sc';
              c.style.setProperty('--i', i++);
              c.textContent = word;
              w.appendChild(c);
            }
            frag.appendChild(w);
          });
          n.replaceWith(frag);
        } else if (n.nodeType === 1) walk(n);
      });
    };
    el.setAttribute('aria-label', el.textContent.replace(/\s+/g, ' ').trim());
    walk(el);
    $$('.sw', el).forEach((w) => w.setAttribute('aria-hidden', 'true'));
  });

  /* Révélations à l'entrée dans l'écran */
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
    });
  }, { rootMargin: '0px 0px -12% 0px' });
  $$('[data-reveal], [data-split]').forEach((el) => io.observe(el));
  $$('[data-stagger]').forEach((g) => Array.from(g.children).forEach((c, i) => { c.style.setProperty('--si', i); c.setAttribute('data-reveal', ''); io.observe(c); }));

  /* Progression au scroll : --p de 0 (entre par le bas) à 1 (sort par le haut),
     data-progress="sticky" : de 0 à 1 pendant que la section est épinglée */
  const prog = $$('[data-progress]');
  function measure() {
    DC.vh = innerHeight;
    prog.forEach((el) => {
      const r = el.getBoundingClientRect();
      el._top = r.top + scrollY;
      el._h = r.height;
    });
  }
  function onScroll() {
    DC.scrollY = scrollY;
    const max = root.scrollHeight - DC.vh;
    root.style.setProperty('--scroll', max > 0 ? (scrollY / max).toFixed(4) : 0);
    prog.forEach((el) => {
      let p;
      if (el.dataset.progress === 'sticky') p = (scrollY - el._top) / Math.max(1, el._h - DC.vh);
      else p = (scrollY + DC.vh - el._top) / (el._h + DC.vh);
      p = clamp(p, 0, 1);
      if (el._p !== p) { el._p = p; el.style.setProperty('--p', p.toFixed(4)); }
    });
    DC.scrollTasks.forEach((fn) => fn(scrollY));
  }
  DC.progressOf = (el) => el._p || 0;
  addEventListener('scroll', onScroll, { passive: true });
  addEventListener('resize', () => { measure(); onScroll(); });
  addEventListener('load', () => { measure(); onScroll(); });
  measure(); onScroll();

  /* Souris normalisée (-1..1), utilisée par les scènes 3D */
  addEventListener('pointermove', (e) => {
    DC.mouse.x = e.clientX; DC.mouse.y = e.clientY;
    DC.mouse.nx = (e.clientX / innerWidth) * 2 - 1;
    DC.mouse.ny = (e.clientY / innerHeight) * 2 - 1;
  }, { passive: true });

  /* Texte qui se brouille au survol */
  const glyphs = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ#%&*+=?';
  $$('[data-scramble]').forEach((el) => {
    const txt = el.textContent;
    let raf = 0;
    el.addEventListener('pointerenter', () => {
      if (reduce) return;
      cancelAnimationFrame(raf);
      const start = performance.now();
      const run = (t) => {
        const k = clamp((t - start) / 450, 0, 1);
        const n = Math.floor(k * txt.length);
        el.textContent = txt.slice(0, n) + Array.from(txt.slice(n)).map((c) => (c === ' ' ? ' ' : glyphs[(Math.random() * glyphs.length) | 0])).join('');
        if (k < 1) raf = requestAnimationFrame(run); else el.textContent = txt;
      };
      raf = requestAnimationFrame(run);
    });
  });

  /* Liens qui roulent (le texte est doublé en CSS via data-text) */
  $$('[data-roll]').forEach((el) => {
    const t = el.textContent.trim().replace(/"/g, '&quot;');
    el.innerHTML = '<span class="roll"><span data-text="' + t + '">' + el.innerHTML + '</span></span>';
  });

  /* Compteurs */
  const cio = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      cio.unobserve(e.target);
      const el = e.target;
      const to = parseFloat(el.dataset.count);
      const dec = (el.dataset.count.split('.')[1] || '').length;
      if (reduce) { el.textContent = to.toLocaleString('fr-FR', { minimumFractionDigits: dec }); return; }
      const t0 = performance.now();
      const step = (t) => {
        const k = clamp((t - t0) / 1600, 0, 1);
        const v = to * (1 - Math.pow(1 - k, 4));
        el.textContent = v.toLocaleString('fr-FR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
        if (k < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  }, { threshold: 0.6 });
  $$('[data-count]').forEach((el) => cio.observe(el));

  /* Barre de navigation : masquée en descendant, visible en remontant */
  const nav = $('[data-nav]');
  if (nav) {
    let prev = 0;
    DC.scrollTasks.add((y) => {
      nav.classList.toggle('is-solid', y > 40);
      nav.classList.toggle('is-hidden', y > prev && y > 400);
      prev = y;
    });
  }

  /* Menu mobile */
  const burger = $('[data-burger]');
  if (burger) burger.addEventListener('click', () => {
    const open = root.classList.toggle('menu-open');
    burger.setAttribute('aria-expanded', open);
  });
  $$('[data-menu] a').forEach((a) => a.addEventListener('click', () => { root.classList.remove('menu-open'); burger && burger.setAttribute('aria-expanded', false); }));

  if (!fine || reduce) return;

  /* Curseur personnalisé */
  const cur = d.createElement('div');
  cur.className = 'dc-cursor';
  cur.innerHTML = '<span class="dc-cursor__dot"></span><span class="dc-cursor__ring"><span class="dc-cursor__label"></span></span>';
  d.body.appendChild(cur);
  root.classList.add('has-cursor');
  const dot = $('.dc-cursor__dot', cur);
  const ring = $('.dc-cursor__ring', cur);
  const label = $('.dc-cursor__label', cur);
  let rx = 0, ry = 0, shown = false;
  addEventListener('pointermove', (e) => {
    if (e.pointerType !== 'mouse') return;
    if (!shown) { shown = true; rx = e.clientX; ry = e.clientY; cur.classList.add('is-on'); }
  }, { passive: true });
  root.addEventListener('mouseleave', () => { shown = false; cur.classList.remove('is-on'); });
  d.addEventListener('pointerover', (e) => {
    const field = e.target.closest('input, textarea, select');
    cur.classList.toggle('is-field', !!field);
    const t = e.target.closest('[data-cursor], a, button, label, [data-hover]');
    const lab = t && t.getAttribute('data-cursor');
    label.textContent = lab || '';
    cur.classList.toggle('is-label', !!lab);
    cur.classList.toggle('is-hover', !!t && !lab);
  });
  d.addEventListener('pointerdown', () => cur.classList.add('is-down'));
  d.addEventListener('pointerup', () => cur.classList.remove('is-down'));
  DC.tasks.add((t, dt) => {
    const k = 1 - Math.pow(1 - 0.2, dt);
    rx = lerp(rx, DC.mouse.x, k); ry = lerp(ry, DC.mouse.y, k);
    dot.style.transform = `translate3d(${DC.mouse.x}px,${DC.mouse.y}px,0)`;
    ring.style.transform = `translate3d(${rx}px,${ry}px,0)`;
  });

  /* Éléments aimantés */
  $$('[data-magnetic]').forEach((el) => {
    const s = parseFloat(el.dataset.magnetic) || 0.3;
    el.addEventListener('pointermove', (e) => {
      const r = el.getBoundingClientRect();
      el.style.setProperty('--mx', ((e.clientX - r.left - r.width / 2) * s).toFixed(1) + 'px');
      el.style.setProperty('--my', ((e.clientY - r.top - r.height / 2) * s).toFixed(1) + 'px');
      el.style.setProperty('--gx', (e.clientX - r.left).toFixed(0) + 'px');
      el.style.setProperty('--gy', (e.clientY - r.top).toFixed(0) + 'px');
    });
    el.addEventListener('pointerleave', () => { el.style.setProperty('--mx', '0px'); el.style.setProperty('--my', '0px'); });
  });

  /* Inclinaison 3D avec reflet */
  $$('[data-tilt]').forEach((el) => {
    const max = parseFloat(el.dataset.tilt) || 8;
    el.addEventListener('pointermove', (e) => {
      const r = el.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width;
      const y = (e.clientY - r.top) / r.height;
      el.style.setProperty('--ry', ((x - 0.5) * max).toFixed(2) + 'deg');
      el.style.setProperty('--rx', ((0.5 - y) * max).toFixed(2) + 'deg');
      el.style.setProperty('--gx', (x * 100).toFixed(1) + '%');
      el.style.setProperty('--gy', (y * 100).toFixed(1) + '%');
    });
    el.addEventListener('pointerleave', () => { el.style.setProperty('--ry', '0deg'); el.style.setProperty('--rx', '0deg'); });
  });

  /* Aperçu qui suit le curseur (data-preview="classe") */
  const pv = $('[data-follow]');
  if (pv) {
    let fx = 0, fy = 0, on = false;
    $$('[data-preview]').forEach((el) => {
      el.addEventListener('pointerenter', () => { on = true; pv.dataset.kind = el.dataset.preview; pv.classList.add('is-on'); });
      el.addEventListener('pointerleave', () => { on = false; pv.classList.remove('is-on'); });
    });
    DC.tasks.add((t, dt) => {
      const k = 1 - Math.pow(1 - 0.12, dt);
      const vx = DC.mouse.x - fx;
      fx = lerp(fx, DC.mouse.x, k); fy = lerp(fy, DC.mouse.y, k);
      pv.style.transform = `translate3d(${fx}px,${fy}px,0) rotate(${clamp(vx * 0.08, -14, 14).toFixed(2)}deg)`;
    });
  }
})();
