"""Génère les pages intérieures (devis, pages légales, 404) à partir de l'accueil.

L'en-tête, le menu, la barre mobile et le footer sont repris de index.html :
modifier index.html puis relancer :  python3 scripts/build_pages.py
"""
import os, re
HERE = os.path.dirname(os.path.abspath(__file__))
PARTIALS = os.path.join(HERE, 'partials')
ROOT = os.path.normpath(os.path.join(HERE, '..')) + '/'
idx = open(ROOT + 'index.html').read()

shared = idx.split('<!-- Curseur personnalisé (souris uniquement) -->', 1)[1].split('<main id="top"', 1)[0]
assert '<section' not in shared, 'en-tête partagé trop long'
shared = '<!-- Curseur personnalisé (souris uniquement) -->' + shared
footer = '<footer class="footer">' + idx.split('<footer class="footer">', 1)[1].split('</footer>', 1)[0] + '</footer>'

def relink(html):
    for anchor in ['services', 'pourquoi', 'methode', 'offres', 'faq', 'contact']:
        html = html.replace(f'href="#{anchor}"', f'href="./#{anchor}"')
    return html.replace('href="#top"', 'href="./"')

shared = relink(shared)
footer = relink(footer)
mbar = '<nav class="mbar"' + idx.split('<nav class="mbar"', 1)[1].split('</nav>', 1)[0] + '</nav>'
V = '3'
BOOT = open(os.path.join(PARTIALS, 'boot.html')).read()
ANALYTICS = '<!-- analytics:start -->\n' + open(os.path.join(PARTIALS, 'analytics.html')).read().rstrip('\n') + '\n<!-- analytics:end -->\n'
BOOK = re.search(r'href="([^"]+)" target="_blank" rel="noopener" data-i18n-attr="href:book.href"', idx).group(1)
SKIP = '<a class="skip" href="#top" data-i18n="skip">Aller au contenu</a>\n'
footer = footer.replace('<a href="./" class="footer__top-link">', '<a href="#top" class="footer__top-link">')
assert 'href="#top" class="footer__top-link"' in footer

ICON_ARROW = '<svg class="btn__arrow" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4"/></svg>'
CHECK = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3.5 8.5 3 3 6-7" pathLength="1"/></svg>'
RING = '<div class="ring"><div class="ring__comet"><i></i></div></div>'

def head(title, desc, extra='', i18n_title='', url='', assets='assets/', robots=''):
    t_attr = f' data-i18n="{i18n_title}"' if i18n_title else ''
    og = ''
    if url is not None:
        og = f"""<meta property="og:type" content="website">
<meta property="og:site_name" content="DYRO WEB">
<meta property="og:title" content="{title}">
<meta property="og:description" content="{desc}">
<meta property="og:url" content="https://dyroweb.online/{url}">
<meta property="og:image" content="https://dyroweb.online/assets/img/og-image.jpg">
<meta property="og:locale" content="fr_FR">
<meta name="twitter:card" content="summary_large_image">
"""
    rob = f'<meta name="robots" content="{robots}">\n' if robots else ''
    boot = BOOT.replace('__ASSETS__', assets).replace('__V__', V)
    return f"""<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title{t_attr}>{title}</title>
<meta name="description" content="{desc}">
<meta name="theme-color" content="#03050a">
<meta name="dyro:lang-paths" content="/de/ /en/ /pt/ /lb/">
{rob}{og}{extra}<link rel="icon" type="image/png" href="{assets}img/favicon.png">
<link rel="apple-touch-icon" href="{assets}img/apple-touch-icon.png">
<link rel="preload" href="{assets}fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{assets}css/fonts.css?v={V}">
<link rel="stylesheet" href="{assets}css/style.css?v={V}">
{ANALYTICS}{boot}</head>
<body>

{SKIP}"""

def page_footer(current, scripts, with_mbar=True, assets='assets/'):
    f = footer
    if current:
        f = f.replace(f'<a href="{current}" data-i18n=', f'<a href="{current}" aria-current="page" data-i18n=')
    bar = (mbar + '\n\n') if with_mbar else ''
    tags = '\n'.join(f'<script src="{assets}js/{s}?v={V}" defer></script>' for s in scripts)
    return f'\n{bar}{f}\n\n{tags}\n</body>\n</html>\n'

