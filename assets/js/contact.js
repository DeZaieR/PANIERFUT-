/* DYRO STUDIOS — formulaire de devis */
(() => {
  'use strict';
  const form = document.getElementById('devis');
  if (!form) return;
  const $ = (s, r = form) => r.querySelector(s);
  const M = k => form.dataset['m' + k[0].toUpperCase() + k.slice(1)] || '';
  const MAX = 10 * 1024 * 1024;
  const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  const MAIL = 'contact@dyrostudios.com';
  const started = Date.now();

  // Pré-remplissage depuis l'adresse (?type=devis&offre=pack-essentiel), y compris les anciens liens
  const slug = s => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const ALIAS = { 'retouche': 'retouche-lumiere-couleurs', 'video': 'video-immobiliere', 'carrousel': 'carrousel-social', 'essential': 'auto-essential', 'cinematic': 'auto-cinematic', 'premium': 'auto-premium', 'forfait-mensuel': 'auto-forfait' };
  const q = new URLSearchParams(location.search);
  let type = slug(q.get('type'));
  let offre = slug(q.get('offre'));
  offre = ALIAS[offre] || offre;
  const sel = $('#f-offre');
  if (offre && sel.querySelector(`option[value="${offre}"]`)) sel.value = offre;
  if (!type && /^(auto|pub)-/.test(offre)) type = 'pub';
  if (['essai', 'devis', 'partenariat', 'pub'].includes(type)) form.querySelector(`input[name=type][value="${type}"]`).checked = true;
  sel.addEventListener('change', () => {
    if (/^(auto|pub)-/.test(sel.value)) form.querySelector('input[name=type][value=pub]').checked = true;
    else if (sel.value === 'agence-partenaire') form.querySelector('input[name=type][value=partenariat]').checked = true;
  });

  // Photo : choix, glisser-déposer, aperçu
  const drop = $('[data-drop]'), file = $('input[type=file]'), ico = $('[data-drop-ico]'), nameEl = $('[data-drop-name]'), info = $('[data-drop-info]');
  const icoHTML = ico.innerHTML, nameTxt = nameEl.textContent, infoTxt = info.textContent;
  let photo = null;
  const fmt = n => n > 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.round(n / 1024) + ' Ko';
  const clearPhoto = () => { photo = null; file.value = ''; ico.innerHTML = icoHTML; nameEl.textContent = nameTxt; info.textContent = infoTxt; drop.querySelector('.drop__x')?.remove(); };
  const setPhoto = f => {
    clearErr(drop);
    if (!f) return clearPhoto();
    if (!TYPES.includes(f.type) || f.size > MAX) { clearPhoto(); return showErr(drop, M('file')); }
    photo = f;
    const url = URL.createObjectURL(f);
    ico.innerHTML = `<img src="${url}" alt="">`;
    nameEl.textContent = f.name; info.textContent = fmt(f.size);
    if (!drop.querySelector('.drop__x')) {
      const x = document.createElement('button'); x.type = 'button'; x.className = 'drop__x'; x.textContent = M('remove');
      x.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); clearPhoto(); });
      drop.appendChild(x);
    }
  };
  file.addEventListener('change', () => setPhoto(file.files[0]));
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) setPhoto(f); });

  // Validation
  function showErr(el, msg) {
    const box = el.closest('.field') || el.closest('.check') || el;
    box.classList.add('is-err');
    el.classList?.add('is-err');
    let s = box.querySelector(':scope > .err');
    if (!s) { s = document.createElement('span'); s.className = 'err'; s.id = 'e-' + Math.random().toString(36).slice(2, 8); box.appendChild(s); }
    s.textContent = msg;
    const input = box.querySelector('input, select, textarea');
    if (input) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', s.id); }
  }
  function clearErr(el) {
    const box = el.closest('.field') || el.closest('.check') || el;
    box.classList.remove('is-err'); el.classList?.remove('is-err');
    box.querySelector(':scope > .err')?.remove();
    const input = box.querySelector('input, select, textarea');
    if (input) { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); }
  }
  form.addEventListener('input', e => { if (e.target.closest('.is-err')) clearErr(e.target); });
  form.addEventListener('change', e => { if (e.target.closest('.is-err')) clearErr(e.target); });

  function validate() {
    let ok = true;
    form.querySelectorAll('.err').forEach(e => e.remove());
    form.querySelectorAll('.is-err').forEach(e => e.classList.remove('is-err'));
    ['nom', 'societe', 'email', 'pays', 'message'].forEach(n => {
      const el = form.elements[n];
      if (!el.value.trim()) { showErr(el, M('req')); ok = false; }
    });
    const em = form.elements.email;
    if (em.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(em.value.trim())) { showErr(em, M('email')); ok = false; }
    const link = form.elements.lien.value.trim();
    const t = form.elements.type.value;
    if (t === 'essai' && !photo && !link) { showErr(drop, M('trial')); ok = false; }
    if ((photo || link) && !form.elements.auth.checked) { showErr(form.elements.auth, M('auth')); ok = false; }
    if (!form.elements.rgpd.checked) { showErr(form.elements.rgpd, M('req')); ok = false; }
    return ok;
  }

  const alertBox = $('.form__alert');
  const btn = $('.form__send');
  const btnHTML = btn.innerHTML;
  const fallback = () => {
    const f = form.elements;
    const body = [`Type : ${f.type.value}`, `Offre : ${sel.options[sel.selectedIndex].text}`, `Nom : ${f.nom.value}`, `Entreprise : ${f.societe.value}`, `Email : ${f.email.value}`, `Téléphone : ${f.tel.value}`, `Pays : ${f.pays.value}`, `Lien : ${f.lien.value}`, '', f.message.value].join('\n');
    return `mailto:${MAIL}?subject=${encodeURIComponent('Demande — ' + f.type.value)}&body=${encodeURIComponent(body)}`;
  };
  const fail = key => {
    alertBox.hidden = false;
    alertBox.innerHTML = '';
    alertBox.append(M(key) + ' ');
    const a = document.createElement('a'); a.href = fallback(); a.textContent = MAIL; alertBox.appendChild(a);
  };

  form.addEventListener('submit', async e => {
    e.preventDefault();
    alertBox.hidden = true;
    if (!validate()) {
      const first = form.querySelector('.is-err input, .is-err select, .is-err textarea, .drop.is-err input');
      first?.focus({ preventScroll: true });
      (form.querySelector('.is-err') || form).scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    form.elements.t.value = String(Date.now() - started);
    const data = new FormData(form);
    data.delete('photo');
    if (photo) data.append('photo', photo, photo.name);
    btn.disabled = true; btn.textContent = M('sending');
    try {
      const ctrl = new AbortController(); const timer = setTimeout(() => ctrl.abort(), 45000);
      const res = await fetch(form.action, { method: 'POST', body: data, signal: ctrl.signal, headers: { Accept: 'application/json' } });
      clearTimeout(timer);
      const out = await res.json().catch(() => ({}));
      if (res.ok && out.ok) {
        const done = document.querySelector('.done');
        form.hidden = true; done.hidden = false; done.focus();
        done.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      fail(res.status === 429 ? 'wait' : 'fail');
    } catch (_) {
      fail('fail');
    } finally {
      btn.disabled = false; btn.innerHTML = btnHTML;
    }
  });
})();
