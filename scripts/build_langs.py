"""Génère les versions statiques par langue de l'accueil et de la page devis.

  /de/  /en/  /pt/  /lb/   (index.html et devis.html)

Les textes viennent de assets/js/i18n.js (clés data-i18n / data-i18n-attr du HTML).
Met aussi à jour les balises hreflang des pages françaises et le sitemap.xml.

Ordre : python3 scripts/build_pages.py  puis  python3 scripts/build_langs.py
"""
import html as htmllib
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, '..'))
SITE = 'https://dyroweb.online/'
LANGS = ['de', 'en', 'pt', 'lb']
ALL = ['fr'] + LANGS
PAGES = ['index.html', 'devis.html']
LEGAL = ['mentions-legales.html', 'confidentialite.html', 'cgv.html']
OG_LOCALE = {'fr': 'fr_FR', 'de': 'de_DE', 'en': 'en_GB', 'pt': 'pt_PT', 'lb': 'lb_LU'}
TITLE_KEY = {'index.html': 'meta.title', 'devis.html': 'dv.doctitle'}
TODAY = '2026-11-02'

src = open(os.path.join(ROOT, 'assets/js/i18n.js'), encoding='utf-8').read()
DICT = json.loads(src.split('window.DYRO_I18N = ', 1)[1].rstrip().rstrip(';'))
ANALYTICS = open(os.path.join(HERE, 'partials/analytics.html'), encoding='utf-8').read().rstrip('\n')


def url(lang, page):
    return SITE + ('' if lang == 'fr' else lang + '/') + ('' if page == 'index.html' else page)


def head_links(html, page, lang):
    """Remplace les balises hreflang/canonical et les infos de partage."""
    html = re.sub(r'<link rel="alternate" hreflang="[^"]+" href="[^"]*">\n', '', html)
    html = re.sub(r'<link rel="canonical" href="[^"]*">\n', '', html)
    links = ''.join(f'<link rel="alternate" hreflang="{l}" href="{url(l, page)}">\n' for l in ALL)
    links += f'<link rel="alternate" hreflang="x-default" href="{url("fr", page)}">\n'
    links += f'<link rel="canonical" href="{url(lang, page)}">\n'
    html = html.replace('<meta name="theme-color" content="#03050a">\n', '<meta name="theme-color" content="#03050a">\n' + links, 1)
    html = re.sub(r'<meta property="og:url" content="[^"]*">', f'<meta property="og:url" content="{url(lang, page)}">', html)
    html = re.sub(r'<meta property="og:locale" content="[^"]*">', f'<meta property="og:locale" content="{OG_LOCALE[lang]}">', html)
    html = re.sub(r'<meta property="og:locale:alternate" content="[^"]*">\n', '', html)
    alts = ''.join(f'<meta property="og:locale:alternate" content="{OG_LOCALE[l]}">\n' for l in ALL if l != lang)
    html = re.sub(r'(<meta property="og:locale" content="[^"]*">\n)', lambda m: m.group(1) + alts, html, count=1)
    # Bloc mesure d'audience à jour
    html = re.sub(r'<!-- analytics:start -->.*?<!-- analytics:end -->', lambda m: '<!-- analytics:start -->\n' + ANALYTICS + '\n<!-- analytics:end -->', html, flags=re.S)
    return html


def element_end(html, tag, start):
    """Position de la balise fermante correspondant à <tag> ouverte avant `start`."""
    depth = 1
    pat = re.compile(r'<(/?)' + tag + r'(?=[\s>/])', re.I)
    for m in pat.finditer(html, start):
        if m.group(1):
            depth -= 1
            if depth == 0:
                return m.start()
        else:
            depth += 1
    raise ValueError(f'balise </{tag}> introuvable')