def page_hero(eyebrow_html, title_html, extra_html=''):
    return f'''  <section class="page-hero">
    <div class="page-hero__bg" aria-hidden="true">
      <div class="hero__grid"></div>
      <div class="page-hero__glow"></div>
      <div class="page-hero__ring">{RING}</div>
    </div>
    <div class="container">
      {eyebrow_html}
      {title_html}
{extra_html}
    </div>
  </section>
'''

# ---------------------------------------------------------------- Pages légales
UPDATED = 'Dernière mise à jour : 1er novembre 2026'
TODO = lambda t: f'<mark class="todo">[{t}]</mark>'

def legal_page(fname, title, desc, sections):
    toc = '\n'.join(f'        <a href="#{sid}">{label}</a>' for sid, label, _ in sections)
    body = ''
    for i, (sid, label, html) in enumerate(sections, 1):
        body += f'''        <section id="{sid}" data-reveal>
          <h2><span class="num">{i:02d}</span>{label}</h2>
{html}
        </section>
'''
    hero = page_hero(
        '<p class="eyebrow intro" style="--d:0" data-i18n="legal.eyebrow">Informations légales</p>',
        f'<h1 class="page-hero__title intro" style="--d:1">{title}</h1>',
        f'      <p class="page-hero__meta intro" style="--d:2">{UPDATED}</p>\n      <p class="legal__notice intro" style="--d:3" data-i18n="legal.notice">Page disponible en français uniquement.</p>')
    canon = f'<link rel="canonical" href="https://dyroweb.online/{fname}">\n'
    html = head(f'{title} · DYRO WEB', desc, canon, url=fname) + shared + f'''<main id="top" tabindex="-1">
{hero}
  <section class="legal">
    <div class="container legal__grid">
      <nav class="legal__toc" data-toc aria-label="Sommaire" data-i18n-attr="aria-label:legal.toc">
        <span class="legal__toc-h" data-i18n="legal.toc">Sommaire</span>
{toc}
      </nav>
      <div class="prose">
{body}      </div>
    </div>
  </section>
</main>
''' + page_footer(fname, ['main.js'])
    open(ROOT + fname, 'w').write(html)

ADDR = TODO('à compléter : adresse du lieu d’exploitation') + ', Luxembourg'

legal_page('mentions-legales.html', 'Mentions légales',
  'Mentions légales du site DYRO WEB : éditeur, hébergement, propriété intellectuelle.', [
  ('editeur', 'Éditeur du site', f'''          <p>Le site <strong>DYRO WEB</strong> est édité par l’entreprise individuelle de Dany Martins Freitas, qui exploite les marques Dyro Studios et DYRO WEB.</p>
          <dl>
            <dt>Exploitant</dt><dd>Dany Martins Freitas, entreprise individuelle</dd>
            <dt>Marques</dt><dd>Dyro Studios · DYRO WEB</dd>
            <dt>Adresse</dt><dd>{ADDR}</dd>
            <dt>Email</dt><dd><a href="mailto:contact@dyroweb.online">contact@dyroweb.online</a></dd>
            <dt>Téléphone</dt><dd><a href="tel:+352691349632">+352 691 349 632</a></dd>
            <dt>Site web</dt><dd><a href="https://dyroweb.online">dyroweb.online</a></dd>
            <dt>N° RCS</dt><dd>{TODO('à compléter')}</dd>
            <dt>Autorisation d’établissement</dt><dd>{TODO('à compléter')}</dd>
            <dt>N° TVA</dt><dd>{TODO('à compléter : LU…')}</dd>
          </dl>'''),
  ('publication', 'Directeur de la publication', '          <p>Dany Martins Freitas.</p>'),
  ('hebergement', 'Hébergement', f'''          <p>Le site est hébergé par Hostinger International Ltd, 61 Lordou Vironos Street, 6023 Larnaca, Chypre (<a href="https://www.hostinger.com" rel="noopener">hostinger.com</a>). {TODO('à confirmer selon votre hébergeur')}</p>'''),
  ('propriete', 'Propriété intellectuelle', '''          <p>L’ensemble des éléments du site (textes, logo DYRO WEB, éléments graphiques, animations et code) est la propriété de Dyro Studios, sauf mention contraire.</p>
          <p>Toute reproduction, représentation ou adaptation, totale ou partielle, sans autorisation écrite préalable est interdite.</p>'''),
  ('responsabilite', 'Responsabilité', '''          <p>Les informations publiées sur ce site sont fournies à titre indicatif et peuvent évoluer. Les prix affichés sont indicatifs et exprimés hors TVA : seul le devis accepté fait foi.</p>
          <p>DYRO WEB ne peut être tenue responsable du contenu des sites externes vers lesquels renvoient des liens.</p>'''),
  ('donnees', 'Données personnelles', '          <p>Le traitement de vos données personnelles est décrit dans notre <a href="confidentialite.html">politique de confidentialité</a>.</p>'),
  ('droit', 'Droit applicable', '          <p>Le présent site et ses mentions légales sont soumis au droit luxembourgeois.</p>'),
])

