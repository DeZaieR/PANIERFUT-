"""Pages « Sites par métier » (5 langues), articles « Conseils » (français), FAQ structurée.

  /site-internet/            + /de/ /en/ /pt/ /lb/site-internet/   (contenu : scripts/data/metiers.py)
  /blog/                     articles en français                 (contenu : scripts/data/blog.py)
  FAQPage (données structurées) sur chaque accueil, à partir de la FAQ affichée.
  Ajoute ces adresses au sitemap.xml.

L'en-tête, le menu et le footer sont repris des pages audit déjà générées.
Ordre : build_pages.py  puis  build_langs.py  puis  build_extra.py
"""
import html as H
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, '..'))
sys.path.insert(0, os.path.join(HERE, 'data'))
import metiers as M  # noqa: E402
import blog as B  # noqa: E402

SITE = 'https://dyroweb.online'
LANGS = ['fr', 'de', 'en', 'pt', 'lb']
OG_LOCALE = {'fr': 'fr_LU', 'de': 'de_LU', 'en': 'en_GB', 'pt': 'pt_PT', 'lb': 'lb_LU'}
TODAY = '2026-11-07'
BOOK = 'https://cal.com/dyroweb/appel-web'
ARROW = '<svg class="btn__arrow" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4"/></svg>'
RING = '<div class="ring"><div class="ring__comet"><i></i></div></div>'

src = open(os.path.join(ROOT, 'assets/js/i18n.js'), encoding='utf-8').read()
DICT = json.loads(src.split('window.DYRO_I18N = ', 1)[1].rstrip().rstrip(';'))
DICT['fr'] = {'book.label': 'Réserver un appel de 20 min', 'foot.sister.href': 'https://dyrostudios.com/'}

T = {  # petits textes propres à ces pages
 'crumbs': {'fr': 'Fil d’Ariane', 'de': 'Sie sind hier', 'en': 'Breadcrumb', 'pt': 'Está aqui', 'lb': 'Dir sidd hei'},
 'promise': {'fr': 'Première réponse sous 24 h · devis gratuit sous 48 h', 'de': 'Erste Antwort in 24 Std. · kostenloses Angebot in 48 Std.',
             'en': 'First reply within 24 h · free quote within 48 h', 'pt': 'Primeira resposta em 24 h · orçamento grátis em 48 h',
             'lb': 'Éischt Äntwert bannent 24 St. · gratis Offer bannent 48 St.'},
 'cta': {'fr': 'Un devis clair, sous 48 h.', 'de': 'Ein klares Angebot, in 48 Stunden.', 'en': 'A clear quote, within 48 hours.',
         'pt': 'Um orçamento claro, em 48 horas.', 'lb': 'Eng kloer Offer, bannent 48 Stonnen.'},
 'others': {'fr': 'Autres métiers', 'de': 'Weitere Branchen', 'en': 'Other trades', 'pt': 'Outras profissões', 'lb': 'Aner Beruffer'},
 'studios': {'fr': 'Découvrir DYRO STUDIOS', 'de': 'DYRO STUDIOS entdecken', 'en': 'Discover DYRO STUDIOS', 'pt': 'Conhecer a DYRO STUDIOS', 'lb': 'DYRO STUDIOS entdecken'},
 'service': {'fr': 'Création de site internet', 'de': 'Website-Erstellung', 'en': 'Website design', 'pt': 'Criação de sites', 'lb': 'Websäit-Kreatioun'},
}
def typo(s):
    """Espaces insécables : 1 890 €, 48 h, « … », avant ? ! : ; (évite les coupures de ligne mal placées)."""
    s = re.sub(r'(?<=\d) (?=\d{3}(?!\d))', '\u202f', s)
    s = re.sub(r'(?<=\d) (?=(?:€|%|h\b|min\b|St\.|Std\.|Stonnen|Stunden|horas|hours))', '\u00a0', s)
    s = s.replace('€ HT', '€\u00a0HT').replace(' ?', '\u202f?').replace(' !', '\u202f!').replace(' :', '\u00a0:').replace(' ;', '\u202f;')
    return s.replace('« ', '«\u00a0').replace(' »', '\u00a0»')


