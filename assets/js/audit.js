/* DYRO WEB — audit gratuit de site (audit.html). Analyse côté serveur : api/audit.php */
(() => {
  'use strict';
  const form = document.querySelector('[data-audit-form]');
  if (!form) return;
  const FR = {
"au.loading": "Analyse en cours…",
"au.c.https": "Connexion sécurisée (HTTPS)",
"au.c.speed": "Temps de réponse du serveur",
"au.c.weight": "Poids de la page",
"au.c.title": "Titre pour Google",
"au.c.description": "Description pour Google",
"au.c.h1": "Titre principal de la page (H1)",
"au.c.index": "Visible sur Google",
"au.c.sitemap": "Plan du site pour Google (sitemap)",
"au.c.schema": "Informations structurées pour Google",
"au.c.social": "Aperçu lors d’un partage",
"au.c.viewport": "Adapté au téléphone",
"au.c.alt": "Descriptions des images",
"au.c.langs": "Langues du site",
"au.c.contact": "Appel en un clic",
"au.f.https": "Sans HTTPS, les navigateurs affichent « Non sécurisé » et Google vous classe moins bien.",
"au.f.speed": "Le serveur met trop de temps à répondre : les visiteurs sur mobile partent avant l’affichage.",
"au.f.weight": "La page est lourde : elle s’affiche lentement sur le réseau mobile.",
"au.f.title": "Le titre doit faire 15 à 65 caractères et dire votre métier et votre ville.",
"au.f.description": "Une description de 70 à 160 caractères donne envie de cliquer dans les résultats Google.",
"au.f.h1": "La page doit avoir un seul titre principal qui dit clairement ce que vous faites.",
"au.f.index": "La page demande à Google de ne pas l’afficher : vous êtes invisible dans les recherches.",
"au.f.sitemap": "Un plan du site aide Google à trouver toutes vos pages.",
"au.f.schema": "Ces informations (adresse, horaires, avis) permettent à Google d’afficher une fiche plus complète.",
"au.f.social": "Sans image ni titre de partage, vos liens s’affichent mal sur WhatsApp, Facebook ou LinkedIn.",
"au.f.viewport": "La page n’est pas adaptée au téléphone, alors que beaucoup de visiteurs arrivent depuis leur mobile.",
"au.f.alt": "Des images sans description : Google ne les comprend pas, les lecteurs d’écran non plus.",
"au.f.langs": "Au Luxembourg, vos clients parlent français, allemand, anglais, portugais ou luxembourgeois : avec une seule langue, une partie d’entre eux vous échappe.",
"au.f.contact": "Ajoutez un numéro cliquable : sur mobile, vos clients vous appellent en un geste.",
"au.err.url": "Entrez l’adresse de votre site, par exemple votresite.lu.",
"au.err.unreachable": "Impossible d’ouvrir ce site. Vérifiez l’adresse et réessayez.",
"au.err.status": "Le site répond par une erreur. Vérifiez l’adresse.",
"au.err.rate": "Trop d’audits en peu de temps. Réessayez dans une heure.",
"au.err.fail": "Une erreur est survenue. Réessayez dans un instant.",
"au.err.fields": "Indiquez votre nom, un email valide et cochez la case d’accord.",
"au.v.good": "Très bon niveau : quelques détails à peaufiner.",
"au.v.mid": "Des bases correctes, mais des points vous font perdre des visites.",
"au.v.low": "Votre site vous fait perdre des clients : plusieurs points sont à corriger.",
"au.s.ok": "Bon",
"au.s.warn": "À améliorer",
"au.s.bad": "À corriger",
"au.u.chars": "car."
};
  const IDS = ["https", "speed", "weight", "title", "description", "h1", "index", "sitemap", "schema", "social", "viewport", "alt", "langs", "contact"];
  const $ = (s, r = document) => r.querySelector(s);
  const tr = (k) => (window.DYRO ? window.DYRO.tr(k) : (FR[k] || k));
  const lang = () => (window.DYRO ? window.DYRO.lang() : (document.documentElement.lang || 'fr').slice(0, 2));
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const onReady = (fn) => (window.DYRO ? fn() : document.addEventListener('dyro:ready', fn, { once: true }));
  const sec = $('[data-audit]'), msg = $('[data-audit-msg]'), input = $('#au-url'), go = form.querySelector('button[type="submit"]');
  let last = null;

  const say = (text, err) => { msg.textContent = text; msg.classList.toggle('is-error', !!err); };
  const color = (n) => (n >= 85 ? '#7fe3a8' : n >= 60 ? '#ffd27a' : '#ff8a8a');
  const ICON = { ok: '✓', warn: '!', bad: '✗' };
  const LOC = { fr: 'fr-FR', de: 'de-DE', en: 'en-GB', pt: 'pt-PT', lb: 'de-LU' };
  const val = (c, l) => {
    if (c.num == null) return c.value || '';
    const nf = (n, d) => Number(n).toLocaleString(LOC[l] || 'fr-FR', { maximumFractionDigits: d, minimumFractionDigits: d });
    if (c.id === 'speed') return nf(c.num, 2) + ' s';
    if (c.id === 'weight') return nf(c.num, 0) + (l === 'fr' ? ' Ko' : ' KB');
    return nf(c.num, 0) + ' ' + (l === 'fr' ? FR['au.u.chars'] : tr('au.u.chars'));
  };

  function render(d) {
    sec.hidden = false;
    $('[data-audit-url]').textContent = d.url.replace(/^https?:\/\//, '').replace(/\/$/, '');
    $('[data-audit-verdict]').textContent = tr(d.score >= 85 ? 'au.v.good' : d.score >= 60 ? 'au.v.mid' : 'au.v.low');
    const ring = $('[data-audit-ring]');
    ring.style.setProperty('--sc', color(d.score));
    const fg = ring.querySelector('.fg');
    requestAnimationFrame(() => { fg.style.strokeDasharray = d.score + ' 100'; });
    const num = $('[data-audit-score]');
    const t0 = performance.now();
    const tick = (t) => { const k = Math.min(1, (t - t0) / 1000); num.textContent = Math.round(d.score * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
    document.querySelectorAll('[data-cat]').forEach((li) => {
      const v = d.cats[li.dataset.cat];
      li.querySelector('.audit-cat__v').textContent = v == null ? '—' : v;
      const bar = li.querySelector('i'); bar.style.background = color(v || 0);
      requestAnimationFrame(() => { bar.style.width = (v || 0) + '%'; });
    });
    const order = { bad: 0, warn: 1, ok: 2 };
    const rows = [...d.checks].sort((a, b) => order[a.status] - order[b.status] || IDS.indexOf(a.id) - IDS.indexOf(b.id));
    $('[data-audit-list]').innerHTML = rows.map((c) => `<li class="audit-item"><span class="audit-item__st st-${c.status}">${ICON[c.status]} ${esc(tr('au.s.' + c.status))}</span>`
      + `<span class="audit-item__t">${esc(tr('au.c.' + c.id))}${val(c, lang()) ? `<small>${esc(val(c, lang()))}</small>` : ''}</span>`
      + (c.status !== 'ok' ? `<span class="audit-item__f">${esc(tr('au.f.' + c.id))}</span>` : '') + '</li>').join('');
  }

  async function run(url) {
    if (!url) { say(tr('au.err.url'), true); input.focus(); return; }
    say(tr('au.loading'));
    go.disabled = true; form.classList.add('is-busy');
    try {
      const res = await fetch('/api/audit.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ url, lang: lang() }) });
      const d = await res.json().catch(() => ({}));
      if (!res.ok || !d.ok) { say(tr('au.err.' + ({ url: 'url', unreachable: 'unreachable', status: 'status', rate: 'rate' }[d.error] || 'fail')), true); return; }
      last = d; say(''); render(d);
      $('[data-audit-lead]').hidden = false; $('[data-audit-done]').hidden = true;
      setTimeout(() => sec.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }), 120);
    } catch (e) {
      say(tr('au.err.fail'), true);
    } finally {
      go.disabled = false; form.classList.remove('is-busy');
    }
  }

  form.addEventListener('submit', (e) => { e.preventDefault(); run(input.value.trim()); });

  const lead = $('[data-audit-lead]'), err = $('[data-audit-lead-err]');
  lead.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!last) return;
    const f = lead.elements;
    const name = f.name.value.trim(), email = f.email.value.trim();
    if (!name || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) || !f.consent.checked) { err.textContent = tr('au.err.fields'); return; }
    err.textContent = '';
    const btn = lead.querySelector('button[type="submit"]'); btn.disabled = true;
    const checks = last.checks.map((c) => ({ label: FR['au.c.' + c.id], status: c.status, value: val(c, 'fr') }));
    try {
      const res = await fetch('/api/audit.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ action: 'lead', name, email, company: f.company.value.trim(), phone: f.phone.value.trim(), url: last.url, score: last.score, checks, lang: lang(), consent: true, website: f.website.value }) });
      const d = await res.json().catch(() => ({}));
      if (!res.ok || !d.ok) { err.textContent = tr(d.error === 'rate' ? 'au.err.rate' : d.error === 'fields' ? 'au.err.fields' : 'au.err.fail'); return; }
      lead.hidden = true; $('[data-audit-done]').hidden = false;
    } catch (e2) {
      err.textContent = tr('au.err.fail');
    } finally { btn.disabled = false; }
  });
  $('[data-audit-again]').addEventListener('click', () => {
    input.value = ''; sec.hidden = true; last = null; lead.reset();
    window.scrollTo({ top: 0, behavior: 'smooth' }); setTimeout(() => input.focus(), 400);
  });

  onReady(() => {
    if (window.DYRO && window.DYRO.addDefaults) window.DYRO.addDefaults(FR);
    if (window.DYRO && window.DYRO.onLang) window.DYRO.onLang(() => { if (last) render(last); });
    // Lien direct : audit.html?url=exemple.lu lance l'audit tout de suite
    const q = new URLSearchParams(location.search).get('url');
    if (q) { input.value = q; run(q.trim()); }
  });
})();