legal_page('confidentialite.html', 'Politique de confidentialité',
  'Politique de confidentialité de DYRO WEB : données collectées, finalités, durées de conservation et vos droits (RGPD).', [
  ('responsable', 'Responsable du traitement', f'''          <p>Le responsable du traitement est Dany Martins Freitas, exploitant de Dyro Studios (marque DYRO WEB), {ADDR}.</p>
          <p>Pour toute question sur vos données : <a href="mailto:contact@dyroweb.online">contact@dyroweb.online</a>.</p>'''),
  ('donnees', 'Données collectées', '''          <ul>
            <li><strong>Formulaire de devis :</strong> nom, entreprise, email, téléphone et informations sur votre projet (type de site, langues, fonctionnalités, délai, adresse du site actuel, message).</li>
            <li><strong>Échanges par email, téléphone ou WhatsApp :</strong> les informations que vous choisissez de nous communiquer.</li>
            <li><strong>Données techniques :</strong> l’hébergeur enregistre des journaux de connexion (adresse IP, date et heure, pages demandées, navigateur) pour assurer la sécurité et le bon fonctionnement du site. Pour limiter les abus du formulaire, votre adresse IP est aussi conservée sous forme d’empreinte non réversible pendant 24 heures au plus.</li>
            <li><strong>Préférences dans votre navigateur :</strong> la langue choisie et la fermeture du bandeau sont enregistrées dans le stockage local de votre navigateur. Ces informations restent sur votre appareil et ne nous sont pas transmises.</li>
          </ul>'''),
  ('finalites', 'Finalités et bases légales', '''          <ul>
            <li><strong>Répondre à votre demande, vous envoyer un email de confirmation et établir un devis :</strong> mesures précontractuelles prises à votre demande (article 6.1.b du RGPD).</li>
            <li><strong>Exécuter le contrat et facturer :</strong> exécution du contrat (article 6.1.b) et respect de nos obligations légales comptables (article 6.1.c).</li>
            <li><strong>Assurer la sécurité du site :</strong> intérêt légitime (article 6.1.f).</li>
          </ul>
          <p>Vos données ne sont jamais vendues ni utilisées pour de la publicité ciblée.</p>'''),
  ('conservation', 'Durées de conservation', f'''          <ul>
            <li><strong>Demande de devis sans suite :</strong> 3 ans après le dernier contact. {TODO('durée à valider')}</li>
            <li><strong>Clients :</strong> pendant toute la relation contractuelle, puis 10 ans pour les pièces comptables, conformément à l’article 16 du Code de commerce.</li>
            <li><strong>Journaux techniques :</strong> durée limitée fixée par l’hébergeur.</li>
          </ul>'''),
  ('destinataires', 'Destinataires', '''          <p>Vos données sont traitées uniquement par DYRO WEB. Nos prestataires techniques (hébergement du site et messagerie) y ont accès dans la stricte mesure nécessaire à leur mission, sur nos instructions et sous obligation de confidentialité.</p>
          <p>Aucune donnée n’est transmise à des tiers à des fins commerciales.</p>'''),
  ('transferts', 'Transferts hors de l’Union européenne', '''          <p>Nous n’organisons aucun transfert de vos données hors de l’Union européenne. Si vous choisissez de nous écrire via WhatsApp, ce service édité par Meta traite vos échanges selon sa propre politique de confidentialité, y compris éventuellement hors de l’Union européenne.</p>
          <p>Les polices de caractères sont hébergées sur notre propre serveur : le site n’effectue aucune requête vers Google Fonts et n’utilise aucun outil de mesure d’audience.</p>'''),
  ('cookies', 'Cookies', '''          <p>Ce site n’utilise aucun cookie publicitaire ni de mesure d’audience. Seules les préférences décrites plus haut (langue et bandeau) sont stockées localement dans votre navigateur. Elles servent uniquement au fonctionnement que vous demandez et ne nécessitent donc pas de consentement.</p>'''),
  ('droits', 'Vos droits', '''          <p>Vous disposez d’un droit d’accès, de rectification, d’effacement, de limitation, d’opposition et de portabilité de vos données. Vous pouvez retirer votre consentement à tout moment.</p>
          <p>Pour exercer vos droits, écrivez à <a href="mailto:contact@dyroweb.online">contact@dyroweb.online</a>. Nous répondons dans un délai d’un mois.</p>'''),
  ('reclamation', 'Réclamation', '''          <p>Si vous estimez que vos droits ne sont pas respectés, vous pouvez introduire une réclamation auprès de la Commission nationale pour la protection des données (CNPD), 15, boulevard du Jazz, L-4370 Belvaux (<a href="https://cnpd.public.lu" rel="noopener">cnpd.public.lu</a>).</p>'''),
  ('modifications', 'Modifications', '          <p>Cette politique peut être mise à jour. La date de dernière mise à jour figure en haut de la page.</p>'),
])

