#!/usr/bin/env python3
"""Génère le site statique DYRO STUDIOS (FR, DE, EN) à partir de src/.

Syntaxe des gabarits (src/pages, src/partials) :
  {{> header}}              insère src/partials/header.html
  {{cle::Texte français}}   texte traduit (FR écrit dans le gabarit, DE/EN dans src/i18n/*.json)
  {{@url}} / {{@url contact/}}   lien vers une page dans la langue courante
  {{@lang}} {{@LANG}} {{@locale}} {{@hreflang}} {{@langlinks}} {{@canonical}}

Usage :
  python3 scripts/build.py          génère le site
  python3 scripts/build.py --keys   exporte les textes FR (src/i18n/_fr.json) pour la traduction
"""
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / 'src'
SITE = 'https://dyrostudios.com'
LANGS = ['fr', 'de', 'en']
NAMES = {'fr': 'Français', 'de': 'Deutsch', 'en': 'English'}
LOCALES = {'fr': 'fr_LU', 'de': 'de_LU', 'en': 'en_GB'}

# gabarit -> dossier de sortie, langues
PAGES = [
    ('index.html', '', LANGS),
    ('contact.html', 'contact/', LANGS),
    ('publicites.html', 'publicites/', LANGS),
    ('mentions-legales.html', 'mentions-legales/', ['fr']),
    ('confidentialite.html', 'confidentialite/', ['fr']),
]

KEY_RE = re.compile(r'\{\{([a-z0-9][a-z0-9_.\-]*)::(.*?)\}\}', re.S)
INC_RE = re.compile(r'\{\{>\s*([a-z0-9_\-]+)\s*\}\}')
URL_RE = re.compile(r'\{\{@url(?:\s+([^}\s]*))?\}\}')


def prefix(lang):
    return '/' if lang == 'fr' else f'/{lang}/'


def include(text, depth=0):
    if depth > 5:
        raise SystemExit('Inclusions trop profondes')
    return INC_RE.sub(lambda m: include((SRC / 'partials' / f'{m.group(1)}.html').read_text('utf-8'), depth + 1), text)


def load_dicts():
    d = {}
    for lang in LANGS[1:]:
        p = SRC / 'i18n' / f'{lang}.json'
        d[lang] = json.loads(p.read_text('utf-8')) if p.exists() else {}
    return d


def collect_fr(texts):
    fr = {}
    for name, text in texts:
        for k, v in KEY_RE.findall(text):
            v = v.strip()
            if k in fr and fr[k] != v:
                raise SystemExit(f'Clé « {k} » utilisée avec deux textes FR différents ({name}).')
            fr[k] = v
    return fr


def render(text, lang, slug, langs, dicts, missing):
    def tr(m):
        k, fr = m.group(1), m.group(2).strip()
        if lang == 'fr':
            return fr
        v = dicts[lang].get(k)
        if v is None:
            missing.setdefault(lang, set()).add(k)
            return fr
        return v

    text = KEY_RE.sub(tr, text)
    text = URL_RE.sub(lambda m: prefix(lang) + (m.group(1) or ''), text)
    canonical = SITE + prefix(lang) + slug
    alts = []
    if len(langs) > 1:
        for l in langs:
            alts.append(f'<link rel="alternate" hreflang="{l}" href="{SITE}{prefix(l)}{slug}">')
        alts.append(f'<link rel="alternate" hreflang="x-default" href="{SITE}/{slug}">')
    links = []
    for l in LANGS:
        href = prefix(l) + (slug if l in langs else '')
        cur = ' aria-current="true"' if l == lang else ''
        links.append(f'<li><a href="{href}" hreflang="{l}" lang="{l}"{cur}><b>{l.upper()}</b>{NAMES[l]}</a></li>')
    rep = {
        '{{@lang}}': lang,
        '{{@LANG}}': lang.upper(),
        '{{@locale}}': LOCALES[lang],
        '{{@canonical}}': canonical,
        '{{@hreflang}}': '\n'.join([f'<link rel="canonical" href="{canonical}">'] + alts),
        '{{@langlinks}}': ''.join(links),
    }
    for a, b in rep.items():
        text = text.replace(a, b)
    left = re.findall(r'\{\{[^}]*\}\}', text)
    if left:
        raise SystemExit(f'Balises non traitées : {left[:5]}')
    return text


def build():
    dicts = load_dicts()
    texts = [(p, include((SRC / 'pages' / p).read_text('utf-8'))) for p, _, _ in PAGES]
    texts.append(('404.html', include((SRC / 'pages' / '404.html').read_text('utf-8'))))
    fr = collect_fr(texts)
    missing = {}
    urls = []
    for (tpl, slug, langs), (_, text) in zip(PAGES, texts):
        for lang in langs:
            out = ROOT / prefix(lang).strip('/') / slug / 'index.html'
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(render(text, lang, slug, langs, dicts, missing), 'utf-8')
            urls.append((slug, lang, langs))
    (ROOT / '404.html').write_text(render(texts[-1][1], 'fr', '', ['fr'], dicts, missing), 'utf-8')

    # plan du site
    rows = []
    for slug, lang, langs in urls:
        alt = ''.join(f'\n    <xhtml:link rel="alternate" hreflang="{l}" href="{SITE}{prefix(l)}{slug}"/>' for l in langs) if len(langs) > 1 else ''
        rows.append(f'  <url>\n    <loc>{SITE}{prefix(lang)}{slug}</loc>{alt}\n  </url>')
    (ROOT / 'sitemap.xml').write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n'
        + '\n'.join(rows) + '\n</urlset>\n', 'utf-8')

    used = set(fr)
    for lang in LANGS[1:]:
        extra = set(dicts[lang]) - used
        if extra:
            print(f'[{lang}] clés inutilisées : {sorted(extra)[:12]}')
    if missing:
        for lang, keys in missing.items():
            print(f'[{lang}] {len(keys)} textes non traduits : {sorted(keys)[:12]}')
        if '--allow-missing' not in sys.argv:
            raise SystemExit('Traductions manquantes (ajoutez-les dans src/i18n/).')
    print(f'OK : {len(urls) + 1} pages, {len(fr)} textes.')


if __name__ == '__main__':
    if '--keys' in sys.argv:
        texts = [(p, include((SRC / 'pages' / p).read_text('utf-8'))) for p in [x[0] for x in PAGES] + ['404.html']]
        fr = collect_fr(texts)
        (SRC / 'i18n' / '_fr.json').write_text(json.dumps(fr, ensure_ascii=False, indent=1), 'utf-8')
        print(len(fr), 'textes exportés')
    else:
        build()
