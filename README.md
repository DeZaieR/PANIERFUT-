# DYRO WEB — site de l'agence

Site statique (HTML, CSS, JavaScript sans framework) en 5 langues : FR · DE · EN · PT · LU.

## Pages

| Fichier | Contenu |
|---|---|
| `index.html` | Accueil : services, méthode, offres, FAQ, contact |
| `devis.html` | Demande de devis en 4 étapes avec estimation de prix |
| `mentions-legales.html` | Mentions légales |
| `confidentialite.html` | Politique de confidentialité (RGPD) |
| `cgv.html` | Conditions générales de vente |
| `404.html` | Page introuvable |
| `de/` `en/` `pt/` `lb/` | Accueil et devis dans chaque langue (générés) |

## Langues

- Le français est écrit directement dans les pages HTML.
- Allemand, anglais, portugais et luxembourgeois : `assets/js/i18n.js` (une clé par texte, attribut `data-i18n` dans le HTML).
- L'accueil et la page devis existent en vraies pages par langue : `/de/`, `/en/`, `/pt/`, `/lb/` (générées, ne pas modifier à la main), déclarées à Google avec `hreflang`. Le sélecteur de langue navigue entre ces adresses.
- La langue du navigateur ne change jamais le contenu : elle déclenche seulement une suggestion discrète.
- Les pages légales sont en français uniquement (un avis s'affiche dans les autres langues).
- Relecture : `docs/relecture-traductions.xlsx` (une feuille par langue, à faire remplir par des natifs).

## Modifier le site

1. Modifier `index.html` (textes français) et/ou `assets/js/i18n.js` (traductions).
2. Régénérer les pages :

```
python3 scripts/build_pages.py      # devis, pages légales, 404 (en-tête et footer repris de l'accueil)
python3 scripts/build_langs.py      # /de/ /en/ /pt/ /lb/ + sitemap.xml
python3 scripts/export_translations.py   # (facultatif) fichier de relecture
```

3. Après une modification du CSS ou du JS, augmenter le numéro `?v=` (constante `V` dans `scripts/build_pages.py` et liens de `index.html`).

## Avant la mise en ligne

1. **Pages légales** : compléter les champs surlignés en jaune (rechercher `class="todo"`) : adresse, n° RCS, autorisation d'établissement, n° TVA, hébergeur, durées à valider. Faire relire par un juriste ou une fiduciaire.
2. **Formulaire de devis** : dans `api/devis.php`, vérifier `$TO` (destinataire) et `$FROM` (adresse de votre domaine). Le script envoie la demande, puis un email de confirmation au prospect dans sa langue (textes fixes, 3 demandes max par 15 min et par visiteur). Il nécessite un hébergement PHP (Hostinger : oui). Sans PHP, le formulaire prépare automatiquement un email pré-rempli.
3. **Réservation d'appel** : les boutons « Réserver un appel de 20 min » ouvrent WhatsApp avec un message pré-rempli. Pour passer à Calendly ou Google Agenda, remplacer la valeur `book.href` (dans `index.html` et `assets/js/i18n.js`), puis régénérer.
4. **Mesure d'audience** : prête mais désactivée (aucun outil chargé). Voir `scripts/partials/analytics.html` pour activer Plausible, puis mettre à jour la politique de confidentialité.
5. **Traductions** : faire relire l'allemand, le portugais et surtout le luxembourgeois par des natifs (`docs/relecture-traductions.xlsx`).

## Déploiement (Hostinger)

Domaine : **dyroweb.online**. Envoyer tout le dossier à la racine du site (ou connecter ce dépôt GitHub dans hPanel pour un déploiement automatique). Aucune compilation n'est nécessaire.

`.htaccess` gère HTTPS, la compression, le cache et la page 404. Après une modification du CSS ou du JS, augmenter le numéro `?v=` dans les pages.

`sitemap.xml` et `robots.txt` sont prêts : après la mise en ligne, déclarer `https://dyroweb.online/sitemap.xml` dans Google Search Console.

Les polices sont hébergées sur le site (`assets/fonts/`) : aucune requête vers Google, conformément à la politique de confidentialité.