legal_page('cgv.html', 'Conditions générales de vente',
  'Conditions générales de vente et de prestation de DYRO WEB : devis, prix, paiement, propriété du site, maintenance.', [
  ('champ', 'Champ d’application', '''          <p>Les présentes conditions générales s’appliquent à toutes les prestations de DYRO WEB, marque de Dyro Studios (Dany Martins Freitas, entreprise individuelle) : création de sites web, landing pages et boutiques en ligne, référencement, maintenance et hébergement.</p>
          <p>Elles complètent le devis. En cas de contradiction, les conditions particulières du devis prévalent.</p>'''),
  ('devis', 'Devis et commande', f'''          <p>Chaque projet fait l’objet d’un devis gratuit et détaillé, présenté en trois options lorsque c’est pertinent. Le devis est valable {TODO('30 jours, à valider')} à compter de sa date d’émission.</p>
          <p>La commande est ferme dès la signature du devis par le client et, le cas échéant, le paiement de l’acompte.</p>'''),
  ('prix', 'Prix', '''          <p>Les prix sont exprimés en euros et hors taxes. La TVA luxembourgeoise (17 % au taux normal) est ajoutée selon la réglementation applicable.</p>
          <p>Les frais de tiers (nom de domaine, licences, extensions payantes, banques d’images) ne sont pas inclus et sont facturés en supplément, sauf mention contraire dans le devis.</p>'''),
  ('paiement', 'Conditions de paiement', '''          <p>Pour tout projet d’un montant supérieur à 1 000 € HT, un acompte de 40 % est facturé à la signature. Les travaux commencent à sa réception.</p>
          <p>Le solde est facturé à la livraison et payable à 15 jours date de facture.</p>
          <p>Tout retard de paiement entraîne de plein droit des intérêts de retard au taux légal en vigueur et, pour les clients professionnels, une indemnité forfaitaire de 40 € pour frais de recouvrement. Après une mise en demeure restée sans effet, DYRO WEB peut suspendre ses prestations.</p>'''),
  ('obligations', 'Déroulement du projet et obligations du client', '''          <p>Le client fournit les contenus nécessaires (textes, images, logo, accès techniques) dans les délais convenus et garantit disposer des droits sur ces éléments.</p>
          <p>Tout retard dans la fourniture des contenus ou des validations décale d’autant le planning.</p>'''),
  ('corrections', 'Corrections, livraison et validation', f'''          <p>Deux tours de corrections sont inclus dans chaque projet. Les demandes supplémentaires ou hors périmètre font l’objet d’un devis complémentaire.</p>
          <p>À la livraison, le client dispose de {TODO('15 jours, à valider')} pour signaler toute anomalie. Passé ce délai, la livraison est réputée acceptée.</p>'''),
  ('delais', 'Délais', '          <p>Les délais sont indiqués dans le devis à titre prévisionnel. Ils courent à compter de la réception de l’acompte et des contenus nécessaires.</p>'),
  ('propriete', 'Propriété intellectuelle et transfert du site', '''          <p>Le site, son code et les contenus créés par DYRO WEB sont transférés au client, avec les accès administrateur, dès le paiement complet du prix. Jusqu’à ce paiement, ils restent la propriété de Dyro Studios.</p>
          <p>Les composants tiers (logiciels libres, extensions, polices, images sous licence) restent soumis à leurs propres licences.</p>
          <p>Sauf refus écrit du client, DYRO WEB peut mentionner la réalisation dans ses références.</p>'''),
  ('maintenance', 'Maintenance et hébergement', '''          <p>Trois formules sont proposées : Essentiel, Pro et Croissance, à 49, 99 et 199 € HT par mois, avec un engagement de 12 mois.</p>
          <p>Le contenu de chaque formule ainsi que les conditions de renouvellement et de résiliation sont précisés dans le devis.</p>'''),
  ('responsabilite', 'Responsabilité', '''          <p>DYRO WEB est tenue à une obligation de moyens. Sa responsabilité ne peut être engagée pour des dommages indirects (perte de chiffre d’affaires, de données ou de clientèle) ni pour des interruptions dues à des tiers (hébergeur, fournisseurs, opérateurs).</p>
          <p>Pour les clients professionnels, la responsabilité de DYRO WEB est limitée au montant hors taxes de la prestation concernée.</p>'''),
  ('retractation', 'Droit de rétractation (clients consommateurs)', '''          <p>Le client consommateur qui conclut un contrat à distance dispose d’un délai de 14 jours pour exercer son droit de rétractation, conformément au Code de la consommation.</p>
          <p>S’il demande expressément que la prestation commence avant la fin de ce délai, il reste redevable du montant correspondant au travail déjà réalisé.</p>'''),
  ('donnees', 'Données personnelles', '          <p>Le traitement des données personnelles est décrit dans la <a href="confidentialite.html">politique de confidentialité</a>.</p>'),
  ('litiges', 'Droit applicable et litiges', '''          <p>Les présentes conditions sont soumises au droit luxembourgeois. Les parties recherchent d’abord une solution amiable. À défaut, les tribunaux du Grand-Duché de Luxembourg sont compétents.</p>
          <p>Le client consommateur peut également recourir gratuitement au Service national du Médiateur de la consommation (<a href="https://www.mediateurconsommation.lu" rel="noopener">mediateurconsommation.lu</a>).</p>'''),
])

