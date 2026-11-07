# BROUILLON — page « Aide SME Packages Digital » (NE PAS PUBLIER)

Le dossier `docs/` est bloqué en ligne (.htaccess) : ce fichier n'est pas visible sur dyroweb.online.

## Conditions avant publication

1. DYRO WEB immatriculé : numéro RCS, autorisation d'établissement, numéro de TVA (mentions légales à jour).
2. Confirmation écrite de la House of Entrepreneurship (Chambre de Commerce) :
   - qu'un site vitrine / multilingue / e-commerce entre bien dans le volet « Digital » (marketing digital) ;
   - qu'une entreprise individuelle comme DYRO WEB peut être le prestataire retenu ;
   - quels postes sont éligibles (création oui ; maintenance, hébergement, nom de domaine probablement non) ;
   - le moment exact du dépôt de la demande (avant signature du devis ? avant le début des travaux ?).
3. Prix du « Pack Croissance » validé (≥ 3 000 € HT, sinon le projet n'est pas éligible).

Source officielle : https://guichet.public.lu/fr/entreprises/financement-aides/regime-sme-packages/soutien-pme/sme-packages-digital.html

## Ce que l'on sait (Guichet.lu, novembre 2026)

- 70 % des coûts éligibles pris en charge par le ministère de l'Économie.
- Projet entre 3 000 et 25 000 € HT, soit une aide de 2 100 à 17 500 €.
- Entreprise bénéficiaire : PME (seuils effectif, chiffre d'affaires, bilan), autorisation d'établissement, siège au Luxembourg.
- Première étape : House of Entrepreneurship (Chambre de Commerce) ou Chambre des Métiers (eHandwierk) pour les artisans.
- L'aide est versée après paiement de la facture finale du prestataire.

## Pourquoi c'est un levier commercial

- Le Site vitrine Pro (1 890 à 2 900 € HT) est sous le seuil : il faut une offre ≥ 3 000 € HT.
- À 4 900 € HT, le client reçoit 3 430 € d'aide : coût réel 1 470 € HT, moins cher qu'un site vitrine classique.
- slash.lu utilise déjà cet argument : ne pas l'avoir, c'est perdre les PME qui comparent.

## Proposition : Pack Croissance (à valider)

| Contenu | Détail |
|---|---|
| Site multilingue | 7 à 10 pages, FR + DE + EN (PT en option) |
| Référencement local | pages par service, fiche Google, données structurées |
| Prise de rendez-vous et formulaires avancés | |
| Suivi d'audience | tableau de bord sans cookies |
| Prix | 4 900 € HT (fourchette 3 900 à 6 500 € HT) |
| Aide 70 % | 3 430 € → reste 1 470 € HT |

La TVA (17 %) est payée sur toute la facture ; une entreprise assujettie la récupère.

---

## Texte de la page (FR, à traduire en DE / EN / PT / LU au moment de publier)

**Adresse prévue :** /aide-sme-packages-digital.html

**Titre Google :** Aide SME Packages Digital : 70 % de votre site financé · DYRO WEB

**Description :** L'État prend en charge 70 % d'un projet digital entre 3 000 et 25 000 € HT. Conditions, étapes et exemple chiffré pour votre site internet au Luxembourg.

### Jusqu'à 70 % de votre projet digital pris en charge par l'État.

Avec le programme SME Packages – Digital, le ministère de l'Économie rembourse 70 % des coûts éligibles d'un projet digital compris entre 3 000 et 25 000 € HT. Nous vous aidons à monter un projet qui entre dans le cadre.

### Exemple

Pack Croissance à 4 900 € HT → aide de 3 430 € → votre coût : 1 470 € HT.

### Qui peut en bénéficier ?

- Les PME qui ont une autorisation d'établissement au Luxembourg.
- Avec leur siège au Luxembourg.
- Pour un projet entre 3 000 et 25 000 € HT.

### Les étapes

1. Vous contactez la House of Entrepreneurship (ou la Chambre des Métiers si vous êtes artisan).
2. Un conseiller analyse votre besoin.
3. Nous vous remettons un devis détaillé, sous 48 h.
4. Vous déposez la demande d'aide [moment exact à confirmer].
5. Nous réalisons votre site.
6. Vous payez la facture, puis l'État vous rembourse 70 % des coûts éligibles.

### Questions fréquentes

- **L'aide est-elle garantie ?** Non : c'est l'administration qui décide. Nous vous aidons à préparer un projet clair et conforme.
- **La maintenance est-elle prise en charge ?** [À confirmer avec la House of Entrepreneurship.]
- **Mon site vitrine à 2 000 € est-il éligible ?** Non, le projet doit atteindre 3 000 € HT. Le Pack Croissance est conçu pour cela.

**Bouton :** Vérifier mon éligibilité → /devis.html?offre=croissance

## Au moment de publier

- Ajouter la page à `scripts/build_extra.py` (5 langues), au sitemap, au footer et dans la FAQ.
- Remplacer la phrase neutre de l'article /blog/cout-site-internet-luxembourg.html par un lien vers cette page.
- Ajouter l'offre « croissance » au formulaire de devis et au rapport hebdo (`api/_report.php`).