esc = lambda s: typo(H.escape(s, quote=False))
attr = lambda s: H.escape(s, quote=True)
cap = lambda s: s[:1].upper() + s[1:]
pre = lambda lang: '/' if lang == 'fr' else f'/{lang}/'


# ---------------------------------------------------------------- Gabarit (en-tête et footer des pages audit)
def shell(lang):
    s = open(os.path.join(ROOT, 'audit.html' if lang == 'fr' else f'{lang}/audit.html'), encoding='utf-8').read()
    head, rest = s.split('<main id="top"', 1)
    tail = rest.split('</main>', 1)[1]
    tail = re.sub(r'<script src="[^"]*audit\.js[^"]*" defer></script>\n', '', tail)
    assert 'audit.js' not in tail
    if lang == 'fr':  # pages placées dans un sous-dossier : liens absolus
        def absolute(x):
            x = x.replace('href="assets/', 'href="/assets/').replace('src="assets/', 'src="/assets/').replace("el.src = 'assets/", "el.src = '/assets/")
            for a in ['devis.html', 'audit.html', 'site-internet/', 'mentions-legales.html', 'confidentialite.html', 'cgv.html']:
                x = x.replace(f'href="{a}', f'href="/{a}')
            return x.replace('href="./', 'href="/')
        head, tail = absolute(head), absolute(tail)
    assert 'href="site-internet/' not in head + tail and 'href="./' not in head + tail
    return head, tail


def set_head(head, lang, title, desc, path, alts, ld, og_type='website', fallback=False):
    """alts : {langue: chemin} des versions équivalentes (hreflang)."""
    head = re.sub(r'<title[^>]*>.*?</title>', f'<title>{esc(title)}</title>', head, count=1, flags=re.S)
    head = re.sub(r'<meta name="description" content="[^"]*">', f'<meta name="description" content="{attr(desc)}">', head, count=1)
    head = re.sub(r'<link rel="alternate" hreflang="[^"]+" href="[^"]*">\n', '', head)
    head = re.sub(r'<link rel="canonical" href="[^"]*">\n', '', head)
    links = ''.join(f'<link rel="alternate" hreflang="{l}" href="{SITE}{p}">\n' for l, p in alts.items())
    if 'fr' in alts and len(alts) > 1:
        links += f'<link rel="alternate" hreflang="x-default" href="{SITE}{alts["fr"]}">\n'
    links += f'<link rel="canonical" href="{SITE}{path}">\n'
    if fallback:  # pas de traduction : le sélecteur de langue mène à l'accueil de la langue choisie
        links += '<meta name="dyro:lang-fallback" content="home">\n'
    head = head.replace('<meta name="theme-color" content="#03050a">\n', '<meta name="theme-color" content="#03050a">\n' + links, 1)
    head = re.sub(r'<meta property="og:type" content="[^"]*">', f'<meta property="og:type" content="{og_type}">', head, count=1)
    head = re.sub(r'<meta property="og:title" content="[^"]*">', f'<meta property="og:title" content="{attr(title)}">', head, count=1)
    head = re.sub(r'<meta property="og:description" content="[^"]*">', f'<meta property="og:description" content="{attr(desc)}">', head, count=1)
    head = re.sub(r'<meta property="og:url" content="[^"]*">', f'<meta property="og:url" content="{SITE}{path}">', head, count=1)
    if len(alts) == 1:
        head = re.sub(r'<meta property="og:locale:alternate" content="[^"]*">\n', '', head)
    else:
        head = re.sub(r'<meta property="og:locale:alternate" content="[^"]*">\n', '', head)
        extra = ''.join(f'<meta property="og:locale:alternate" content="{OG_LOCALE[l]}">\n' for l in alts if l != lang)
        head = re.sub(r'(<meta property="og:locale" content="[^"]*">\n)', lambda m: m.group(1) + extra, head, count=1)
    data = json.dumps(ld, ensure_ascii=False, indent=1).replace('</', '<\\/')
    head = head.replace('</head>', f'<script type="application/ld+json">\n{data}\n</script>\n</head>', 1)
    assert head.count('<link rel="canonical"') == 1
    return head