# ---------------------------------------------------------------- Page devis
def choice(value, name_key, name, desc_key, desc, price_key='', price=''):
    p = f'<span class="choice__price" data-i18n="{price_key}">{price}</span>' if price_key else ''
    return f'''              <label class="choice card">
                <input type="radio" name="type" value="{value}">
                <span class="choice__check">{CHECK}</span>
                <span class="choice__name" data-i18n="{name_key}">{name}</span>
                <span class="choice__desc" data-i18n="{desc_key}">{desc}</span>
                {p}
              </label>'''

def lang_pill(code, label, name, checked=False):
    c = ' checked' if checked else ''
    return f'<label class="pill-check" lang="{code}"><input type="checkbox" name="langs" value="{code}"{c}><span class="pill-check__code">{label}</span>{name}</label>'

def check(value, key, text):
    return f'<label class="check"><input type="checkbox" name="features" value="{value}"><span class="check__box">{CHECK}</span><span data-i18n="{key}">{text}</span></label>'

def chip(group, value, key, text, checked=False):
    c = ' checked' if checked else ''
    return f'<label class="chip"><input type="radio" name="{group}" value="{value}"{c}><span data-i18n="{key}">{text}</span></label>'

steps_ind = '\n'.join(
    f'          <li data-step-ind="{n}"><button type="button"><span class="steps__n">0{n}</span><span data-i18n="dv.s{n}">{lbl}</span></button></li>'
    for n, lbl in [(1, 'Projet'), (2, 'Langues'), (3, 'Options'), (4, 'Contact')])

hreflang = ''.join(f'<link rel="alternate" hreflang="{l}" href="https://dyroweb.online/{p}devis.html">\n'
                   for l, p in [('fr', ''), ('de', 'de/'), ('en', 'en/'), ('pt', 'pt/'), ('lb', 'lb/'), ('x-default', '')])
hreflang += '<link rel="canonical" href="https://dyroweb.online/devis.html">\n'

devis_shared = re.sub(r'\n      <a href="devis.html" class="btn btn--sm header__quote".*?</a>', '', shared, flags=re.S)
assert 'header__quote' not in devis_shared

