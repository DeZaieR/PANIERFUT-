# DYRO STUDIOS — site dyrostudios.com

Site statique (HTML/CSS/JS sans dépendance) en français, allemand et anglais, avec un formulaire PHP qui envoie les demandes par email (photo jointe).

## Structure
- `src/pages/` : gabarits des pages (texte français écrit directement dedans, sous la forme `{{cle::Texte}}`).
- `src/partials/` : en-tête, pied de page, balises communes.
- `src/i18n/de.json`, `src/i18n/en.json` : traductions.
- `assets/` : CSS, JS, polices, images.
- `api/contact.php` : réception du formulaire (destinataire : contact@dyrostudios.com).
- Pages générées (ne pas modifier à la main) : `index.html`, `contact/`, `publicites/`, `mentions-legales/`, `confidentialite/`, `de/`, `en/`, `404.html`, `sitemap.xml`.

## Modifier le site
1. Modifier le gabarit dans `src/pages/` (et la traduction dans `src/i18n/` si le texte change).
2. Lancer `python3 scripts/build.py` : le script refuse de générer s'il manque une traduction.
3. Si le CSS ou le JS change, augmenter le numéro `?v=` dans `src/partials/head.html`.
4. Commit + push : Hostinger déploie automatiquement la branche.

`python3 scripts/build.py --keys` exporte tous les textes français dans `src/i18n/_fr.json` (utile pour une relecture).

## Avant la mise en ligne
- Compléter les mentions légales (adresse, RCS, autorisation d'établissement, TVA) : textes `[à compléter]`.
- Valider les durées de conservation dans la politique de confidentialité.

## Formulaire et emails
- Les emails partent de la boîte noreply@dyrostudios.com (SMTP authentifié, signé DKIM). Ses identifiants sont dans `api/.smtp.php`, **uniquement sur le serveur** (jamais dans Git, bloqué par `.htaccess`). Sans ce fichier, le site se rabat sur `mail()`.
- Si le site est recréé sur Hostinger, redéposer `api/.smtp.php` (modèle en tête de `send_mail()` dans `api/contact.php`).
- L'en-tête `Auto-Submitted: auto-generated` est indispensable : sans lui, la notification arrive dans les indésirables.
- Les photos reçues sont stockées dans `api/uploads/` (fermé au public), ouvertes via le lien secret de l'email (`api/photo.php`), supprimées après 90 jours.
- Anti-abus : 4 envois par 15 minutes et par visiteur.

## Hébergement
- Production : https://dyrostudios.com (déploiement automatique de la branche `claude/dyro-studios-site`).
- Test : https://teal-barracuda-408224.hostingersite.com (même branche, même déploiement automatique).
