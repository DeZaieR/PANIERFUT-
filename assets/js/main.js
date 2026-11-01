/* DYRO WEB — animations (vanilla JS, aucune dépendance) */
(() => {
  'use strict';

  const d = document;
  const root = d.documentElement;
  const $ = (s, c = d) => c.querySelector(s);
  const $$ = (s, c = d) => Array.from(c.querySelectorAll(s));
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const lerp = (a, b, t) => a + (b - a) * t;
  const easeOutExpo = (t) => (t >= 1 ? 1 : 1 - Math.pow(2, -10 * t));
  const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  /* ------------------------------------------------------------------
     Boucle d'animation unique
  ------------------------------------------------------------------ */
  const tasks = new Set();
  let sy = window.scrollY;
  let lastY = sy;
  let vel = 0;
  let vw = root.clientWidth;
  let vh = window.innerHeight;
  let lastT = performance.now();

  function frame(t) {
    const dt = Math.min(48, t - lastT) / 16.667;
    lastT = t;
    sy = window.scrollY;
    vel = lerp(vel, sy - lastY, 0.2);
    lastY = sy;
    tasks.forEach((fn) => fn(t, dt));
    requestAnimationFrame(frame);
  }

  // N'exécute fn que lorsque el est (presque) visible
  function whenVisible(el, fn, margin = '120px') {
    if (!el) return;
    let on = false;
    new IntersectionObserver(([e]) => {
      if (e.isIntersecting && !on) { tasks.add(fn); on = true; }
      else if (!e.isIntersecting && on) { tasks.delete(fn); on = false; }
    }, { rootMargin: margin }).observe(el);
  }

  const onResize = [];
  let rT;
  addEventListener('resize', () => {
    clearTimeout(rT);
    rT = setTimeout(() => {
      vw = root.clientWidth;
      vh = window.innerHeight;
      onResize.forEach((fn) => fn());
    }, 120);
  }, { passive: true });

  /* ------------------------------------------------------------------
     Découpage du texte
  ------------------------------------------------------------------ */
  function splitWords(el) {
    let i = 0;
    const frag = d.createDocumentFragment();
    const wrap = (node) => {
      const w = d.createElement('span');
      w.className = 'w';
      const inner = d.createElement('span');
      inner.className = 'w__in';
      inner.style.setProperty('--i', i++);
      inner.appendChild(node);
      w.appendChild(inner);
      return w;
    };
    Array.from(el.childNodes).forEach((n) => {
      if (n.nodeType === 3) {
        n.textContent.split(/([ \t\n\r]+)/).forEach((part) => {
          if (!part) return;
          if (/^[ \t\n\r]+$/.test(part)) frag.appendChild(d.createTextNode(' '));
          else frag.appendChild(wrap(d.createTextNode(part)));
        });
      } else {
        frag.appendChild(wrap(n.cloneNode(true)));
      }
    });
    el.textContent = '';
    el.appendChild(frag);
  }

  // Mots individuels (texte révélé au scroll), em conservés
  function splitScrub(el) {
    const words = [];
    const walk = (node, isEm) => {
      Array.from(node.childNodes).forEach((n) => {
        if (n.nodeType === 3) {
          const frag = d.createDocumentFragment();
          n.textContent.split(/([ \t\n\r]+)/).forEach((part) => {
            if (!part) return;
            if (/^[ \t\n\r]+$/.test(part)) { frag.appendChild(d.createTextNode(' ')); return; }
            const s = d.createElement('span');
            s.className = isEm ? 'sw em' : 'sw';
            s.textContent = part;
            words.push(s);
            frag.appendChild(s);
          });
          n.replaceWith(frag);
        } else if (n.nodeType === 1) {
          walk(n, isEm || n.tagName === 'EM');
        }
      });
    };
    walk(el, false);
    return words;
  }

  // Effet « roulement » : lettres doublées qui glissent au survol
  function rollText(el) {
    const text = el.textContent.trim();
    el.setAttribute('aria-label', text);
    const row = (cls) => {
      const r = d.createElement('span');
      r.className = 'roll__row ' + cls;
      Array.from(text).forEach((ch, i) => {
        const c = d.createElement('span');
        c.className = 'roll__c';
        c.style.setProperty('--i', i);
        c.textContent = ch;
        r.appendChild(c);
      });
      return r;
    };
    const wrap = d.createElement('span');
    wrap.className = 'roll';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.append(row('roll__row--a'), row('roll__row--b'));
    el.textContent = '';
    el.appendChild(wrap);
  }

  $$('[data-split-hero]').forEach(splitWords);
  $$('[data-split]').forEach(splitWords);
  $$('[data-roll]').forEach(rollText);

  /* ------------------------------------------------------------------
     Préchargement → intro du hero
  ------------------------------------------------------------------ */
  const readyFns = [];
  let isReady = false;
  const onReady = (fn) => { if (isReady) fn(); else readyFns.push(fn); };
  function setReady() {
    if (isReady) return;
    isReady = true;
    root.classList.remove('is-loading');
    root.classList.add('is-ready');
    readyFns.splice(0).forEach((f) => f());
  }

  (function loader() {
    const el = $('.loader');
    if (!el || reduce) { if (el) el.remove(); setReady(); return; }
    const num = $('[data-load-count]', el);
    const t0 = performance.now();
    const minDur = 1250;
    let fontsOk = false;
    const fontsReady = d.fonts && d.fonts.ready ? d.fonts.ready : Promise.resolve();
    Promise.race([fontsReady, sleep(2200)]).then(() => { fontsOk = true; });
    const step = (t) => {
      const p = clamp((t - t0) / minDur, 0, 1);
      const shown = fontsOk ? p : Math.min(p, 0.9);
      num.textContent = String(Math.round(easeOutCubic(shown) * 100)).padStart(3, '0');
      if (shown < 1) { requestAnimationFrame(step); return; }
      el.classList.add('is-done');
      setTimeout(setReady, 180);
      setTimeout(() => el.remove(), 1300);
    };
    requestAnimationFrame(step);
  })();

  /* ------------------------------------------------------------------
     Révélations au scroll
  ------------------------------------------------------------------ */
  $$('[data-stagger]').forEach((g) => {
    Array.from(g.children).forEach((c, i) => {
      c.style.setProperty('--i', i);
      if (!c.hasAttribute('data-reveal')) c.setAttribute('data-reveal', '');
    });
  });

  const revealIO = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      const el = e.target;
      el.classList.add('is-in');
      revealIO.unobserve(el);
      const i = parseFloat(getComputedStyle(el).getPropertyValue('--i')) || 0;
      setTimeout(() => el.classList.add('is-settled'), 1200 + i * 85);
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.1 });
  $$('[data-reveal], [data-split]').forEach((el) => revealIO.observe(el));

  /* ------------------------------------------------------------------
     En-tête, progression, bouton devis
  ------------------------------------------------------------------ */
  const header = $('[data-header]');
  const progress = $('.progress i');
  let docH = root.scrollHeight - vh;
  let menuOpen = false;
  let headerHidden = false;
  let scrolled = null;
  const measureDoc = () => { docH = Math.max(1, root.scrollHeight - vh); };
  onResize.push(measureDoc);
  addEventListener('load', measureDoc);

  tasks.add(() => {
    const isScrolled = sy > 8;
    if (isScrolled !== scrolled) { scrolled = isScrolled; root.classList.toggle('is-scrolled', isScrolled); }
    if (Math.abs(vel) > 0.6) {
      const hide = vel > 0 && sy > 320 && !menuOpen;
      if (hide !== headerHidden) { headerHidden = hide; header.classList.toggle('is-hidden', hide); }
    }
    progress.style.transform = `scaleX(${clamp(sy / docH, 0, 1)})`;
  });

  const heroActions = $('[data-hero-actions]');
  if (heroActions) {
    new IntersectionObserver(([e]) => {
      root.classList.toggle('is-past-hero', !e.isIntersecting && e.boundingClientRect.top < 0);
    }).observe(heroActions);
  }

  /* Bandeau */
  const banner = $('[data-banner]');
  try { if (localStorage.getItem('dyro-banner') === '0' && banner) banner.classList.add('is-closed'); } catch (e) { /* stockage indisponible */ }
  const bannerClose = $('[data-banner-close]');
  if (bannerClose) bannerClose.addEventListener('click', () => {
    banner.classList.add('is-closed');
    try { localStorage.setItem('dyro-banner', '0'); } catch (e) { /* stockage indisponible */ }
    setTimeout(measureDoc, 700);
  });

  /* Menu mobile */
  const burger = $('[data-burger]');
  const menu = $('#menu');
  function setMenu(open) {
    menuOpen = open;
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
    if (open) {
      menu.hidden = false;
      requestAnimationFrame(() => requestAnimationFrame(() => root.classList.add('menu-open')));
      d.body.style.overflow = 'hidden';
    } else {
      root.classList.remove('menu-open');
      d.body.style.overflow = '';
      setTimeout(() => { if (!menuOpen) menu.hidden = true; }, 800);
    }
  }
  if (burger && menu) {
    burger.addEventListener('click', () => setMenu(!menuOpen));
    $$('a', menu).forEach((a) => a.addEventListener('click', () => setMenu(false)));
    d.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menuOpen) setMenu(false); });
  }

  /* Pastille de navigation + section active */
  const nav = $('.nav');
  const pill = $('.nav__pill');
  const navLinks = $$('.nav__link');
  let activeLink = null;
  function movePill(link) {
    if (!link) { pill.style.opacity = '0'; return; }
    pill.style.opacity = '1';
    pill.style.width = link.offsetWidth + 'px';
    pill.style.transform = `translateX(${link.offsetLeft}px)`;
  }
  if (nav) {
    navLinks.forEach((l) => l.addEventListener('pointerenter', () => movePill(l)));
    nav.addEventListener('pointerleave', () => movePill(activeLink));
    const sections = navLinks.map((l) => $(l.getAttribute('href'))).filter(Boolean);
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        const link = navLinks.find((l) => l.getAttribute('href') === '#' + e.target.id);
        navLinks.forEach((l) => l.classList.toggle('is-active', l === link));
        activeLink = link;
        movePill(link);
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach((s) => io.observe(s));
    new IntersectionObserver(([e]) => {
      if (e.isIntersecting) { activeLink = null; navLinks.forEach((l) => l.classList.remove('is-active')); movePill(null); }
    }, { rootMargin: '0px 0px -60% 0px' }).observe($('.hero'));
  }

  /* ------------------------------------------------------------------
     Curseur, aimantation, projecteur, inclinaison
  ------------------------------------------------------------------ */
  let px = vw / 2;
  let py = vh / 2;
  if (fine && !reduce) {
    const cur = $('.cursor');
    const dot = $('.cursor__dot', cur);
    const ring = $('.cursor__ring', cur);
    const label = $('.cursor__label', cur);
    let rx = px;
    let ry = py;
    let shown = false;
    root.classList.add('has-cursor');
    addEventListener('pointermove', (e) => {
      if (e.pointerType !== 'mouse') return;
      px = e.clientX; py = e.clientY;
      if (!shown) { shown = true; rx = px; ry = py; cur.classList.add('is-on'); }
    }, { passive: true });
    root.addEventListener('mouseleave', () => { shown = false; cur.classList.remove('is-on'); });
    d.addEventListener('pointerover', (e) => {
      const t = e.target.closest('[data-cursor], a, button');
      if (!t) { cur.classList.remove('is-hover', 'is-label'); return; }
      const lab = t.getAttribute('data-cursor');
      label.textContent = lab || '';
      cur.classList.toggle('is-label', !!lab);
      cur.classList.toggle('is-hover', !lab);
    });
    d.addEventListener('pointerdown', () => cur.classList.add('is-down'));
    d.addEventListener('pointerup', () => cur.classList.remove('is-down'));
    tasks.add((t, dt) => {
      const k = 1 - Math.pow(1 - 0.22, dt);
      rx = lerp(rx, px, k); ry = lerp(ry, py, k);
      dot.style.transform = `translate3d(${px}px,${py}px,0)`;
      ring.style.transform = `translate3d(${rx}px,${ry}px,0)`;
    });

    // Boutons aimantés
    $$('[data-magnetic]').forEach((el) => {
      const s = parseFloat(el.dataset.magnetic) || 0.35;
      let r = null;
      el.addEventListener('pointerenter', () => { r = el.getBoundingClientRect(); });
      el.addEventListener('pointermove', (e) => {
        if (!r) r = el.getBoundingClientRect();
        el.style.setProperty('--tx', ((e.clientX - (r.left + r.width / 2)) * s).toFixed(1) + 'px');
        el.style.setProperty('--ty', ((e.clientY - (r.top + r.height / 2)) * s).toFixed(1) + 'px');
      });
      el.addEventListener('pointerleave', () => { r = null; el.style.setProperty('--tx', '0px'); el.style.setProperty('--ty', '0px'); });
    });

    // Inclinaison 3D des cartes
    $$('[data-tilt]').forEach((el) => {
      const max = parseFloat(el.dataset.tilt) || 6;
      el.addEventListener('pointermove', (e) => {
        const r = el.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - 0.5;
        const y = (e.clientY - r.top) / r.height - 0.5;
        el.style.setProperty('--ry', (x * max).toFixed(2) + 'deg');
        el.style.setProperty('--rx', (-y * max).toFixed(2) + 'deg');
      });
      el.addEventListener('pointerleave', () => { el.style.setProperty('--ry', '0deg'); el.style.setProperty('--rx', '0deg'); });
    });
  }

  // Projecteur partagé : la lumière suit la souris sur toutes les cartes d'une grille
  $$('[data-spotlight-group]').forEach((g) => {
    const cards = $$('.card', g);
    g.addEventListener('pointermove', (e) => {
      cards.forEach((c) => {
        const r = c.getBoundingClientRect();
        c.style.setProperty('--mx', (e.clientX - r.left).toFixed(0) + 'px');
        c.style.setProperty('--my', (e.clientY - r.top).toFixed(0) + 'px');
      });
    });
  });

  /* ------------------------------------------------------------------
     HERO : scène 3D, anneau, traînées lumineuses
  ------------------------------------------------------------------ */
  const hero = $('.hero');
  const scene = $('[data-scene]');
  const heroRing = $('[data-hero-ring]');
  let hx = 0, hy = 0, cx = 0, cy = 0;
  if (hero && scene && !reduce) {
    if (fine) {
      hero.addEventListener('pointermove', (e) => {
        const r = hero.getBoundingClientRect();
        hx = (e.clientX - r.left) / r.width - 0.5;
        hy = (e.clientY - r.top) / r.height - 0.5;
      });
      hero.addEventListener('pointerleave', () => { hx = 0; hy = 0; });
    }
    whenVisible(hero, (t, dt) => {
      const k = 1 - Math.pow(1 - 0.06, dt);
      const tx = fine ? hx : Math.sin(t / 2600) * 0.35;
      const ty = fine ? hy : Math.cos(t / 3100) * 0.25;
      cx = lerp(cx, tx, k); cy = lerp(cy, ty, k);
      const sp = clamp(sy / vh, 0, 1.2);
      scene.style.transform = `translate3d(0, ${(-sp * 50).toFixed(1)}px, 0) rotateY(${(cx * 16 - 8).toFixed(2)}deg) rotateX(${(-cy * 12 + 5 + sp * 10).toFixed(2)}deg)`;
      if (heroRing) heroRing.style.transform = `rotate(${(sp * 50 + cx * 6).toFixed(2)}deg) scale(${(1 + sp * 0.18).toFixed(3)})`;
    }, '0px');
  }

  // Traînées lumineuses à l'angle du swoosh du logo
  (function streaks() {
    const cv = $('[data-streaks]');
    if (!cv || reduce) return;
    const ctx = cv.getContext('2d');
    const dirX = Math.cos(0.5236);
    const dirY = -Math.sin(0.5236);
    let w = 0, h = 0, dpr = 1, list = [];
    const spawn = (init) => {
      const fromLeft = Math.random() < 0.35;
      return {
        x: init ? Math.random() * w : (fromLeft ? -50 : Math.random() * w * 0.95),
        y: init ? Math.random() * h : (fromLeft ? h * (0.3 + Math.random() * 0.8) : h + 40 + Math.random() * 160),
        len: 70 + Math.random() * 240,
        sp: 1.4 + Math.random() * 3.6,
        a: 0.12 + Math.random() * 0.5,
        lw: Math.random() < 0.2 ? 1.8 : 1,
      };
    };
    const resize = () => {
      dpr = Math.min(2, window.devicePixelRatio || 1);
      w = cv.clientWidth; h = cv.clientHeight;
      cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const n = w < 700 ? 9 : 18;
      list = Array.from({ length: n }, () => spawn(true));
    };
    resize();
    onResize.push(resize);
    whenVisible(hero, (t, dt) => {
      ctx.clearRect(0, 0, w, h);
      const boost = 1 + Math.min(5, Math.abs(vel) * 0.18);
      list.forEach((s, i) => {
        s.x += dirX * s.sp * boost * dt;
        s.y += dirY * s.sp * boost * dt;
        const tx = s.x - dirX * s.len;
        const ty = s.y - dirY * s.len;
        if (ty < -20 || tx > w + 20) { list[i] = spawn(false); return; }
        const g = ctx.createLinearGradient(tx, ty, s.x, s.y);
        g.addColorStop(0, 'rgba(0,96,237,0)');
        g.addColorStop(0.7, `rgba(0,118,255,${s.a * 0.6})`);
        g.addColorStop(1, `rgba(1,247,255,${s.a})`);
        ctx.strokeStyle = g;
        ctx.lineWidth = s.lw;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(tx, ty);
        ctx.lineTo(s.x, s.y);
        ctx.stroke();
      });
    }, '0px');
  })();

  /* ------------------------------------------------------------------
     HERO : maquette vivante (langues, clic, notification, code)
  ------------------------------------------------------------------ */
  const LANGS = {
    fr: { h: 'Votre activité, enfin visible en ligne.', cta: 'Prendre rendez-vous', t: 'Nouvelle demande reçue', s: 'via le formulaire de contact', url: 'votre-entreprise.lu' },
    de: { h: 'Ihr Unternehmen, endlich online sichtbar.', cta: 'Termin vereinbaren', t: 'Neue Anfrage erhalten', s: 'über das Kontaktformular', url: 'votre-entreprise.lu/de' },
    en: { h: 'Your business, finally visible online.', cta: 'Book an appointment', t: 'New enquiry received', s: 'via the contact form', url: 'votre-entreprise.lu/en' },
  };
  const mock = $('[data-mock]');
  const mockH = $('[data-mock-h]');
  const mockCta = $('[data-mock-cta]');
  const mockUrl = $('[data-mock-url]');
  const toast = $('[data-toast]');
  const toastT = $('[data-toast-t]');
  const toastS = $('[data-toast-s]');
  const fcursor = $('[data-fcursor]');
  const chip = $('.langchip');
  const chipPill = $('.langchip__pill');
  const chipBtns = $$('[data-lang]');
  let lang = 'fr';
  let heroOn = true;
  let userLockUntil = 0;

  if (hero) new IntersectionObserver(([e]) => { heroOn = e.isIntersecting; }).observe(hero);

  const GLYPHS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  function scramble(el, to, dur = 520) {
    if (reduce) { el.textContent = to; return; }
    const from = el.textContent;
    const len = Math.max(from.length, to.length);
    const t0 = performance.now();
    const tick = (t) => {
      const p = clamp((t - t0) / dur, 0, 1);
      let out = '';
      for (let i = 0; i < len; i++) {
        const settle = i / len;
        if (p > settle * 0.7 + 0.3) out += to[i] || '';
        else if (to[i] === ' ' || from[i] === ' ') out += ' ';
        else out += GLYPHS[(Math.random() * GLYPHS.length) | 0];
      }
      el.textContent = out;
      if (p < 1) requestAnimationFrame(tick); else el.textContent = to;
    };
    requestAnimationFrame(tick);
  }

  function placeChipPill() {
    const btn = chipBtns.find((b) => b.dataset.lang === lang);
    if (!btn || !chipPill) return;
    chipPill.style.width = btn.offsetWidth + 'px';
    chipPill.style.transform = `translateX(${btn.offsetLeft}px)`;
  }

  function setLang(l) {
    if (!LANGS[l] || l === lang) return;
    lang = l;
    const L = LANGS[l];
    chipBtns.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.lang === l)));
    placeChipPill();
    scramble(mockH, L.h, 600);
    scramble(mockCta, L.cta, 450);
    mockUrl.textContent = L.url;
    toastT.textContent = L.t;
    toastS.textContent = L.s;
  }

  chipBtns.forEach((b) => b.addEventListener('click', () => { userLockUntil = performance.now() + 9000; setLang(b.dataset.lang); }));
  placeChipPill();
  onResize.push(placeChipPill);
  if (d.fonts && d.fonts.ready) d.fonts.ready.then(placeChipPill);

  function offsetWithin(el, ancestor) {
    let x = 0, y = 0, n = el;
    while (n && n !== ancestor) { x += n.offsetLeft; y += n.offsetTop; n = n.offsetParent; }
    return { x, y };
  }
  function moveCursorTo(x, y) { fcursor.style.transform = `translate(${x}px, ${y}px)`; }

  async function mockLoop() {
    const order = ['fr', 'de', 'en'];
    await sleep(2600);
    for (;;) {
      if (!heroOn || d.hidden) { await sleep(500); continue; }
      const o = offsetWithin(mockCta, mock);
      moveCursorTo(o.x + mockCta.offsetWidth * 0.62, o.y + mockCta.offsetHeight * 0.55);
      await sleep(1150);
      mockCta.classList.remove('is-press'); void mockCta.offsetWidth; mockCta.classList.add('is-press');
      await sleep(220);
      mockCta.classList.remove('is-press');
      toast.classList.add('is-on');
      await sleep(400);
      moveCursorTo(mock.offsetWidth * (0.55 + Math.random() * 0.2), mock.offsetHeight * (0.72 + Math.random() * 0.12));
      await sleep(2200);
      toast.classList.remove('is-on');
      await sleep(700);
      if (performance.now() > userLockUntil) setLang(order[(order.indexOf(lang) + 1) % 3]);
      await sleep(1500);
    }
  }
  if (mock && !reduce) onReady(mockLoop);

  // Éditeur de code qui se tape tout seul
  const CODE = [
    ['p', '<'], ['t', 'section'], ['p', ' '], ['a', 'class'], ['p', '='], ['s', '"hero"'], ['p', '>\n  <'], ['t', 'h1'], ['p', '>'],
    ['x', 'Votre activité,'], ['p', '\n    '], ['x', 'enfin visible.'], ['p', '</'], ['t', 'h1'], ['p', '>\n  <'], ['t', 'a'], ['p', ' '],
    ['a', 'href'], ['p', '='], ['s', '"#contact"'], ['p', '>\n    '], ['x', 'Prendre rendez-vous'], ['p', '\n  </'], ['t', 'a'], ['p', '>\n</'], ['t', 'section'], ['p', '>'],
  ];
  const codeEl = $('[data-typing]');
  function renderFullCode() {
    codeEl.innerHTML = '';
    CODE.forEach(([c, txt]) => { const s = d.createElement('span'); s.className = 'tk-' + c; s.textContent = txt; codeEl.appendChild(s); });
  }
  async function typeLoop() {
    await sleep(1400);
    for (;;) {
      if (!heroOn || d.hidden) { await sleep(500); continue; }
      codeEl.innerHTML = '';
      for (const [c, txt] of CODE) {
        const s = d.createElement('span');
        s.className = 'tk-' + c;
        codeEl.appendChild(s);
        for (const ch of txt) {
          s.textContent += ch;
          await sleep(ch === '\n' ? 120 : 18 + Math.random() * 42);
        }
      }
      await sleep(3800);
      while (codeEl.lastChild) {
        const last = codeEl.lastChild;
        last.textContent = last.textContent.slice(0, -3);
        if (!last.textContent) codeEl.removeChild(last);
        await sleep(8);
      }
      await sleep(500);
    }
  }
  if (codeEl) {
    if (reduce) renderFullCode();
    else onReady(typeLoop);
  }

  /* ------------------------------------------------------------------
     Marquee piloté par la vitesse de scroll
  ------------------------------------------------------------------ */
  $$('[data-marquee]').forEach((row) => {
    const track = $('.marquee__track', row);
    const items = Array.from(track.children);
    items.forEach((n) => { const c = n.cloneNode(true); c.setAttribute('aria-hidden', 'true'); track.appendChild(c); });
    if (reduce) return;
    const dir = parseFloat(row.dataset.marquee) || 1;
    let x = 0;
    let half = track.scrollWidth / 2;
    let skew = 0;
    let hover = false;
    onResize.push(() => { half = track.scrollWidth / 2; });
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(() => { half = track.scrollWidth / 2; });
    row.addEventListener('pointerenter', () => { hover = true; });
    row.addEventListener('pointerleave', () => { hover = false; });
    whenVisible(row, (t, dt) => {
      const base = hover ? 0.35 : 1.1;
      const boost = Math.min(18, Math.abs(vel) * 0.9);
      const sign = vel < -0.5 ? -1 : 1;
      x -= (base + boost) * dir * sign * dt;
      if (x <= -half) x += half;
      if (x > 0) x -= half;
      skew = lerp(skew, clamp(-vel * 0.35, -10, 10), 0.12);
      track.style.transform = `translate3d(${x.toFixed(2)}px,0,0) skewX(${skew.toFixed(2)}deg)`;
    });
  });

  /* ------------------------------------------------------------------
     Manifeste : mots révélés au fil du scroll
  ------------------------------------------------------------------ */
  $$('[data-scrub]').forEach((el) => {
    const words = splitScrub(el);
    if (reduce) return;
    const n = words.length;
    whenVisible(el, () => {
      const r = el.getBoundingClientRect();
      const start = vh * 0.88;
      const end = vh * 0.32;
      const p = clamp((start - r.top) / (start - end + r.height * 0.6), 0, 1);
      for (let i = 0; i < n; i++) {
        const o = clamp(p * n * 1.15 - i, 0, 1);
        const w = words[i];
        if (w._o !== o) { w._o = o; w.style.setProperty('--o', o.toFixed(3)); }
      }
    });
  });

  /* ------------------------------------------------------------------
     Méthode : défilement horizontal épinglé (desktop)
  ------------------------------------------------------------------ */
  (function processScroll() {
    const proc = $('[data-process]');
    if (!proc) return;
    const track = $('[data-process-track]', proc);
    const bar = $('[data-process-bar]', proc);
    const steps = $$('.step', track).filter((s) => !s.classList.contains('step--end'));
    const mq = matchMedia('(min-width: 961px)');
    let horiz = false;
    let overflow = 0;
    let cur = 0;

    const layout = () => {
      horiz = mq.matches && !reduce;
      proc.classList.toggle('is-horizontal', horiz);
      if (horiz) {
        overflow = Math.max(0, track.scrollWidth - vw);
        proc.style.height = (vh + overflow * 1.25) + 'px';
      } else {
        proc.style.height = '';
        track.style.transform = '';
      }
      measureDoc();
    };
    layout();
    onResize.push(layout);
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(layout);

    // Mode vertical : chaque étape s'allume en entrant à l'écran
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => { if (!horiz && e.isIntersecting) e.target.classList.add('is-active'); });
    }, { rootMargin: '0px 0px -30% 0px' });
    steps.forEach((s) => io.observe(s));

    whenVisible(proc, (t, dt) => {
      if (!horiz) return;
      const r = proc.getBoundingClientRect();
      const dist = Math.max(1, r.height - vh);
      const p = clamp(-r.top / dist, 0, 1);
      cur = lerp(cur, p * overflow, 1 - Math.pow(1 - 0.14, dt));
      track.style.transform = `translate3d(${(-cur).toFixed(1)}px,0,0)`;
      bar.style.transform = `scaleX(${p.toFixed(4)})`;
      steps.forEach((s) => s.classList.toggle('is-active', s.offsetLeft - cur < vw * 0.62));
    }, '0px');
  })();

  /* ------------------------------------------------------------------
     Bento : langues, recherche locale
  ------------------------------------------------------------------ */
  (function langDemo() {
    const box = $('[data-langdemo]');
    if (!box) return;
    const h = $('[data-langdemo-h]', box);
    const p = $('[data-langdemo-p]', box);
    const pills = $$('.langdemo__pills span', box);
    const data = [['Bienvenue', 'Découvrez nos services'], ['Willkommen', 'Entdecken Sie unsere Leistungen'], ['Welcome', 'Discover our services']];
    let k = 0;
    let visible = false;
    new IntersectionObserver(([e]) => { visible = e.isIntersecting; }).observe(box);
    if (reduce) return;
    setInterval(() => {
      if (!visible || d.hidden) return;
      k = (k + 1) % 3;
      pills.forEach((s, i) => s.classList.toggle('is-on', i === k));
      scramble(h, data[k][0], 500);
      scramble(p, data[k][1], 650);
    }, 2400);
  })();

  (function serpDemo() {
    const box = $('[data-serp]');
    if (!box) return;
    const q = $('[data-serp-q]', box);
    const query = 'plombier luxembourg';
    if (reduce) { q.textContent = query; box.classList.add('is-shown'); return; }
    let running = false;
    const run = async () => {
      if (running) return;
      running = true;
      for (;;) {
        box.classList.remove('is-shown');
        q.textContent = '';
        await sleep(600);
        for (const ch of query) { q.textContent += ch; await sleep(70 + Math.random() * 60); }
        await sleep(350);
        box.classList.add('is-shown');
        await sleep(4200);
        if (d.hidden) await sleep(2000);
      }
    };
    new IntersectionObserver(([e], obs) => { if (e.isIntersecting) { run(); obs.disconnect(); } }, { threshold: 0.4 }).observe(box);
  })();

  /* ------------------------------------------------------------------
     Compteurs
  ------------------------------------------------------------------ */
  const fmt = new Intl.NumberFormat('fr-FR');
  const countIO = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      countIO.unobserve(e.target);
      const el = e.target;
      const to = parseFloat(el.dataset.countTo);
      if (reduce) { el.textContent = fmt.format(to); return; }
      const t0 = performance.now();
      const dur = 1200;
      const tick = (t) => {
        const p = clamp((t - t0) / dur, 0, 1);
        el.textContent = fmt.format(Math.round(easeOutExpo(p) * to));
        if (p < 1) requestAnimationFrame(tick);
      };
      el.textContent = '0';
      requestAnimationFrame(tick);
    });
  }, { threshold: 0.4 });
  $$('[data-count-to]').forEach((el) => countIO.observe(el));

  /* ------------------------------------------------------------------
     FAQ
  ------------------------------------------------------------------ */
  $$('.faq__item').forEach((item) => {
    const btn = $('.faq__q', item);
    btn.addEventListener('click', () => {
      const open = !item.classList.contains('is-open');
      $$('.faq__item.is-open').forEach((o) => { if (o !== item) { o.classList.remove('is-open'); $('.faq__q', o).setAttribute('aria-expanded', 'false'); } });
      item.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', String(open));
      setTimeout(measureDoc, 700);
    });
  });

  /* ------------------------------------------------------------------
     Copier email / téléphone
  ------------------------------------------------------------------ */
  $$('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const value = btn.dataset.copy;
      const done = () => {
        btn.textContent = 'Copié';
        btn.classList.add('is-done');
        setTimeout(() => { btn.textContent = 'Copier'; btn.classList.remove('is-done'); }, 1600);
      };
      const fallback = () => {
        const target = btn.parentElement.querySelector('.contact__val');
        const range = d.createRange();
        range.selectNodeContents(target);
        const sel = getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        btn.textContent = 'Sélectionné';
        setTimeout(() => { btn.textContent = 'Copier'; }, 1600);
      };
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, fallback);
        else fallback();
      } catch (e) { fallback(); }
    });
  });

  /* ------------------------------------------------------------------
     Contact : logo qui suit la souris
  ------------------------------------------------------------------ */
  (function ctaMark() {
    const sec = $('[data-cta]');
    const mark = $('[data-cta-mark]');
    if (!sec || !mark || reduce) return;
    let tx = 0, ty = 0, x = 0, y = 0;
    if (fine) {
      sec.addEventListener('pointermove', (e) => {
        const r = sec.getBoundingClientRect();
        tx = (e.clientX - r.left) / r.width - 0.5;
        ty = (e.clientY - r.top) / r.height - 0.5;
      });
      sec.addEventListener('pointerleave', () => { tx = 0; ty = 0; });
    }
    whenVisible(sec, (t, dt) => {
      const k = 1 - Math.pow(1 - 0.07, dt);
      const gx = fine ? tx : Math.sin(t / 2200) * 0.3;
      const gy = fine ? ty : Math.cos(t / 2700) * 0.2;
      x = lerp(x, gx, k); y = lerp(y, gy, k);
      mark.style.setProperty('--ry', (x * 30).toFixed(2) + 'deg');
      mark.style.setProperty('--rx', (-y * 24).toFixed(2) + 'deg');
    });
  })();

  /* ------------------------------------------------------------------
     Footer : texte géant ajusté + projecteur
  ------------------------------------------------------------------ */
  (function giant() {
    const g = $('[data-giant]');
    if (!g) return;
    const base = $('.giant__base', g);
    const fit = () => {
      g.style.fontSize = '100px';
      const cs = getComputedStyle(g);
      const avail = g.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
      const range = d.createRange();
      range.selectNodeContents(base);
      const wText = range.getBoundingClientRect().width || 1;
      g.style.fontSize = Math.max(32, Math.floor(100 * (avail / wText) * 0.98)) + 'px';
    };
    fit();
    onResize.push(fit);
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(fit);

    let gx = 50, gy = 50, tgx = 50, tgy = 50, gr = 0, tgr = 0;
    if (fine && !reduce) {
      g.addEventListener('pointermove', (e) => {
        const r = g.getBoundingClientRect();
        tgx = ((e.clientX - r.left) / r.width) * 100;
        tgy = ((e.clientY - r.top) / r.height) * 100;
        tgr = Math.max(180, r.height * 1.3);
      });
      g.addEventListener('pointerleave', () => { tgr = 0; });
    }
    whenVisible(g, (t, dt) => {
      if (!fine || reduce) {
        // Balayage automatique sur écran tactile
        const r = g.getBoundingClientRect();
        tgx = 50 + Math.sin(t / 1800) * 42;
        tgy = 55;
        tgr = r.height * 1.4;
      }
      const k = 1 - Math.pow(1 - 0.12, dt);
      gx = lerp(gx, tgx, k); gy = lerp(gy, tgy, k); gr = lerp(gr, tgr, 0.08 * dt);
      g.style.setProperty('--gx', gx.toFixed(2) + '%');
      g.style.setProperty('--gy', gy.toFixed(2) + '%');
      g.style.setProperty('--gr', gr.toFixed(1) + 'px');
    });
  })();

  requestAnimationFrame(frame);
})();