hero = page_hero(
  '<p class="eyebrow intro" style="--d:0" data-i18n="dv.eyebrow">Demande de devis</p>',
  '<h1 class="page-hero__title intro" style="--d:1" data-i18n="dv.title">Votre projet en 2 minutes.</h1>',
  '      <p class="page-hero__lead intro" style="--d:2" data-i18n="dv.lead">Quatre questions et une fourchette de prix indicative. Vous recevez ensuite une proposition claire, en trois options.</p>')

devis = head('Demande de devis · DYRO WEB',
  'Demandez un devis pour votre site web : landing page, site vitrine, site multilingue ou boutique en ligne au Luxembourg. Estimation immédiate.',
  hreflang, 'dv.doctitle', url='devis.html') + devis_shared + f'''<main id="top" tabindex="-1">
{hero}
  <section class="quote">
    <div class="container quote__grid">
      <form class="quote__form" data-quote novalidate>
        <ol class="steps">
{steps_ind}
        </ol>

        <fieldset class="step-panel" data-step="1">
          <legend class="q-title" tabindex="-1" data-i18n="dv.q1">Quel type de site vous faut-il ?</legend>
          <div class="choice-grid" data-spotlight-group>
{choice('landing', 'price.1.h', 'Landing page', 'price.1.for', 'Pour lancer une offre ou une campagne.', 'price.1.range', '890 à 1 290 € HT selon le périmètre')}
{choice('pro', 'price.2.h', 'Site vitrine Pro', 'price.2.for', 'Pour présenter votre activité et rassurer.', 'price.2.range', '1 890 à 2 900 € HT selon le périmètre')}
{choice('premium', 'price.3.h', 'Premium multilingue', 'price.3.for', 'Pour viser tout le marché luxembourgeois.', 'price.3.range', '3 500 à 6 000 € HT selon le périmètre')}
{choice('ecommerce', 'price.x1.label', 'E-commerce', 'price.x1.note', 'Boutique en ligne, sur devis', 'price.x1.val', 'dès 4 500 € HT')}
{choice('unsure', 'dv.unsure', 'Je ne sais pas encore', 'dv.unsure.d', 'Nous vous conseillons la formule adaptée.')}
          </div>
          <p class="field-error" data-error="type" aria-live="polite"></p>
        </fieldset>

        <fieldset class="step-panel" data-step="2" hidden>
          <legend class="q-title" tabindex="-1" data-i18n="dv.q2">Dans quelles langues ?</legend>
          <div class="pills">
            {lang_pill('fr', 'FR', 'Français', True)}
            {lang_pill('de', 'DE', 'Deutsch')}
            {lang_pill('en', 'EN', 'English')}
            {lang_pill('pt', 'PT', 'Português')}
            {lang_pill('lb', 'LU', 'Lëtzebuergesch')}
          </div>
          <p class="q-hint" data-i18n="dv.q2.hint">Le Site vitrine Pro inclut le français et l’allemand. L’offre Premium couvre jusqu’à cinq langues. Les autres langues sont chiffrées dans le devis.</p>
          <p class="field-error" data-error="langs" aria-live="polite"></p>
        </fieldset>

        <fieldset class="step-panel" data-step="3" hidden>
          <legend class="q-title" tabindex="-1" data-i18n="dv.q3">Fonctionnalités souhaitées</legend>
          <div class="checks">
            {check('form', 'price.l.form', 'Formulaire de contact')}
            {check('booking', 'price.3.l3', 'Réservation en ligne')}
            {check('adv', 'price.3.l4', 'Formulaires avancés')}
            {check('pay', 'dv.f.pay', 'Paiement en ligne')}
            {check('seo', 'price.3.l2', 'SEO local')}
            {check('blog', 'dv.f.blog', 'Blog ou actualités')}
          </div>
          <p class="q-sub" data-i18n="dv.q3b">Maintenance et hébergement</p>
          <div class="chips" role="radiogroup" aria-label="Maintenance et hébergement" data-i18n-attr="aria-label:dv.q3b">
            {chip('maint', 'none', 'dv.m.none', 'Pas pour l’instant', True)}
            {chip('maint', 'ess', 'dv.m.ess', 'Essentiel · 49 € HT / mois')}
            {chip('maint', 'pro', 'dv.m.pro', 'Pro · 99 € HT / mois')}
            {chip('maint', 'gro', 'dv.m.gro', 'Croissance · 199 € HT / mois')}
          </div>
          <p class="q-sub" data-i18n="dv.q3c">Délai souhaité</p>
          <div class="chips" role="radiogroup" aria-label="Délai souhaité" data-i18n-attr="aria-label:dv.q3c">
            {chip('deadline', 'asap', 'dv.d.asap', 'Dès que possible')}
            {chip('deadline', 'soon', 'dv.d.soon', 'D’ici 1 à 2 mois')}
            {chip('deadline', 'later', 'dv.d.later', 'Dans 3 mois ou plus')}
            {chip('deadline', 'none', 'dv.d.none', 'Pas de date fixe')}
          </div>
          <div class="field field--site">
            <label for="q-site"><span data-i18n="dv.site">Adresse de votre site actuel</span> <span class="opt">(<span data-i18n="dv.optional">facultatif</span>)</span></label>
            <input id="q-site" name="site" type="url" inputmode="url" autocomplete="url" placeholder="https://">
          </div>
        </fieldset>

        <fieldset class="step-panel" data-step="4" hidden>
          <legend class="q-title" tabindex="-1" data-i18n="dv.q4">Vos coordonnées</legend>
          <div class="fields">
            <div class="field">
              <label for="q-name" data-i18n="dv.name">Nom et prénom</label>
              <input id="q-name" name="name" type="text" autocomplete="name" required>
              <p class="field-error" data-error="name" aria-live="polite"></p>
            </div>
            <div class="field">
              <label for="q-company"><span data-i18n="dv.company">Entreprise</span> <span class="opt">(<span data-i18n="dv.optional">facultatif</span>)</span></label>
              <input id="q-company" name="company" type="text" autocomplete="organization">
            </div>
            <div class="field">
              <label for="q-email" data-i18n="dv.email">Email</label>
              <input id="q-email" name="email" type="email" autocomplete="email" inputmode="email" required>
              <p class="field-error" data-error="email" aria-live="polite"></p>
            </div>
            <div class="field">
              <label for="q-phone"><span data-i18n="dv.phone">Téléphone</span> <span class="opt">(<span data-i18n="dv.optional">facultatif</span>)</span></label>
              <input id="q-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel">
            </div>
            <div class="field field--full">
              <label for="q-message" data-i18n="dv.msg">Votre activité et votre besoin</label>
              <textarea id="q-message" name="message" rows="5" placeholder="Ex. : restaurant à Esch-sur-Alzette, nous voulons un site avec réservation en ligne." data-i18n-attr="placeholder:dv.msg.ph"></textarea>
            </div>
          </div>
          <label class="consent">
            <input type="checkbox" name="consent" id="q-consent">
            <span class="consent__box">{CHECK}</span>
            <span data-i18n="dv.consent">J’accepte que mes données soient utilisées pour traiter ma demande, conformément à la <a href="confidentialite.html">politique de confidentialité</a>.</span>
          </label>
          <p class="field-error" data-error="consent" aria-live="polite"></p>
          <div class="hp" aria-hidden="true"><label>Site web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        </fieldset>

        <div class="quote__nav">
          <button type="button" class="btn btn--ghost" data-prev hidden><svg class="btn__arrow btn__arrow--back" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4"/></svg><span data-i18n="dv.prev">Retour</span></button>
          <button type="button" class="btn btn--chrome" data-next data-magnetic="0.2"><span data-i18n="dv.next">Continuer</span>{ICON_ARROW}</button>
          <button type="submit" class="btn btn--chrome" data-submit hidden data-magnetic="0.2"><span data-i18n="dv.send">Envoyer ma demande</span>{ICON_ARROW}</button>
        </div>

        <div class="quote__done" data-done hidden>
          <div class="done-mark" aria-hidden="true">{RING}<svg viewBox="0 0 16 16"><path d="m3.5 8.5 3 3 6-7" pathLength="1"/></svg></div>
          <h2 data-i18n="dv.ok.title">Merci, votre demande est envoyée.</h2>
          <p data-i18n="dv.ok.text">Nous revenons vers vous avec une proposition claire, en trois options.</p>
          <a href="./" class="btn btn--ghost"><span data-i18n="dv.home">Retour à l’accueil</span>{ICON_ARROW}</a>
        </div>

        <div class="quote__done" data-fallback hidden>
          <div class="done-mark" aria-hidden="true">{RING}<svg viewBox="0 0 16 16"><path d="m3.5 8.5 3 3 6-7" pathLength="1"/></svg></div>
          <h2 data-i18n="dv.fb.title">Dernière étape : envoyez votre demande par email.</h2>
          <p data-i18n="dv.fb.text">Votre messagerie s’ouvre avec la demande déjà rédigée. Si rien ne se passe, copiez le texte ci-dessous et envoyez-le à contact@dyroweb.online.</p>
          <textarea class="fb-text" data-fb-text readonly aria-label="Votre demande"></textarea>
          <div class="fb-actions">
            <button type="button" class="btn btn--ghost" data-fb-copy data-i18n="copy">Copier</button>
            <a class="btn btn--chrome" data-fb-mail href="mailto:contact@dyroweb.online"><span data-i18n="dv.fb.mail">Ouvrir ma messagerie</span>{ICON_ARROW}</a>
          </div>
        </div>
      </form>

      <aside class="summary" aria-live="polite">
        <div class="summary__ring" aria-hidden="true">{RING}</div>
        <p class="summary__h" data-i18n="dv.sum.title">Votre estimation</p>
        <p class="summary__label" data-i18n="dv.sum.range">Fourchette indicative</p>
        <p class="summary__range" data-sum-range>—</p>
        <p class="summary__sub" data-sum-sub>Choisissez un type de projet pour voir la fourchette.</p>
        <dl class="summary__rows">
          <dt data-i18n="dv.s1">Projet</dt><dd data-sum-type>—</dd>
          <dt data-i18n="dv.s2">Langues</dt><dd data-sum-langs>FR</dd>
          <dt data-i18n="dv.s3">Options</dt><dd data-sum-feat>—</dd>
          <dt data-i18n="dv.sum.monthly">Maintenance</dt><dd data-sum-maint>—</dd>
        </dl>
        <p class="summary__extra" data-sum-extra hidden></p>
        <p class="summary__note" data-i18n="dv.sum.note">Fourchette indicative selon notre grille, hors TVA. Le devis fixe le prix exact.</p>
        <p class="summary__book"><span data-i18n="book.alt">Vous préférez en parler ?</span><br><a href="{BOOK}" target="_blank" rel="noopener" data-i18n-attr="href:book.href"><span data-i18n="book.label">Réserver un appel de 20 min</span> <span aria-hidden="true">→</span></a></p>
      </aside>
    </div>
  </section>
</main>
''' + page_footer('', ['main.js', 'devis.js'], with_mbar=False)
open(ROOT + 'devis.html', 'w').write(devis)
print('pages ok')

