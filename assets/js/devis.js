/* DYRO WEB — formulaire de demande de devis (page devis.html).
   Étapes, validation, estimation selon la grille de prix, envoi.
   Envoi : POST vers api/devis.php (hébergement PHP). Si le serveur ne répond pas,
   la demande est préparée dans un email et affichée pour être copiée. */
(() => {
  'use strict';

  const D = window.DYRO;
  const form = document.querySelector('[data-quote]');
  if (!D || !form) return;

  const d = document;
  const $ = (s, c = d) => c.querySelector(s);
  const $$ = (s, c = d) => Array.from(c.querySelectorAll(s));
  const tr = D.tr;
  D.addDefaults({
    'price.pre': '', 'price.cur': '€ HT', 'price.from': 'dès',
    'dv.err.type': 'Choisissez un type de projet.',
    'dv.err.lang': 'Choisissez au moins une langue.',
    'dv.err.name': 'Indiquez votre nom.',
    'dv.err.email': 'Indiquez une adresse email valide.',
    'dv.err.consent': 'Cochez la case pour que nous puissions traiter votre demande.',
    'dv.subject': 'Demande de devis',
    'dv.sum.empty': 'Choisissez un type de projet pour voir la fourchette.',
    'dv.sum.unsure': 'Nous vous conseillerons la formule adaptée à votre activité.',
    'dv.sum.extra': 'Langues supplémentaires : chiffrées dans le devis.',
    'dv.sending': 'Envoi…',
  });
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const plain = (s) => String(s).replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/&nbsp;| /g, ' ').trim();
  const TO = 'contact@dyroweb.online';
  const ENDPOINT = 'api/devis.php';
  const startedAt = Date.now();

  // Grille de prix (HT) — identique à la section Offres
  const PLANS = {
    landing: { min: 890, max: 1290, name: 'price.1.h', incl: ['fr'] },
    pro: { min: 1890, max: 2900, name: 'price.2.h', incl: ['fr', 'de'] },
    premium: { min: 3500, max: 6000, name: 'price.3.h', incl: ['fr', 'de', 'en', 'pt', 'lb'] },
    ecommerce: { min: 4500, max: null, name: 'price.x1.label', incl: ['fr'] },
    unsure: { name: 'dv.unsure' },
  };
  const LANG_LABEL = { fr: 'FR', de: 'DE', en: 'EN', pt: 'PT', lb: 'LU' };
  const FEATURES = { form: 'price.l.form', booking: 'price.3.l3', adv: 'price.3.l4', pay: 'dv.f.pay', seo: 'price.3.l2', blog: 'dv.f.blog' };
  const MAINT = { none: 'dv.m.none', ess: 'dv.m.ess', pro: 'dv.m.pro', gro: 'dv.m.gro' };
  const DEADLINE = { asap: 'dv.d.asap', soon: 'dv.d.soon', later: 'dv.d.later', none: 'dv.d.none' };

  const panels = $$('[data-step]', form);
  const inds = $$('[data-step-ind]', form);
  const nav = $('.quote__nav', form);
  const steps = $('.steps', form);
  const btnPrev = $('[data-prev]', form);
  const btnNext = $('[data-next]', form);
  const btnSend = $('[data-submit]', form);
  const donePanel = $('[data-done]', form);
  const fbPanel = $('[data-fallback]', form);
  const last = panels.length;
  let step = 1;
  let maxReached = 1;

  /* ---------- État visuel des cases ---------- */
  const syncChecks = () => {
    $$('input[type="radio"], input[type="checkbox"]', form).forEach((inp) => {
      const host = inp.closest('.choice, .pill-check, .check, .chip, .consent');
      if (host) host.classList.toggle('is-checked', inp.checked);
    });
  };

  /* ---------- Lecture du formulaire ---------- */
  const read = () => {
    const fd = new FormData(form);
    const val = (k) => String(fd.get(k) || '').trim();
    return {
      type: val('type'),
      langs: fd.getAll('langs').map(String),
      features: fd.getAll('features').map(String),
      maint: val('maint') || 'none',
      deadline: val('deadline'),
      site: val('site'),
      name: val('name'),
      company: val('company'),
      email: val('email'),
      phone: val('phone'),
      message: val('message'),
      consent: fd.get('consent') === 'on',
      website: val('website'),
    };
  };

  /* ---------- Estimation ---------- */
  const fmt = (n) => new Intl.NumberFormat(D.locale()).format(Math.round(n));
  const money = (n) => `${plain(tr('price.pre'))}${fmt(n)}`;
  const rangeText = (min, max) => (max == null
    ? `${plain(tr('price.from'))} ${money(min)} ${plain(tr('price.cur'))}`
    : `${money(min)} – ${money(max)} ${plain(tr('price.cur'))}`);

  const sumRange = $('[data-sum-range]');
  const sumSub = $('[data-sum-sub]');
  const sumType = $('[data-sum-type]');
  const sumLangs = $('[data-sum-langs]');
  const sumFeat = $('[data-sum-feat]');
  const sumMaint = $('[data-sum-maint]');
  const sumExtra = $('[data-sum-extra]');
  let shown = { min: 0, max: 0 };
  let tweenId = 0;

  function tweenRange(plan) {
    const from = { ...shown };
    const to = { min: plan.min, max: plan.max == null ? plan.min : plan.max };
    const id = ++tweenId;
    const t0 = performance.now();
    const dur = reduce ? 0 : 650;
    const step_ = (t) => {
      if (id !== tweenId) return;
      const p = dur ? Math.min(1, (t - t0) / dur) : 1;
      const e = p >= 1 ? 1 : 1 - Math.pow(2, -10 * p);
      shown = { min: from.min + (to.min - from.min) * e, max: from.max + (to.max - from.max) * e };
      sumRange.textContent = rangeText(shown.min, plan.max == null ? null : shown.max);
      if (p < 1) requestAnimationFrame(step_);
      else { shown = to; sumRange.textContent = rangeText(plan.min, plan.max); }
    };
    requestAnimationFrame(step_);
  }

  function extraLangs(s) {
    const plan = PLANS[s.type];
    if (!plan || !plan.incl) return false;
    return s.langs.some((l) => !plan.incl.includes(l));
  }

  function render() {
    const s = read();
    const plan = PLANS[s.type];
    if (!plan) {
      tweenId++;
      sumRange.textContent = '—';
      sumSub.textContent = plain(tr('dv.sum.empty'));
    } else if (s.type === 'unsure') {
      tweenId++;
      sumRange.textContent = '—';
      sumSub.textContent = plain(tr('dv.sum.unsure'));
    } else {
      sumSub.textContent = '';
      tweenRange(plan);
    }
    sumType.textContent = plan ? plain(tr(plan.name)) : '—';
    sumLangs.textContent = s.langs.length ? s.langs.map((l) => LANG_LABEL[l] || l).join(' · ') : '—';
    sumFeat.textContent = s.features.length ? s.features.map((f) => plain(tr(FEATURES[f]))).join(', ') : '—';
    sumMaint.textContent = plain(tr(MAINT[s.maint] || MAINT.none));
    const extra = extraLangs(s);
    sumExtra.hidden = !extra;
    if (extra) sumExtra.textContent = plain(tr('dv.sum.extra'));
  }

  /* ---------- Étapes ---------- */
  const errorEl = (k) => $(`[data-error="${k}"]`, form);
  function setError(k, msg) {
    const el = errorEl(k);
    if (el) el.textContent = msg || '';
  }
  function shake(el) {
    if (reduce || !el) return;
    el.classList.remove('shake');
    void el.offsetWidth;
    el.classList.add('shake');
  }

  function validate(n) {
    const s = read();
    let ok = true;
    if (n === 1) {
      ok = !!PLANS[s.type];
      setError('type', ok ? '' : plain(tr('dv.err.type')));
    } else if (n === 2) {
      ok = s.langs.length > 0;
      setError('langs', ok ? '' : plain(tr('dv.err.lang')));
    } else if (n === 4) {
      const nameOk = s.name.length > 1;
      const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s.email);
      $('#q-name').closest('.field').classList.toggle('has-error', !nameOk);
      $('#q-email').closest('.field').classList.toggle('has-error', !emailOk);
      setError('name', nameOk ? '' : plain(tr('dv.err.name')));
      setError('email', emailOk ? '' : plain(tr('dv.err.email')));
      setError('consent', s.consent ? '' : plain(tr('dv.err.consent')));
      ok = nameOk && emailOk && s.consent;
      if (!nameOk) $('#q-name').focus();
      else if (!emailOk) $('#q-email').focus();
    }
    if (!ok) shake(panels[n - 1]);
    return ok;
  }

  function show(n, back = false, focus = true) {
    step = n;
    maxReached = Math.max(maxReached, n);
    panels.forEach((p, i) => {
      const on = i + 1 === n;
      p.hidden = !on;
      p.classList.toggle('is-back', on && back);
      if (on) { p.style.animation = 'none'; void p.offsetWidth; p.style.animation = ''; }
    });
    inds.forEach((li, i) => {
      li.classList.toggle('is-done', i + 1 < n);
      li.classList.toggle('is-current', i + 1 === n);
      if (i + 1 === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
      const b = $('button', li);
      if (b) b.disabled = i + 1 > maxReached || i + 1 === n;
    });
    btnPrev.hidden = n === 1;
    btnNext.hidden = n === last;
    btnSend.hidden = n !== last;
    if (!focus) return;
    const legend = $('.q-title', panels[n - 1]);
    if (legend) legend.focus({ preventScroll: true });
    const top = form.getBoundingClientRect().top;
    if (top < 70) window.scrollTo({ top: window.scrollY + top - 90, behavior: reduce ? 'auto' : 'smooth' });
  }

  btnNext.addEventListener('click', () => { if (validate(step)) show(Math.min(last, step + 1)); });
  btnPrev.addEventListener('click', () => show(Math.max(1, step - 1), true));
  inds.forEach((li, i) => {
    const b = $('button', li);
    if (b) b.addEventListener('click', () => { if (i + 1 <= maxReached) show(i + 1, i + 1 < step); });
  });
  // Entrée dans un champ texte : passe à l'étape suivante plutôt que d'envoyer
  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('input') && step < last) { e.preventDefault(); btnNext.click(); }
  });

  form.addEventListener('change', (e) => {
    syncChecks();
    render();
    if (e.target.name === 'type') {
      setError('type', '');
      // Étape 1 : le choix fait avancer tout seul
      setTimeout(() => { if (step === 1) show(2); }, reduce ? 0 : 380);
    }
    if (e.target.name === 'langs') setError('langs', '');
    if (e.target.name === 'consent' && e.target.checked) setError('consent', '');
  });

  /* ---------- Envoi ---------- */
  function summaryText(s) {
    const plan = PLANS[s.type];
    const lines = [
      `${plain(tr('dv.subject'))} · DYRO WEB`,
      '',
      `${plain(tr('dv.s1'))} : ${plan ? plain(tr(plan.name)) : '—'}`,
    ];
    if (plan && plan.min) lines.push(`${plain(tr('dv.sum.range'))} : ${rangeText(plan.min, plan.max)}`);
    lines.push(
      `${plain(tr('dv.s2'))} : ${s.langs.map((l) => LANG_LABEL[l] || l).join(', ') || '—'}`,
      `${plain(tr('dv.q3'))} : ${s.features.map((f) => plain(tr(FEATURES[f]))).join(', ') || '—'}`,
      `${plain(tr('dv.q3b'))} : ${plain(tr(MAINT[s.maint] || MAINT.none))}`,
      `${plain(tr('dv.q3c'))} : ${s.deadline ? plain(tr(DEADLINE[s.deadline])) : '—'}`,
      `${plain(tr('dv.site'))} : ${s.site || '—'}`,
      '',
      `${plain(tr('dv.name'))} : ${s.name}`,
      `${plain(tr('dv.company'))} : ${s.company || '—'}`,
      `${plain(tr('dv.email'))} : ${s.email}`,
      `${plain(tr('dv.phone'))} : ${s.phone || '—'}`,
      '',
      `${plain(tr('dv.msg'))} :`,
      s.message || '—',
    );
    return lines.join('\n');
  }

  function finish(panel) {
    panels.forEach((p) => { p.hidden = true; });
    nav.hidden = true;
    steps.hidden = true;
    panel.hidden = false;
    const h = $('h2', panel);
    if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
    const top = form.getBoundingClientRect().top;
    if (top < 70) window.scrollTo({ top: window.scrollY + top - 90, behavior: reduce ? 'auto' : 'smooth' });
  }

  function fallback(s, text) {
    const subject = `${plain(tr('dv.subject'))} – ${s.name}`;
    const href = `mailto:${TO}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(text)}`;
    $('[data-fb-text]', fbPanel).value = text;
    $('[data-fb-mail]', fbPanel).setAttribute('href', href);
    finish(fbPanel);
    try { window.location.href = href; } catch (e) { /* messagerie indisponible */ }
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    for (let n = 1; n <= last; n++) {
      if (!validate(n)) { if (n !== step) show(n, n < step); return; }
    }
    const s = read();
    const text = summaryText(s);
    if (s.website) { finish(donePanel); return; } // robot : on ne fait rien

    btnSend.classList.add('is-loading');
    const label = $('[data-i18n="dv.send"]', btnSend);
    if (label) label.textContent = plain(tr('dv.sending'));
    let sent = false;
    try {
      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 9000);
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...s, summary: text, lang: D.lang(), elapsed: Date.now() - startedAt }),
        signal: ctrl.signal,
      });
      clearTimeout(timer);
      const ct = res.headers.get('content-type') || '';
      if (res.ok && ct.includes('json')) { const json = await res.json(); sent = !!json.ok; }
    } catch (err) { sent = false; }
    btnSend.classList.remove('is-loading');
    if (label) label.textContent = plain(tr('dv.send'));
    if (sent) finish(donePanel); else fallback(s, text);
  });

  // Copier la demande (solution de secours)
  const copyBtn = $('[data-fb-copy]', fbPanel);
  if (copyBtn) copyBtn.addEventListener('click', () => {
    const ta = $('[data-fb-text]', fbPanel);
    const done = () => { copyBtn.textContent = plain(tr('copied')); setTimeout(() => { copyBtn.textContent = plain(tr('copy')); }, 1600); };
    const select = () => { ta.focus(); ta.select(); copyBtn.textContent = plain(tr('selected')); setTimeout(() => { copyBtn.textContent = plain(tr('copy')); }, 1600); };
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(ta.value).then(done, select);
      else select();
    } catch (err) { select(); }
  });

  /* ---------- Démarrage ---------- */
  // Formule présélectionnée depuis la section Offres (devis.html#pro, etc.)
  const pre = location.hash.slice(1);
  if (PLANS[pre]) {
    const r = $(`input[name="type"][value="${pre}"]`, form);
    if (r) r.checked = true;
  }
  syncChecks();
  render();
  show(PLANS[pre] ? 2 : 1, false, false);
  D.onLang(() => { render(); });
})();
