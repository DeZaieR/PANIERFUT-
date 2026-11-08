/* DYRO WEB — assistant IA (fenêtre de discussion).
   Chargé à la demande par main.js, au premier clic sur le bouton de la barre d'actions.
   Réponses : api/assistant.php. La conversation reste dans l'onglet (sessionStorage), jamais sur le serveur. */
(() => {
  'use strict';
  if (window.DyroAssistant) return;

  const D = window.DYRO;
  const d = document;
  const ENDPOINT = '/api/assistant.php';
  const STORE = 'dyro_as';
  const MAX_KEEP = 30;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  D.addDefaults({
    'as.title': 'Assistant DYRO WEB',
    'as.badge': 'IA',
    'as.sub': 'Réponses générées par une IA · Dany reprend votre demande',
    'as.hello': 'Bonjour, je suis l’assistant IA de DYRO WEB. Posez-moi vos questions sur nos offres, les prix, les délais ou l’aide de l’État : je vous réponds tout de suite.',
    'as.q1': 'Quelle offre pour mon activité ?',
    'as.q2': 'Comment marche l’aide de 70 % ?',
    'as.q3': 'Délais et paiement',
    'as.q4': 'Je veux être rappelé',
    'as.ph': 'Votre question…',
    'as.send': 'Envoyer',
    'as.close': 'Fermer l’assistant',
    'as.reset': 'Nouvelle conversation',
    'as.typing': 'L’assistant écrit…',
    'as.note': 'Ne partagez pas d’informations sensibles. <a href="/confidentialite.html#assistant">Confidentialité</a>',
    'as.err': 'L’assistant est indisponible pour le moment. Écrivez-nous à contact@dyroweb.online, appelez le +352 691 349 632 ou réservez un appel : https://dyroweb.online/rdv',
    'as.rate': 'Vous avez envoyé beaucoup de messages. Pour continuer, réservez un appel : https://dyroweb.online/rdv',
    'as.lead': 'Demande transmise',
  });
  const tr = (k) => String(D.tr(k));
  const plain = (s) => String(s).replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').trim();

  /* ---------- Conversation (onglet courant seulement) ---------- */
  let msgs = [];
  try { msgs = JSON.parse(sessionStorage.getItem(STORE) || '[]'); } catch (e) { msgs = []; }
  if (!Array.isArray(msgs)) msgs = [];
  const save = () => {
    try { sessionStorage.setItem(STORE, JSON.stringify(msgs.slice(-MAX_KEEP))); } catch (e) { /* stockage indisponible */ }
  };

  /* ---------- Mise en forme sûre : texte échappé, liens cliquables ---------- */
  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  function format(text) {
    // Espace insécable avant ? ! : ; » (évite la ponctuation seule en début de ligne)
    let h = esc(text.replace(/ ([?!:;»])/g, '\u00a0$1')).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)»])|([\w.+-]+@[\w-]+\.[\w.-]*\w)|(\+352[\d\s]{6,15}\d)/g, (m, url, mail, tel) => {
      if (url) {
        const own = /^https:\/\/(www\.)?dyroweb\.online\b/.test(url);
        const href = url.replace(/&amp;/g, '&');
        return `<a href="${esc(href)}"${own ? '' : ' target="_blank" rel="noopener"'}>${url.replace(/^https?:\/\//, '')}</a>`;
      }
      if (mail) return `<a href="mailto:${mail}">${mail}</a>`;
      return `<a href="tel:${tel.replace(/\s+/g, '')}">${tel}</a>`;
    });
    return h.split(/\n{2,}/).map((p) => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');
  }

  /* ---------- Fenêtre ---------- */
  const panel = d.createElement('section');
  panel.className = 'as';
  panel.id = 'assistant';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-labelledby', 'as-title');
  panel.hidden = true;
  panel.innerHTML = `
    <header class="as__head">
      <span class="as__avatar" aria-hidden="true"><img src="/assets/img/dyro-mark-160.webp" alt="" width="160" height="93"></span>
      <div class="as__id">
        <h2 class="as__title" id="as-title"><span data-as="as.title"></span> <span class="as__badge" data-as="as.badge"></span></h2>
        <p class="as__sub" data-as="as.sub"></p>
      </div>
      <button type="button" class="as__icon" data-as-reset data-as-attr="aria-label:as.reset;title:as.reset"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7M4 4v5h5"/></svg></button>
      <button type="button" class="as__icon" data-as-close data-as-attr="aria-label:as.close;title:as.close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </header>
    <div class="as__log" data-as-log aria-live="polite"></div>
    <form class="as__form" data-as-form>
      <input type="text" name="hp" tabindex="-1" autocomplete="off" class="as__hp" aria-hidden="true">
      <textarea class="as__input" name="q" rows="1" maxlength="1000" data-as-attr="placeholder:as.ph;aria-label:as.ph"></textarea>
      <button type="submit" class="as__send" data-as-attr="aria-label:as.send"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
    </form>
    <p class="as__note" data-as="as.note"></p>`;
  d.body.appendChild(panel);

  const $ = (s) => panel.querySelector(s);
  const log = $('[data-as-log]');
  const form = $('[data-as-form]');
  const input = $('.as__input');
  const sendBtn = $('.as__send');
  let opener = null;
  let busy = false;

  function texts() {
    panel.querySelectorAll('[data-as]').forEach((el) => { el.innerHTML = tr(el.dataset.as); });
    panel.querySelectorAll('[data-as-attr]').forEach((el) => el.dataset.asAttr.split(';').forEach((pair) => {
      const [a, k] = pair.split(':');
      el.setAttribute(a, plain(tr(k)));
    }));
  }

  function bubble(role, text, extra) {
    const el = d.createElement('div');
    el.className = `as__msg as__msg--${role}${extra ? ` ${extra}` : ''}`;
    el.innerHTML = role === 'user' ? `<p>${esc(text).replace(/\n/g, '<br>')}</p>` : format(text);
    log.appendChild(el);
    return el;
  }

  function render() {
    log.innerHTML = '';
    bubble('assistant', plain(tr('as.hello')));
    msgs.forEach((m) => {
      const el = bubble(m.role, m.content, m.local ? 'as__msg--note' : '');
      if (m.lead) el.insertAdjacentHTML('beforeend', `<span class="as__lead">${esc(plain(tr('as.lead')))}</span>`);
    });
    if (!msgs.some((m) => m.role === 'user')) {
      const chips = d.createElement('div');
      chips.className = 'as__chips';
      ['as.q1', 'as.q2', 'as.q3', 'as.q4'].forEach((k) => {
        const b = d.createElement('button');
        b.type = 'button';
        b.className = 'as__chip';
        b.textContent = plain(tr(k));
        b.addEventListener('click', () => ask(b.textContent));
        chips.appendChild(b);
      });
      log.appendChild(chips);
    }
    scrollEnd();
  }

  const scrollEnd = () => { log.scrollTop = log.scrollHeight; };

  function grow() {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 132)}px`;
  }

  async function ask(text) {
    const q = String(text || '').trim().slice(0, 1000);
    if (!q || busy) return;
    busy = true;
    sendBtn.disabled = true;
    msgs.push({ role: 'user', content: q });
    save();
    render();
    const typing = bubble('assistant', '', 'as__msg--typing');
    typing.innerHTML = `<span class="as__dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="sr-only">${esc(plain(tr('as.typing')))}</span>`;
    scrollEnd();

    // Seuls les vrais échanges partent au serveur (jamais les messages d'erreur locaux)
    const history = msgs.filter((m) => !m.local).map((m) => ({ role: m.role, content: m.content })).slice(-16);
    let out = null;
    let status = 0;
    try {
      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 45000);
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: history, lang: D.lang(), page: location.pathname, hp: form.hp.value }),
        signal: ctrl.signal,
        credentials: 'omit',
      });
      clearTimeout(timer);
      status = res.status;
      if ((res.headers.get('content-type') || '').includes('json')) out = await res.json();
    } catch (e) { out = null; }

    if (out && out.ok && out.reply) {
      msgs.push({ role: 'assistant', content: String(out.reply), lead: !!out.lead });
      if (out.lead && typeof window.dyroTrack === 'function') window.dyroTrack('form', 'assistant');
    } else {
      msgs.push({ role: 'assistant', content: plain(tr(status === 429 ? 'as.rate' : 'as.err')), local: true });
    }
    save();
    busy = false;
    sendBtn.disabled = false;
    render();
    if (!matchMedia('(pointer: coarse)').matches) input.focus();
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const q = input.value;
    input.value = '';
    grow();
    ask(q);
  });
  input.addEventListener('input', grow);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
  });
  $('[data-as-close]').addEventListener('click', () => close());
  $('[data-as-reset]').addEventListener('click', () => { msgs = []; save(); render(); input.focus(); });
  panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  function open(from) {
    opener = from || d.activeElement;
    texts();
    render();
    panel.hidden = false;
    d.documentElement.classList.add('as-open');
    requestAnimationFrame(() => panel.classList.add('is-on'));
    if (!matchMedia('(pointer: coarse)').matches) input.focus({ preventScroll: true });
    else $('[data-as-close]').focus({ preventScroll: true });
    d.querySelectorAll('[data-assistant]').forEach((b) => b.setAttribute('aria-expanded', 'true'));
  }

  function close() {
    panel.classList.remove('is-on');
    d.documentElement.classList.remove('as-open');
    d.querySelectorAll('[data-assistant]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    setTimeout(() => { if (!panel.classList.contains('is-on')) panel.hidden = true; }, reduce ? 0 : 320);
    if (opener && opener.focus) opener.focus({ preventScroll: true });
  }

  D.onLang(() => { if (!panel.hidden) { texts(); render(); } });

  window.DyroAssistant = {
    open,
    close,
    toggle: (from) => (panel.hidden ? open(from) : close()),
  };
})();