# ---------------------------------------------------------------- Page 404
def absolute(html):
    for a in ['assets/', 'devis.html', 'mentions-legales.html', 'confidentialite.html', 'cgv.html']:
        html = html.replace(f'href="{a}', f'href="/{a}').replace(f'src="{a}', f'src="/{a}')
    return html.replace('href="./', 'href="/')

e404_hero = f"""  <section class="page-hero page-hero--404">
    <div class="page-hero__bg" aria-hidden="true">
      <div class="hero__grid"></div>
      <div class="page-hero__glow"></div>
      <div class="page-hero__ring">{RING}</div>
    </div>
    <div class="container">
      <p class="e404__code intro" style="--d:0" aria-hidden="true">404</p>
      <h1 class="page-hero__title intro" style="--d:1" data-i18n="e404.title">Page introuvable.</h1>
      <p class="page-hero__lead intro" style="--d:2" data-i18n="e404.text">Cette page n’existe pas ou a été déplacée.</p>
      <div class="e404__actions intro" style="--d:3">
        <a href="./" class="btn btn--chrome btn--lg" data-magnetic><span data-i18n="dv.home">Retour à l’accueil</span>{ICON_ARROW}</a>
        <a href="devis.html" class="btn btn--ghost btn--lg"><span data-i18n="cta.quote">Demander un devis</span></a>
      </div>
    </div>
  </section>
"""
page404 = head('Page introuvable · DYRO WEB', 'Cette page n’existe pas ou a été déplacée.', '', 'e404.doctitle', url=None, assets='/assets/', robots='noindex') + shared + f"""<main id="top" tabindex="-1">
{e404_hero}</main>
""" + page_footer('', ['main.js'], with_mbar=True, assets='/assets/')
open(ROOT + '404.html', 'w').write(absolute(page404))
print('404 ok')