def translate(html, lang):
    d = DICT[lang]
    swoosh = re.search(r'<svg class="swoosh".*?</svg>', html, re.S)
    swoosh = swoosh.group(0) if swoosh else ''
    missing = set()

    # Contenu des éléments data-i18n
    start_re = re.compile(r'<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\sdata-i18n="([^"]+)"[^>]*>')
    pos = 0
    out = []
    while True:
        m = start_re.search(html, pos)
        if not m:
            out.append(html[pos:])
            break
        tag, key = m.group(1), m.group(2)
        inner_start = m.end()
        inner_end = element_end(html, tag, inner_start)
        value = d.get(key)
        if value is None:
            missing.add(key)
            value = html[inner_start:inner_end]
        elif 'data-split-hero' in m.group(0):
            value = re.sub(r'\[\[(.+?)\]\]([.,!?…]*)', lambda x: f'<span class="nowrap"><span class="hl hl--swoosh">{x.group(1)}{swoosh}</span>{x.group(2)}</span>', value)
        out.append(html[pos:inner_start] + value)
        pos = inner_end
    html = ''.join(out)

    # Attributs data-i18n-attr="attr:clé;attr2:clé2"
    def fix_tag(m):
        tag = m.group(0)
        spec = re.search(r'data-i18n-attr="([^"]+)"', tag).group(1)
        for pair in spec.split(';'):
            attr, _, key = pair.partition(':')
            attr, key = attr.strip(), key.strip()
            if key not in d:
                missing.add(key)
                continue
            val = htmllib.escape(d[key], quote=True).replace('&amp;', '&') if attr != 'href' else d[key].replace('"', '%22')
            if re.search(r'\s' + re.escape(attr) + r'="', tag):
                tag = re.sub(r'(\s' + re.escape(attr) + r'=")[^"]*(")', lambda x: x.group(1) + val + x.group(2), tag, count=1)
        return tag
    html = re.sub(r'<[a-zA-Z][^>]*\sdata-i18n-attr="[^"]+"[^>]*>', fix_tag, html)
    html = html.replace('<html lang="fr">', f'<html lang="{lang}">', 1)
    return html, missing


def relink(html, lang):
    """Liens absolus pour les pages placées dans /xx/."""
    html = html.replace('href="assets/', 'href="/assets/').replace('src="assets/', 'src="/assets/')
    html = html.replace("el.src = 'assets/", "el.src = '/assets/")
    html = re.sub(r'href="devis\.html', f'href="/{lang}/devis.html', html)
    html = re.sub(r'href="\./', f'href="/{lang}/', html)
    for page in LEGAL:
        html = html.replace(f'href="{page}', f'href="/{page}')
    return html


def og_texts(html, page, lang):
    title = DICT[lang].get(TITLE_KEY[page])
    if title:
        html = re.sub(r'<meta property="og:title" content="[^"]*">', f'<meta property="og:title" content="{htmllib.escape(title, quote=True)}">', html)
    desc = DICT[lang].get('meta.desc') if page == 'index.html' else DICT[lang].get('dv.lead')
    if desc:
        html = re.sub(r'<meta property="og:description" content="[^"]*">', f'<meta property="og:description" content="{htmllib.escape(desc, quote=True)}">', html)
        if page == 'devis.html':
            html = re.sub(r'<meta name="description" content="[^"]*">', f'<meta name="description" content="{htmllib.escape(desc, quote=True)}">', html)
    return html


def main():
    report = {}
    for page in PAGES:
        path = os.path.join(ROOT, page)
        fr = open(path, encoding='utf-8').read()
        fr = head_links(fr, page, 'fr')
        open(path, 'w', encoding='utf-8').write(fr)
        for lang in LANGS:
            html, missing = translate(fr, lang)
            html = head_links(html, page, lang)
            html = og_texts(html, page, lang)
            html = relink(html, lang)
            os.makedirs(os.path.join(ROOT, lang), exist_ok=True)
            open(os.path.join(ROOT, lang, page), 'w', encoding='utf-8').write(html)
            report[f'{lang}/{page}'] = sorted(missing)

    # sitemap.xml
    xml = '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n'
    for page, prio in [('index.html', '1.0'), ('devis.html', '0.8')]:
        alts = ''.join(f'    <xhtml:link rel="alternate" hreflang="{l}" href="{url(l, page)}"/>\n' for l in ALL)
        alts += f'    <xhtml:link rel="alternate" hreflang="x-default" href="{url("fr", page)}"/>\n'
        for lang in ALL:
            xml += f'  <url>\n    <loc>{url(lang, page)}</loc>\n{alts}    <lastmod>{TODAY}</lastmod>\n    <priority>{prio}</priority>\n  </url>\n'
    for page in LEGAL:
        xml += f'  <url>\n    <loc>{SITE}{page}</loc>\n    <lastmod>{TODAY}</lastmod>\n    <priority>0.3</priority>\n  </url>\n'
    xml += '</urlset>\n'
    open(os.path.join(ROOT, 'sitemap.xml'), 'w', encoding='utf-8').write(xml)

    for k, v in report.items():
        print(f'{k}: ' + ('ok' if not v else 'clés manquantes ' + ', '.join(v)))


if __name__ == '__main__':
    main()