def hero(crumbs, eyebrow, h1, lead, actions, promise, lang, long=True):
    c = '<i aria-hidden="true">/</i>'.join(f'<a href="{h}">{esc(t)}</a>' if h else f'<span aria-current="page">{esc(t)}</span>' for t, h in crumbs)
    return f'''  <section class="page-hero">
    <div class="page-hero__bg" aria-hidden="true">
      <div class="hero__grid"></div>
      <div class="page-hero__glow"></div>
      <div class="page-hero__ring">{RING}</div>
    </div>
    <div class="container">
      <nav class="crumbs intro" style="--d:0" aria-label="{attr(T['crumbs'][lang])}">{c}</nav>
      <p class="eyebrow intro" style="--d:1">{esc(eyebrow)}</p>
      <h1 class="page-hero__title{' page-hero__title--long' if long else ''} intro" style="--d:2">{esc(h1)}</h1>
      <p class="page-hero__lead intro" style="--d:3">{esc(lead)}</p>
{actions}{f'      <p class="page-hero__promise intro" style="--d:5">{esc(promise)}</p>' if promise else ''}
    </div>
  </section>
'''


def actions(lang, d=4):
    u = M.UI[lang]
    return (f'      <div class="page-hero__actions intro" style="--d:{d}">'
            f'<a class="btn btn--chrome btn--lg" href="{pre(lang)}devis.html" data-magnetic="0.2"><span>{esc(u["cta_quote"])}</span>{ARROW}</a>'
            f'<a class="btn btn--ghost btn--lg" href="{pre(lang)}audit.html"><span>{esc(u["cta_audit"])}</span></a></div>\n')


def cta_card(lang):
    u = M.UI[lang]
    return f'''      <div class="card tcta" data-reveal>
        <div><h2>{esc(T['cta'][lang])}</h2><p>{esc(u['note'])}</p></div>
        <div class="tcta__actions"><a class="btn btn--chrome btn--lg" href="{pre(lang)}devis.html"><span>{esc(u['cta_quote'])}</span>{ARROW}</a><a class="btn btn--ghost btn--lg" href="{BOOK}" data-cal="dyroweb/appel-web" target="_blank" rel="noopener" data-ev="book"><span>{esc(DICT[lang]['book.label'])}</span></a></div>
      </div>
'''


def faq_html(items, prefix):
    out = ''
    for i, (q, a) in enumerate(items, 1):
        out += f'''        <div class="faq__item">
          <h3><button class="faq__q" type="button" aria-expanded="false" aria-controls="{prefix}-a{i}" id="{prefix}-q{i}"><span>{esc(q)}</span><span class="faq__icon" aria-hidden="true"></span></button></h3>
          <div class="faq__a" id="{prefix}-a{i}" role="region" aria-labelledby="{prefix}-q{i}"><div><p>{esc(a)}</p></div></div>
        </div>
'''
    return out


def faq_ld(items):
    strip = lambda s: re.sub(r'\s+', ' ', H.unescape(re.sub(r'<[^>]+>', '', s))).strip()
    return {'@type': 'FAQPage', 'mainEntity': [{'@type': 'Question', 'name': strip(q), 'acceptedAnswer': {'@type': 'Answer', 'text': strip(a)}} for q, a in items]}


def crumbs_ld(items):
    return {'@type': 'BreadcrumbList', 'itemListElement': [{'@type': 'ListItem', 'position': i, 'name': n, 'item': SITE + p} for i, (n, p) in enumerate(items, 1)]}


