# Assistant IA de dyroweb.online

Le bouton « Assistant IA » de la barre d'actions n'apparaît que si la clé API est installée sur le serveur.
Sans clé, rien ne s'affiche et rien n'est facturé.

## Fichiers

| Fichier | Rôle |
| --- | --- |
| `api/assistant.php` | Reçoit les messages, appelle l'API Claude, transmet la demande par email |
| `api/_assistant_kb.php` | Instructions et connaissances de l'assistant : **à mettre à jour à chaque changement de prix ou d'offre** |
| `assets/js/assistant.js` | Fenêtre de discussion, chargée au premier clic |
| `api/.assistant.php` | Clé API, **hors dépôt**, créée à la main sur le serveur |

## Installer la clé (à faire une seule fois, par Dany)

1. Créer un compte sur console.anthropic.com, ajouter un moyen de paiement et **fixer une limite de dépense mensuelle**.
2. Créer une clé API nommée `dyroweb-assistant`.
3. Dans hPanel Hostinger : Gestionnaire de fichiers → dyroweb.online → `public_html/api` → nouveau fichier `.assistant.php` :

```php
<?php return [
    'key' => 'COLLER_LA_CLE_ICI',
    'model' => 'claude-haiku-5-5',
    'daily_cap' => 300,
];
```

4. Ouvrir dyroweb.online : le bouton « Assistant IA » apparaît dans la barre du bas.

Ne jamais mettre la clé dans le dépôt GitHub, dans un email ou dans un chat.

## Couper l'assistant

Renommer ou supprimer `api/.assistant.php` : le bouton disparaît (au plus 5 minutes de cache).

## Limites intégrées

- 600 tokens maximum par réponse, 16 derniers messages envoyés au modèle.
- 20 messages par visiteur en 10 minutes, 80 par jour.
- `daily_cap` appels à l'API par jour pour tout le site (300 par défaut), puis message de repli avec email, téléphone et lien de rendez-vous.
- Une seule demande transmise par visiteur et par heure.

## Ce que l'assistant fait et ne fait pas

- Se présente comme une IA (AI Act, article 50).
- Répond à partir de `api/_assistant_kb.php` uniquement ; n'invente ni prix, ni client, ni résultat.
- Ne promet jamais l'aide SME Packages.
- Transmet une demande à contact@dyroweb.online seulement avec le nom, un email ou un téléphone et l'accord explicite du visiteur.
- N'enregistre aucune conversation sur le serveur.
