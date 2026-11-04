/* DYRO STUDIOS — animations et interactions (sans dépendance) */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

  function init() {
    splitWords();
    reveals();
    header();
    langMenu();
    mobileMenu();
    rooms();
    gallery();
    faq();
    counters();
    if (!reduce) {
      marquee();
      spotlight();
      magnetic();
      tilt();
      glow();
      cursor();
    }
    loop();
  }

  /* Titres mot par mot */
  function splitWords() {
    $$('[data-split]').forEach(el => {
      let i = 0;
      const walk = node => {
        [...node.childNodes].forEach(n => {
          if (n.nodeType === 3) {
            const parts = n.textContent.split(/(\s+)/);
            const frag = document.createDocumentFragment();
            parts.forEach(p => {
              if (!p) return;
              if (/^\s+$/.test(p)) { frag.appendChild(document.createTextNode(' ')); return; }
              const w = document.createElement('span'); w.className = 'w';
              const s = document.createElement('span'); s.textContent = p; s.style.setProperty('--i', i++);
              w.appendChild(s); frag.appendChild(w);
            });
            n.replaceWith(frag);
          } else if (n.nodeType === 1 && n.tagName !== 'BR') walk(n);
        });
      };
      el.setAttribute('aria-label', el.textContent.replace(/\s+/g, ' ').trim());
      walk(el);
      $$('.w', el).forEach(w => w.setAttribute('aria-hidden', 'true'));
    });
  }

  /* Apparitions */
  function reveals() {
    $$('[data-stagger]').forEach(g => {
      const base = parseFloat(getComputedStyle(g).getPropertyValue('--d')) || 0;
      $$(':scope > [data-reveal]', g).forEach((c, i) => c.style.setProperty('--d', (base + i * 0.08).toFixed(2) + 's'));
    });
    const els = $$('[data-reveal], [data-split]');
    if (reduce || !('IntersectionObserver' in window)) { els.forEach(e => e.classList.add('is-in')); return; }
    // Une fois apparu, l'élément retrouve ses propres effets (survol, inclinaison…)
    const settle = el => {
      if (!el.hasAttribute('data-reveal')) return;
      const d = (parseFloat(el.style.getPropertyValue('--d')) || 0) * 1000;
      setTimeout(() => { el.removeAttribute('data-reveal'); el.classList.remove('is-in'); }, d + 1400);
    };
    const io = new IntersectionObserver(entries => entries.forEach(e => {
      if (e.isIntersecting) { e.target.classList.add('is-in'); settle(e.target); io.unobserve(e.target); }
    }), { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    els.forEach(e => io.observe(e));
  }

  /* En-tête, progression, barre mobile, étapes, parallaxe */
  let lastY = 0;
  const st = { hdr: null, bar: null, mbar: null, steps: null, ticking: false };
  function header() {
    st.hdr = $('[data-hdr]'); st.bar = $('.progress span'); st.mbar = $('.mbar'); st.steps = $('[data-steps]');
    addEventListener('scroll', () => { if (!st.ticking) { st.ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
    onScroll();
    // lien actif
    const links = $$('.nav a[href*="#"]');
    const map = new Map();
    links.forEach(a => { const id = a.hash.slice(1); const s = id && document.getElementById(id); if (s) map.set(s, a); });
    if (map.size && 'IntersectionObserver' in window) {
      const io = new IntersectionObserver(es => es.forEach(e => {
        const a = map.get(e.target); if (!a) return;
        if (e.isIntersecting) { links.forEach(l => l.classList.remove('is-active')); a.classList.add('is-active'); }
      }), { rootMargin: '-45% 0px -50% 0px' });
      map.forEach((a, s) => io.observe(s));
    }
  }
  function onScroll() {
    st.ticking = false;
    const y = scrollY, h = document.documentElement.scrollHeight - innerHeight;
    if (st.bar) st.bar.style.transform = `scaleX(${h > 0 ? clamp(y / h, 0, 1) : 0})`;
    if (st.hdr) {
      st.hdr.classList.toggle('is-scrolled', y > 20);
      const menuOpen = document.body.classList.contains('menu-open');
      st.hdr.classList.toggle('is-hidden', !menuOpen && y > 400 && y > lastY + 4);
      if (y < lastY - 4 || y < 400) st.hdr.classList.remove('is-hidden');
    }
    if (st.mbar) st.mbar.classList.toggle('is-on', y > innerHeight * 0.6 && y < h - 300);
    if (st.steps) {
      const r = st.steps.getBoundingClientRect();
      const p = clamp((innerHeight * 0.75 - r.top) / (r.height + innerHeight * 0.2), 0, 1);
      st.steps.style.setProperty('--p', p.toFixed(3));
      const line = $('.steps__line span', st.steps); if (line) line.style.setProperty('--p', p.toFixed(3));
      $$('.step', st.steps).forEach((s, i, all) => s.classList.toggle('is-on', p >= (i + 0.5) / all.length));
    }
    lastY = y;
  }

  /* Langues */
  function langMenu() {
    const box = $('[data-lang]'); if (!box) return;
    const btn = $('.lang__btn', box);
    const set = open => { box.classList.toggle('is-open', open); btn.setAttribute('aria-expanded', open); };
    btn.addEventListener('click', e => { e.stopPropagation(); set(!box.classList.contains('is-open')); });
    document.addEventListener('click', e => { if (!box.contains(e.target)) set(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') set(false); });
  }

  /* Menu mobile */
  function mobileMenu() {
    const btn = $('.burger'), menu = $('#menu'); if (!btn || !menu) return;
    const set = open => {
      btn.setAttribute('aria-expanded', open);
      document.body.classList.toggle('menu-open', open);
      if (open) { menu.hidden = false; requestAnimationFrame(() => menu.classList.add('is-open')); }
      else { menu.classList.remove('is-open'); menu.hidden = true; }
      document.documentElement.style.overflow = open ? 'hidden' : '';
    };
    btn.addEventListener('click', () => set(btn.getAttribute('aria-expanded') !== 'true'));
    $$('a', menu).forEach(a => a.addEventListener('click', () => set(false)));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') { set(false); btn.focus(); } });
  }

  /* Comparateur avant / après
     Glisser (souris ou doigt) n'importe où sur l'image : la ligne suit exactement le pointeur.
     Un geste vertical fait défiler la page sans bouger le curseur ; un simple toucher place la ligne.
     Le champ « range » caché sert au clavier et aux lecteurs d'écran. */
  function slider(box) {
    if (!box || box._ba) return box && box._ba;
    const range = $('.ba__range', box);
    let touched = false, target = 50, raf = 0;
    let pid = null, active = false, decided = false, x0 = 0, y0 = 0, rect = null;
    const apply = () => {
      raf = 0;
      box.style.setProperty('--pos', target.toFixed(2) + '%');
      range.value = target;
      range.setAttribute('aria-valuetext', Math.round(target) + ' %');
    };
    const set = v => { target = clamp(v, 0, 100); if (!raf) raf = requestAnimationFrame(apply); };
    const at = x => ((x - rect.left) / rect.width) * 100;
    const stopIntro = () => { touched = true; box.classList.remove('is-intro'); };
    const grab = () => { decided = true; box.classList.add('is-drag'); try { box.setPointerCapture(pid); } catch (_) {} };
    const release = () => { active = false; decided = false; pid = null; box.classList.remove('is-drag'); };

    box.addEventListener('pointerdown', e => {
      if (e.button > 0 || pid !== null) return;
      stopIntro();
      pid = e.pointerId; active = true; decided = false; x0 = e.clientX; y0 = e.clientY;
      rect = box.getBoundingClientRect();
      if (e.pointerType === 'mouse') { e.preventDefault(); grab(); set(at(e.clientX)); }
    });
    box.addEventListener('pointermove', e => {
      if (!active || e.pointerId !== pid) return;
      if (!decided) {
        const dx = Math.abs(e.clientX - x0), dy = Math.abs(e.clientY - y0);
        if (dx < 5 && dy < 5) return;
        if (dy > dx) { release(); return; } // geste vertical : la page défile
        grab();
      }
      set(at(e.clientX));
    });
    box.addEventListener('pointerup', e => {
      if (e.pointerId !== pid) return;
      if (active && !decided) set(at(e.clientX)); // simple toucher : la ligne vient sous le doigt
      release();
    });
    box.addEventListener('pointercancel', e => { if (e.pointerId === pid) release(); });
    box.addEventListener('lostpointercapture', e => { if (e.target === box && e.pointerId === pid) release(); });
    box.addEventListener('dragstart', e => e.preventDefault());
    range.addEventListener('input', () => { stopIntro(); set(+range.value); });
    range.addEventListener('focus', stopIntro);

    const intro = () => {
      if (reduce || touched) return;
      box.classList.add('is-intro');
      const keys = [[0, 50], [700, 76], [1500, 26], [2300, 50]];
      const t0 = performance.now();
      const step = now => {
        if (touched) return;
        const t = now - t0;
        let i = 0; while (i < keys.length - 1 && t > keys[i + 1][0]) i++;
        if (i >= keys.length - 1) { set(50); box.classList.remove('is-intro'); return; }
        const [ta, va] = keys[i], [tb, vb] = keys[i + 1];
        const k = clamp((t - ta) / (tb - ta), 0, 1), ease = k < .5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2;
        set(va + (vb - va) * ease);
        requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    };
    box._ba = { set, intro, reset: () => { touched = false; set(50); } };
    return box._ba;
  }

  const src = (spec, w) => {
    const [name, sizes] = spec.split(':');
    const ws = sizes.split(',').map(Number);
    return {
      src: `/assets/img/${name}-${ws[ws.length - 1]}.webp`,
      srcset: ws.map(x => `/assets/img/${name}-${x}.webp ${x}w`).join(', '),
    };
  };

  function rooms() {
    const box = $('[data-ba]'); if (!box) return;
    const ba = slider(box);
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(es => { if (es[0].isIntersecting) { setTimeout(ba.intro, 900); io.disconnect(); } }, { threshold: 0.5 });
      io.observe(box);
    }
    const btns = $$('.rooms__btn');
    const after = $('.ba__after', box), before = $('.ba__before img', box);
    const title = $('[data-ba-title]'), note = $('[data-ba-note]');
    const L = { now: box.dataset.lNow, staging: box.dataset.lStaging, reno: box.dataset.lReno };
    const show = btn => {
      if (btn.getAttribute('aria-pressed') === 'true') return;
      btns.forEach(b => b.setAttribute('aria-pressed', b === btn));
      const a = src(btn.dataset.a), b = src(btn.dataset.b);
      const kind = L[btn.dataset.kind];
      const pre = [a, b].map(x => new Promise(res => { const i = new Image(); i.onload = i.onerror = res; i.srcset = x.srcset; i.sizes = after.sizes; i.src = x.src; }));
      box.classList.add('is-swapping');
      Promise.all([...pre, new Promise(r => setTimeout(r, 260))]).then(() => {
        after.srcset = a.srcset; after.src = a.src; after.alt = `${btn.dataset.t} — ${kind}`;
        before.srcset = b.srcset; before.src = b.src; before.alt = `${btn.dataset.t} — ${L.now}`;
        if (title) title.textContent = btn.dataset.t;
        if (note) note.textContent = kind;
        requestAnimationFrame(() => box.classList.remove('is-swapping'));
        ba.reset(); setTimeout(ba.intro, 150);
      });
    };
    btns.forEach(b => b.addEventListener('click', () => show(b)));
  }

  /* Galerie : filtres, survol, visionneuse */
  function gallery() {
    const gal = $('[data-gal]'); if (!gal) return;
    const cards = $$('.gal__card', gal);
    $$('[data-filter]').forEach(f => f.addEventListener('click', () => {
      $$('[data-filter]').forEach(x => x.setAttribute('aria-pressed', x === f));
      const k = f.dataset.filter;
      cards.forEach(c => {
        const show = k === 'all' || c.dataset.kind === k;
        if (show && c.classList.contains('is-out')) { c.classList.remove('is-out'); c.animate?.([{ opacity: 0, transform: 'scale(.96)' }, { opacity: 1, transform: 'none' }], { duration: 450, easing: 'cubic-bezier(.22,1,.36,1)' }); }
        else if (!show) c.classList.add('is-out');
      });
    }));
    // Sur écran tactile : l'image « après » se dévoile au défilement
    if (!fine && 'IntersectionObserver' in window) {
      const io = new IntersectionObserver(es => es.forEach(e => e.target.classList.toggle('is-after', e.isIntersecting)), { rootMargin: '-35% 0px -35% 0px' });
      cards.filter(c => c.dataset.open).forEach(c => io.observe(c));
    }
    // Visionneuse
    const dlg = $('[data-lb]'); if (!dlg || !dlg.showModal) return;
    const box = $('[data-ba-lb]', dlg); const ba = slider(box);
    const hero = $('[data-ba]');
    const L = hero ? { now: hero.dataset.lNow, staging: hero.dataset.lStaging, reno: hero.dataset.lReno } : {};
    const order = cards.filter(c => c.dataset.open).map(c => c.dataset.open);
    let cur = 0;
    const open = room => {
      const btn = $(`.rooms__btn[data-room="${room}"]`); if (!btn) return;
      cur = order.indexOf(room);
      const a = src(btn.dataset.a), b = src(btn.dataset.b);
      const after = $('.ba__after', box), before = $('.ba__before img', box);
      after.sizes = before.sizes = '(min-width: 900px) 620px, 94vw';
      after.srcset = a.srcset; after.src = a.src; after.alt = `${btn.dataset.t} — ${L[btn.dataset.kind]}`;
      before.srcset = b.srcset; before.src = b.src; before.alt = `${btn.dataset.t} — ${L.now}`;
      $('[data-lb-title]', dlg).textContent = btn.dataset.t;
      $('[data-lb-note]', dlg).textContent = btn.dataset.n;
      $('[data-lb-kind]', dlg).textContent = $(`.gal__card[data-open="${room}"] .gal__kind`)?.textContent || '';
      $('[data-lb-legal]', dlg).textContent = L[btn.dataset.kind];
      ba.reset();
      if (!dlg.open) { dlg.showModal(); document.documentElement.style.overflow = 'hidden'; }
      setTimeout(ba.intro, 350);
    };
    cards.forEach(c => c.dataset.open && c.addEventListener('click', () => open(c.dataset.open)));
    $('[data-lb-prev]', dlg).addEventListener('click', () => open(order[(cur - 1 + order.length) % order.length]));
    $('[data-lb-next]', dlg).addEventListener('click', () => open(order[(cur + 1) % order.length]));
    $('[data-lb-close]', dlg).addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => { document.documentElement.style.overflow = ''; });
    dlg.addEventListener('keydown', e => {
      if (e.target.classList.contains('ba__range')) return;
      if (e.key === 'ArrowRight') $('[data-lb-next]', dlg).click();
      if (e.key === 'ArrowLeft') $('[data-lb-prev]', dlg).click();
    });
  }

  /* FAQ : ouverture animée */
  function faq() {
    $$('.faq__list details').forEach(d => {
      const sum = $('summary', d), body = $('.faq__a', d);
      sum.addEventListener('click', e => {
        if (reduce || !body.animate) return;
        e.preventDefault();
        if (d.open) {
          const h = body.offsetHeight;
          body.animate([{ height: h + 'px', opacity: 1 }, { height: '0px', opacity: 0 }], { duration: 320, easing: 'cubic-bezier(.22,1,.36,1)' }).onfinish = () => { d.open = false; };
        } else {
          d.open = true;
          const h = body.offsetHeight;
          body.animate([{ height: '0px', opacity: 0 }, { height: h + 'px', opacity: 1 }], { duration: 420, easing: 'cubic-bezier(.22,1,.36,1)' });
        }
      });
    });
  }

  /* Compteurs de prix */
  function counters() {
    const els = $$('[data-count]'); if (!els.length || reduce || !('IntersectionObserver' in window)) return;
    const io = new IntersectionObserver(es => es.forEach(e => {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      const el = e.target, to = +el.dataset.count, t0 = performance.now(), d = 1100;
      const step = now => { const k = clamp((now - t0) / d, 0, 1), v = Math.round(to * (1 - Math.pow(1 - k, 3))); el.textContent = v; if (k < 1) requestAnimationFrame(step); };
      el.textContent = '0'; requestAnimationFrame(step);
    }), { threshold: 0.6 });
    els.forEach(e => io.observe(e));
  }

  /* Bandeau défilant, accéléré par le défilement */
  const mq = { el: null, x: 0, w: 0, v: 0, lastY: 0 };
  function marquee() {
    mq.el = $('[data-marquee]'); if (!mq.el) return;
    mq.el.innerHTML += mq.el.innerHTML;
    const measure = () => { mq.w = mq.el.scrollWidth / 2; };
    measure(); addEventListener('resize', measure);
    mq.lastY = scrollY;
  }

  /* Halo qui suit la souris sur les cartes */
  function spotlight() {
    if (!fine) return;
    document.addEventListener('pointermove', e => {
      const c = e.target.closest?.('[data-spot]'); if (!c) return;
      const r = c.getBoundingClientRect();
      c.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      c.style.setProperty('--my', (e.clientY - r.top) + 'px');
    }, { passive: true });
  }

  /* Boutons magnétiques */
  function magnetic() {
    if (!fine) return;
    $$('[data-magnetic]').forEach(b => {
      b.addEventListener('pointermove', e => {
        const r = b.getBoundingClientRect();
        b.style.setProperty('--bx', ((e.clientX - r.left - r.width / 2) * 0.22).toFixed(1) + 'px');
        b.style.setProperty('--by', ((e.clientY - r.top - r.height / 2) * 0.3).toFixed(1) + 'px');
      });
      b.addEventListener('pointerleave', () => { b.style.setProperty('--bx', '0px'); b.style.setProperty('--by', '0px'); });
    });
  }

  /* Inclinaison 3D */
  function tilt() {
    if (!fine) return;
    $$('[data-tilt]').forEach(c => {
      c.addEventListener('pointermove', e => {
        const r = c.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
        c.style.setProperty('--ry', (x * 7).toFixed(2) + 'deg');
        c.style.setProperty('--rx', (-y * 6).toFixed(2) + 'deg');
      });
      c.addEventListener('pointerleave', () => { c.style.setProperty('--rx', '0deg'); c.style.setProperty('--ry', '0deg'); });
    });
  }

  /* Lueur qui suit la souris (bandeau essai) */
  function glow() {
    if (!fine) return;
    $$('[data-glow]').forEach(s => s.addEventListener('pointermove', e => {
      const r = s.getBoundingClientRect();
      s.style.setProperty('--gx', ((e.clientX - r.left) / r.width * 100).toFixed(1) + '%');
      s.style.setProperty('--gy', ((e.clientY - r.top) / r.height * 100).toFixed(1) + '%');
    }, { passive: true }));
  }

  /* Curseur */
  const cur = { on: false, x: -100, y: -100, rx: -100, ry: -100, dot: null, ring: null, el: null, label: null };
  function cursor() {
    if (!fine) return;
    cur.el = $('.cursor'); if (!cur.el) return;
    document.documentElement.classList.add('has-cursor');
    cur.dot = $('.cursor__dot', cur.el); cur.ring = $('.cursor__ring', cur.el); cur.label = $('em', cur.ring);
    cur.on = true;
    document.addEventListener('pointermove', e => {
      cur.x = e.clientX; cur.y = e.clientY;
      const t = e.target.closest?.('[data-cursor]');
      const link = e.target.closest?.('a, button, summary, label, select, input:not([type=range])');
      if (t) { cur.label.textContent = t.dataset.cursor; cur.el.classList.add('is-label'); cur.el.classList.remove('is-link'); }
      else { cur.el.classList.remove('is-label'); cur.el.classList.toggle('is-link', !!link); }
    }, { passive: true });
    document.addEventListener('pointerleave', () => { cur.x = cur.y = -100; });
  }

  /* Boucle d'animation unique */
  function loop() {
    const parallax = reduce ? [] : $$('[data-parallax]');
    const frame = () => {
      if (mq.el && mq.w) {
        const dy = Math.abs(scrollY - mq.lastY); mq.lastY = scrollY;
        mq.v += (Math.min(dy, 60) * 0.12 - mq.v) * 0.08;
        mq.x -= 0.6 + mq.v;
        if (-mq.x >= mq.w) mq.x += mq.w;
        mq.el.style.transform = `translate3d(${mq.x.toFixed(2)}px,0,0)`;
      }
      if (cur.on) {
        cur.rx += (cur.x - cur.rx) * 0.18; cur.ry += (cur.y - cur.ry) * 0.18;
        cur.dot.style.transform = `translate3d(${cur.x}px,${cur.y}px,0)`;
        cur.ring.style.transform = `translate3d(${cur.rx.toFixed(1)}px,${cur.ry.toFixed(1)}px,0)`;
      }
      parallax.forEach(p => {
        const r = p.getBoundingClientRect();
        if (r.bottom < 0 || r.top > innerHeight) return;
        const k = (r.top + r.height / 2 - innerHeight / 2) / innerHeight;
        p.style.transform = `translate3d(0,${(k * -40).toFixed(1)}px,0)`;
      });
      requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

/* Mesure d'audience maison, sans cookies (voir api/_stats.php).
   Pour ne pas compter ses propres visites : ouvrir une fois le site avec ?notrack=1 (?notrack=0 pour annuler). */
(() => {
  'use strict';
  const off = () => { window.dyroTrack = () => {}; };
  try {
    if (/[?&]notrack=1\b/.test(location.search)) localStorage.setItem('dyro_notrack', '1');
    if (/[?&]notrack=0\b/.test(location.search)) localStorage.removeItem('dyro_notrack');
    if (localStorage.getItem('dyro_notrack')) return off();
  } catch (e) { /* stockage indisponible : on mesure quand même */ }
  if (navigator.webdriver || (location.protocol !== 'https:' && location.hostname !== 'localhost')) return off();

  const send = (o) => {
    o.p = location.pathname;
    const body = JSON.stringify(o);
    try { if (navigator.sendBeacon && navigator.sendBeacon('/api/v.php', body)) return; } catch (e) { /* repli */ }
    try { fetch('/api/v.php', { method: 'POST', body, keepalive: true, credentials: 'omit' }); } catch (e) { /* ignoré */ }
  };
  const track = window.dyroTrack = (n, m) => send({ t: 'ev', n, m: m ? String(m) : '' });

  const pageview = () => {
    const u = new URLSearchParams(location.search);
    send({
      t: 'pv',
      r: document.referrer,
      w: screen.width || innerWidth,
      l: (document.documentElement.lang || '').slice(0, 2),
      us: u.get('utm_source') || u.get('ref') || '',
      um: u.get('utm_medium') || '',
      uc: u.get('utm_campaign') || '',
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(pageview, 0));
  else setTimeout(pageview, 0);

  // Clics qui comptent : prise de contact, réseaux, autre marque
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    const h = a.href || '';
    let n = a.dataset.ev || '';
    if (!n) {
      if (/^https:\/\/(wa\.me|api\.whatsapp\.com)\//.test(h)) n = 'whatsapp';
      else if (h.startsWith('tel:')) n = 'tel';
      else if (h.startsWith('mailto:')) n = 'email';
      else if (/instagram\.com\//.test(h)) n = 'instagram';
      else if (/linkedin\.com\//.test(h)) n = 'linkedin';
      else if (/^https:\/\/(www\.)?(dyroweb\.online|dyrostudios\.com)/.test(h) && a.hostname !== location.hostname) n = 'sister';
    }
    if (n) track(n, a.dataset.evm || '');
  }, { capture: true, passive: true });
})();