def write(rel, html):
    path = os.path.join(ROOT, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(html)


ORG = {'@type': 'ProfessionalService', 'name': 'DYRO WEB', 'url': SITE + '/'}
SITEMAP = []  # (chemin, {langue: chemin} ou None, priorité)


# ---------------------------------------------------------------- Pages métier
def build_metiers():
    alts_hub = {l: f'{pre(l)}site-internet/' for l in LANGS}
    for lang in LANGS:
        head0, tail = shell(lang)
        u, P = M.UI[lang], M.P[lang]
        hub = f'{pre(lang)}site-internet/'
        for slug in M.SLUGS:
            p = P[slug]
            path = f'{hub}{slug}.html'
            alts = {l: f'{pre(l)}site-internet/{slug}.html' for l in LANGS}
            needs = ''.join(f'        <article class="card need" data-reveal style="--i:{i}"><span class="need__n">0{i + 1}</span><h3>{esc(t)}</h3><p>{esc(d)}</p></article>\n' for i, (t, d) in enumerate(p['needs']))
            checks = ''.join(f'<li>{esc(f)}</li>' for f in p['features'])
            extra = ''
            if p['extra']:
                href = DICT[lang]['foot.sister.href'] + '?utm_source=dyroweb&utm_medium=referral&utm_campaign=metier'
                extra = f'<p class="advice__extra">{esc(p["extra"])} <a href="{attr(href)}" target="_blank" rel="noopener">{esc(T["studios"][lang])} →</a></p>'
            others = ''.join(f'<a href="{hub}{s}.html">{esc(cap(P[s]["name"]))}</a>' for s in M.SLUGS if s != slug) + f'<a href="{hub}">{esc(u["see_all"])} →</a>'
            main = '<main id="top" tabindex="-1">\n' + hero(
                [('DYRO WEB', pre(lang)), (u['crumb'], hub), (cap(p['name']), None)],
                f'{u["eyebrow"]} {p["name"]}', p['h1'], p['lead'], actions(lang), T['promise'][lang], lang) + f'''
  <section class="tsec">
    <div class="container">
      <h2 class="tsec__h" data-reveal>{esc(u['needs'])}</h2>
      <div class="needs" data-spotlight-group>
{needs}      </div>
    </div>
  </section>

  <section class="tsec">
    <div class="container">
      <h2 class="tsec__h" data-reveal>{esc(u['features'])}</h2>
      <div class="tfeat">
        <ul class="checks" data-reveal>{checks}</ul>
        <aside class="card advice" data-reveal><p class="eyebrow">{esc(u['advice'])}</p><p>{esc(p['advice'])}</p><p class="advice__note">{esc(u['note'])}</p>{extra}</aside>
      </div>
    </div>
  </section>

  <section class="tsec">
    <div class="container">
      <h2 class="tsec__h" data-reveal>{esc(u['faq'])}</h2>
      <div class="faq__list" data-reveal>
{faq_html(p['faq'], 'mq')}      </div>
    </div>
  </section>

  <section class="tsec">
    <div class="container">
{cta_card(lang)}      <h2 class="tsec__h" style="margin-top:56px;font-size:clamp(20px,2vw,26px)">{esc(T['others'][lang])}</h2>
      <nav class="others" aria-label="{attr(T['others'][lang])}">{others}</nav>
    </div>
  </section>
</main>'''
            ld = {'@context': 'https://schema.org', '@graph': [
                {'@type': 'Service', 'name': p['title'].split(' · ')[0], 'serviceType': T['service'][lang], 'description': p['desc'],
                 'url': SITE + path, 'inLanguage': lang, 'provider': ORG, 'areaServed': {'@type': 'Country', 'name': 'Luxembourg'}},
                crumbs_ld([('DYRO WEB', pre(lang)), (u['crumb'], hub), (cap(p['name']), path)]),
                faq_ld(p['faq'])]}
            write(path.lstrip('/'), set_head(head0, lang, p['title'], p['desc'], path, alts, ld) + main + tail)
            if lang == 'fr':
                SITEMAP.append((slug, alts, '0.6'))

        # Page d'accueil des métiers
        cards = ''.join(f'''        <a class="card tcard" href="{hub}{s}.html" data-reveal style="--i:{i}"><h2>{esc(cap(P[s]['name']))}</h2><p>{esc(P[s]['card'])}</p><span class="tcard__go">{esc(u['more'])} →</span></a>
''' for i, s in enumerate(M.SLUGS))
        main = '<main id="top" tabindex="-1">\n' + hero(
            [('DYRO WEB', pre(lang)), (u['crumb'], None)], u['hub_eyebrow'], u['hub_h1'], u['hub_lead'], actions(lang), T['promise'][lang], lang) + f'''
  <section class="tsec">
    <div class="container">
      <div class="tgrid" data-spotlight-group>
{cards}      </div>
      <p class="tnote" data-reveal>{esc(u['hub_other'])} <a class="banner__link" href="{pre(lang)}devis.html">{esc(u['cta_quote'])} →</a></p>
    </div>
  </section>

  <section class="tsec">
    <div class="container">
{cta_card(lang)}    </div>
  </section>
</main>'''
        ld = {'@context': 'https://schema.org', '@graph': [
            {'@type': 'CollectionPage', 'name': u['hub_title'].split(' · ')[0], 'url': SITE + hub, 'inLanguage': lang, 'publisher': ORG,
             'hasPart': [{'@type': 'WebPage', 'name': P[s]['title'].split(' · ')[0], 'url': f'{SITE}{hub}{s}.html'} for s in M.SLUGS]},
            crumbs_ld([('DYRO WEB', pre(lang)), (u['crumb'], hub)])]}
        write(hub.lstrip('/') + 'index.html', set_head(head0, lang, u['hub_title'], u['hub_desc'], hub, alts_hub, ld) + main + tail)
    SITEMAP.insert(0, ('', alts_hub, '0.7'))


# ---------------------------------------------------------------- Conseils (français)
def build_blog():
    head0, tail = shell('fr')
    links = {'__DEVIS__': '/devis.html', '__AUDIT__': '/audit.html', '__FAQ__': '/#faq', '__METIERS__': '/site-internet/'}
    meta = lambda p: f'{p["date_txt"]} · {p["read"]} min de lecture'
    for p in B.POSTS:
        path = f'/blog/{p["slug"]}.html'
        body = p['body']
        for k, v in links.items():
            body = body.replace(k, v)
        assert '__' not in body
        body = re.sub(r'>([^<]+)<', lambda m: '>' + typo(m.group(1)) + '<', body)
        more = ''.join(f'''        <a class="card tcard" href="/blog/{o['slug']}.html" data-reveal style="--i:{i}"><span class="tcard__meta">{esc(meta(o))}</span><h3>{esc(o['h1'])}</h3><p>{esc(o['card'])}</p><span class="tcard__go">Lire l’article →</span></a>
''' for i, o in enumerate(x for x in B.POSTS if x is not p))
        main = '<main id="top" tabindex="-1">\n' + hero(
            [('DYRO WEB', '/'), ('Conseils', '/blog/'), (p['h1'].split(' ?')[0].split(' :')[0], None)],
            'Conseils', p['h1'], p['lead'], '', meta(p), 'fr') + f'''
  <article class="article">
    <div class="container">
      <div class="prose">
{body.strip()}
      </div>
    </div>
  </article>

  <section class="tsec">
    <div class="container">
      <h2 class="tsec__h" data-reveal style="font-size:clamp(22px,2.4vw,30px)">À lire aussi</h2>
      <div class="tgrid" style="margin-top:26px" data-spotlight-group>
{more}        <a class="card tcard" href="/site-internet/" data-reveal style="--i:2"><span class="tcard__meta">Sites par métier</span><h3>Un site pensé pour votre métier</h3><p>Agence immobilière, artisan, restaurant, cabinet médical, commerce : ce qu’il faut sur votre site, et à quel prix.</p><span class="tcard__go">Voir les métiers →</span></a>
      </div>
    </div>
  </section>
</main>'''
        ld = {'@context': 'https://schema.org', '@graph': [
            {'@type': 'BlogPosting', 'headline': p['h1'], 'description': p['desc'], 'datePublished': p['date'], 'dateModified': p['date'],
             'inLanguage': 'fr', 'url': SITE + path, 'mainEntityOfPage': SITE + path, 'image': SITE + '/assets/img/og-image.jpg',
             'author': ORG, 'publisher': {**ORG, 'logo': {'@type': 'ImageObject', 'url': SITE + '/assets/img/apple-touch-icon.png'}}},
            crumbs_ld([('DYRO WEB', '/'), ('Conseils', '/blog/'), (p['h1'], path)])]}
        write(path.lstrip('/'), set_head(head0, 'fr', p['title'], p['desc'], path, {'fr': path}, ld, og_type='article', fallback=True) + main + tail)
        SITEMAP.append(('blog:' + p['slug'], None, '0.5'))

    cards = ''.join(f'''        <a class="card tcard" href="/blog/{p['slug']}.html" data-reveal style="--i:{i}"><span class="tcard__meta">{esc(meta(p))}</span><h2>{esc(p['h1'])}</h2><p>{esc(p['card'])}</p><span class="tcard__go">Lire l’article →</span></a>
''' for i, p in enumerate(B.POSTS))
    h = B.HUB
    main = '<main id="top" tabindex="-1">\n' + hero([('DYRO WEB', '/'), ('Conseils', None)], h['eyebrow'], h['h1'], h['lead'], actions('fr'), '', 'fr') + f'''
  <section class="tsec">
    <div class="container">
      <div class="tgrid" data-spotlight-group>
{cards}      </div>
    </div>
  </section>
</main>'''
    ld = {'@context': 'https://schema.org', '@graph': [
        {'@type': 'Blog', 'name': 'Conseils DYRO WEB', 'url': SITE + '/blog/', 'inLanguage': 'fr', 'publisher': ORG,
         'blogPost': [{'@type': 'BlogPosting', 'headline': p['h1'], 'url': f'{SITE}/blog/{p["slug"]}.html', 'datePublished': p['date']} for p in B.POSTS]},
        crumbs_ld([('DYRO WEB', '/'), ('Conseils', '/blog/')])]}
    write('blog/index.html', set_head(head0, 'fr', h['title'], h['desc'], '/blog/', {'fr': '/blog/'}, ld, fallback=True) + main + tail)
    SITEMAP.append(('blog:', None, '0.5'))


# ---------------------------------------------------------------- FAQ structurée sur les accueils
def build_faq_ld():
    for lang in LANGS:
        rel = 'index.html' if lang == 'fr' else f'{lang}/index.html'
        s = open(os.path.join(ROOT, rel), encoding='utf-8').read()
        sec = s[s.index('id="faq"'):]
        sec = sec[:sec.index('</section>')]
        qs = re.findall(r'<span data-i18n="faq\.q\d+">(.*?)</span>', sec, re.S)
        as_ = re.findall(r'<p data-i18n="faq\.a\d+">(.*?)</p>', sec, re.S)
        assert len(qs) == len(as_) >= 10, (rel, len(qs), len(as_))
        ld = {'@context': 'https://schema.org', **faq_ld(list(zip(qs, as_)))}
        block = '<script type="application/ld+json" id="ld-faq">\n' + json.dumps(ld, ensure_ascii=False, indent=1).replace('</', '<\\/') + '\n</script>\n'
        s = re.sub(r'<script type="application/ld\+json" id="ld-faq">.*?</script>\n', '', s, flags=re.S)
        s = s.replace('</head>', block + '</head>', 1)
        open(os.path.join(ROOT, rel), 'w', encoding='utf-8').write(s)


# ---------------------------------------------------------------- sitemap.xml
def build_sitemap():
    path = os.path.join(ROOT, 'sitemap.xml')
    xml = open(path, encoding='utf-8').read()
    xml = re.sub(r'  <url>\n    <loc>https://dyroweb\.online/(?:[a-z]{2}/)?(?:site-internet|blog)/.*?</url>\n', '', xml, flags=re.S)
    add = ''
    for key, alts, prio in SITEMAP:
        if alts:
            al = ''.join(f'    <xhtml:link rel="alternate" hreflang="{l}" href="{SITE}{p}"/>\n' for l, p in alts.items())
            al += f'    <xhtml:link rel="alternate" hreflang="x-default" href="{SITE}{alts["fr"]}"/>\n'
            for l in LANGS:
                add += f'  <url>\n    <loc>{SITE}{alts[l]}</loc>\n{al}    <lastmod>{TODAY}</lastmod>\n    <priority>{prio}</priority>\n  </url>\n'
        else:
            slug = key.split(':', 1)[1]
            add += f'  <url>\n    <loc>{SITE}/blog/{slug + ".html" if slug else ""}</loc>\n    <lastmod>{TODAY}</lastmod>\n    <priority>{prio}</priority>\n  </url>\n'
    xml = xml.replace('</urlset>', add + '</urlset>')
    open(path, 'w', encoding='utf-8').write(xml)
    print('sitemap :', xml.count('<loc>'), 'adresses')


if __name__ == '__main__':
    build_metiers()
    build_blog()
    build_faq_ld()
    build_sitemap()
    print('métiers :', len(M.SLUGS) + 1, 'pages × 5 langues · conseils :', len(B.POSTS) + 1, 'pages')
