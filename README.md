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

## Langues

- Le français est écrit directement dans les pages HTML.
- Allemand, anglais, portugais et luxembourgeois : `assets/js/i18n.js` (une clé par texte, attribut `data-i18n` dans le HTML).
- Chaque langue a son adresse (`?lang=de`, `?lang=en`, `?lang=pt`, `?lang=lb`) déclarée à Google avec `hreflang`.
- Les pages légales sont en français uniquement (un avis s'affiche dans les autres langues).

## Avant la mise en ligne

1. **Pages légales** : compléter les champs surlignés en jaune (rechercher `class="todo"`) : adresse, n° RCS, autorisation d'établissement, n° TVA, hébergeur, durées à valider. Faire relire par un juriste ou une fiduciaire.
2. **Formulaire de devis** : dans `api/devis.php`, vérifier `$TO` (destinataire) et `$FROM` (adresse de votre domaine). Le script nécessite un hébergement PHP (Hostinger : oui). Sans PHP, le formulaire prépare automatiquement un email pré-rempli.
3. **Traductions** : faire relire l'allemand, le portugais et surtout le luxembourgeois par des natifs.

## Déploiement (Hostinger)

Domaine : **dyroweb.online**. Envoyer tout le dossier à la racine du site (ou connecter ce dépôt GitHub dans hPanel pour un déploiement automatique). Aucune compilation n'est nécessaire.

`.htaccess` gère HTTPS, la compression, le cache et la page 404. Après une modification du CSS ou du JS, augmenter le numéro `?v=` dans les pages.

`sitemap.xml` et `robots.txt` sont prêts : après la mise en ligne, déclarer `https://dyroweb.online/sitemap.xml` dans Google Search Console.

Les polices sont hébergées sur le site (`assets/fonts/`) : aucune requête vers Google, conformément à la politique de confidentialité.
