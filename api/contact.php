<?php
/**
 * DYRO STUDIOS — réception des demandes (formulaire /contact/).
 *
 * Envoie la demande par email, avec la photo jointe (JPG, PNG ou WebP, 10 Mo max),
 * puis un email de confirmation au visiteur dans sa langue.
 * À configurer : $TO (destinataire) et $FROM (adresse du domaine, sinon risque de spam).
 *
 * Réponses JSON : {"ok":true} si l'email est parti, {"ok":false,...} sinon.
 * En cas d'échec, le formulaire propose un email pré-rempli.
 */
declare(strict_types=1);

$TO   = 'contact@dyrostudios.com';
$FROM = 'noreply@dyrostudios.com';
$MAX  = 10 * 1024 * 1024;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function reply(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'method']);
}

// Anti-spam : champ piège rempli ou formulaire envoyé trop vite → on fait semblant d'accepter
if (!empty($_POST['website']) || (int) ($_POST['t'] ?? 0) < 4000) {
    reply(200, ['ok' => true]);
}

/** Texte sur une ligne, sans retour chariot (évite l'injection d'en-têtes). */
function line($value, int $max): string
{
    $v = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

/** Texte multiligne, longueur limitée. */
function block($value, int $max): string
{
    $v = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $v = trim(strip_tags($v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

$lang    = in_array($_POST['lang'] ?? '', ['fr', 'de', 'en'], true) ? $_POST['lang'] : 'fr';
$type    = in_array($_POST['type'] ?? '', ['essai', 'devis', 'partenariat', 'pub'], true) ? $_POST['type'] : 'devis';
$offre   = preg_replace('/[^a-z0-9\-]/', '', (string) ($_POST['offre'] ?? '')) ?? '';
$nom     = line($_POST['nom'] ?? '', 120);
$societe = line($_POST['societe'] ?? '', 160);
$email   = line($_POST['email'] ?? '', 160);
$tel     = line($_POST['tel'] ?? '', 40);
$pays    = line($_POST['pays'] ?? '', 40);
$message = block($_POST['message'] ?? '', 4000);
$lien    = line($_POST['lien'] ?? '', 500);
$rgpd    = !empty($_POST['rgpd']);
$auth    = !empty($_POST['auth']);
$news    = !empty($_POST['news']);

if ($lien !== '' && !preg_match('~^https?://~i', $lien)) {
    $lien = 'https://' . $lien;
}
if ($lien !== '' && !filter_var($lien, FILTER_VALIDATE_URL)) {
    reply(422, ['ok' => false, 'error' => 'link']);
}
if ($nom === '' || $societe === '' || $pays === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$rgpd) {
    reply(422, ['ok' => false, 'error' => 'invalid']);
}

// Photo jointe (facultative)
$photo = null;
if (isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > $MAX || !is_uploaded_file($f['tmp_name'])) {
        reply(422, ['ok' => false, 'error' => 'file']);
    }
    $mime = '';
    if (class_exists('finfo')) {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    } elseif (function_exists('getimagesize')) {
        $mime = (string) (getimagesize($f['tmp_name'])['mime'] ?? '');
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null) {
        reply(422, ['ok' => false, 'error' => 'file']);
    }
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo((string) $f['name'], PATHINFO_FILENAME)) ?: 'photo';
    $photo = ['mime' => $mime, 'name' => substr($base, 0, 60) . '.' . $ext, 'data' => (string) file_get_contents($f['tmp_name'])];
}

if ($type === 'essai' && $photo === null && $lien === '') {
    reply(422, ['ok' => false, 'error' => 'trial']);
}
if (($photo !== null || $lien !== '') && !$auth) {
    reply(422, ['ok' => false, 'error' => 'auth']);
}

/**
 * Limite anti-abus : 4 demandes par 15 minutes et par visiteur.
 * L'adresse IP n'est jamais stockée telle quelle : seulement une empreinte non réversible
 * (salée avec la date du jour), effacée après 24 heures.
 */
function rate_limited(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return false;
    }
    $dir  = rtrim(sys_get_temp_dir(), '/');
    $file = $dir . '/dyrostudios-' . substr(hash('sha256', 'dyrostudios|' . date('Y-m-d') . '|' . $ip), 0, 32) . '.json';
    $now  = time();
    $hits = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - 900));
    if (count($hits) >= 4) {
        return true;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    foreach (glob($dir . '/dyrostudios-*.json') ?: [] as $old) {
        if (@filemtime($old) < $now - 86400) {
            @unlink($old);
        }
    }
    return false;
}

if (rate_limited()) {
    reply(429, ['ok' => false, 'error' => 'rate']);
}

date_default_timezone_set('Europe/Luxembourg');
$TYPES = ['essai' => 'Essai gratuit', 'devis' => 'Devis', 'partenariat' => 'Partenariat agence', 'pub' => 'Publicité IA'];
$OFFERS = [
    'a-la-carte' => 'À la carte — 39 € HT / photo', 'pack-essentiel' => 'Pack Essentiel — 149 € HT', 'pack-signature' => 'Pack Signature — 249 € HT',
    'agence-partenaire' => 'Agence partenaire — 499 € HT / mois', 'renovation-virtuelle' => 'Rénovation virtuelle — sur devis',
    'retouche-lumiere-couleurs' => 'Retouche lumière / couleurs', 'video-immobiliere' => 'Vidéo immobilière', 'carrousel-social' => 'Carrousel social',
    'auto-essential' => 'Auto — Essential 149 €', 'auto-cinematic' => 'Auto — Cinematic 249 €', 'auto-premium' => 'Auto — Premium dès 449 €', 'auto-forfait' => 'Auto — Forfait mensuel garage',
    'pub-ugc-produit' => 'Pub UGC IA / produit — dès 249 €', 'pub-commerces-agences' => 'Pub commerce / agence — dès 349 €', 'pub-cinematique-premium' => 'Pub cinématique premium — dès 599 €',
    'pub-film-sur-mesure' => 'Film publicitaire sur mesure — dès 990 €', 'pub-pack-3-videos' => 'Pack 3 vidéos courtes — dès 599 €',
];

// Photo : enregistrée dans un dossier privé (api/uploads, inaccessible directement), lien secret dans l'email.
// Une pièce jointe fait classer l'email en indésirables ; un lien, non. Suppression automatique après 90 jours.
$photoLink = '';
if ($photo) {
    $dir = __DIR__ . '/uploads';
    if (is_dir($dir) || @mkdir($dir, 0750, true)) {
        foreach (glob($dir . '/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $old) {
            if (@filemtime($old) < time() - 90 * 86400) {
                @unlink($old);
            }
        }
        $key = bin2hex(random_bytes(16));
        $ext = pathinfo($photo['name'], PATHINFO_EXTENSION);
        if (@file_put_contents($dir . '/' . $key . '.' . $ext, $photo['data'], LOCK_EX) !== false) {
            $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'dyrostudios.com');
            $photoLink = 'https://' . $host . '/api/photo.php?k=' . $key;
        }
    }
}

$rows = [
    'Demande'    => $TYPES[$type],
    'Offre'      => $OFFERS[$offre] ?? 'À définir',
    'Nom'        => $nom,
    'Entreprise' => $societe,
    'Email'      => $email,
    'Téléphone'  => $tel !== '' ? $tel : '—',
    'Pays'       => $pays,
    'Lien'       => $lien !== '' ? $lien : '—',
    'Photo'      => $photoLink !== '' ? $photoLink : ($photo ? $photo['name'] . ' (jointe)' : '—'),
];
$body = '';
foreach ($rows as $k => $v) {
    $body .= $k . str_repeat(' ', max(1, 12 - (function_exists('mb_strlen') ? mb_strlen($k) : strlen($k)))) . ': ' . $v . "\n";
}
$body .= "\nProjet :\n" . $message . "\n\n---\n";
$body .= 'Langue du site : ' . strtoupper($lang) . "\n";
$body .= 'Reçu le : ' . date('d/m/Y H:i') . "\n";
$body .= 'Consentement RGPD : oui · Autorisation photos : ' . ($auth ? 'oui' : 'sans objet') . ' · Newsletter : ' . ($news ? 'oui' : 'non') . "\n";

$subject  = '=?UTF-8?B?' . base64_encode('Nouvelle demande – ' . $TYPES[$type] . ' – ' . $societe) . '?=';
$fromName = '=?UTF-8?B?' . base64_encode('DYRO STUDIOS') . '?=';
$headers  = [
    'From: ' . $fromName . ' <' . $FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Auto-Submitted: auto-generated', // indispensable : sans lui, le filtre de la messagerie classe la notification en indésirables
];

if ($photo && $photoLink === '') { // repli : pièce jointe si l'enregistrement a échoué
    $b = 'dyro-' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $b . '"';
    $mail  = "This is a multi-part message in MIME format.\r\n\r\n";
    $mail .= "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $mail .= quoted_printable_encode(str_replace("\n", "\r\n", $body)) . "\r\n";
    $mail .= "--$b\r\nContent-Type: {$photo['mime']}; name=\"{$photo['name']}\"\r\nContent-Transfer-Encoding: base64\r\n";
    $mail .= "Content-Disposition: attachment; filename=\"{$photo['name']}\"\r\n\r\n";
    $mail .= chunk_split(base64_encode($photo['data'])) . "\r\n--$b--";
} else {
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $mail = $body;
}

$sent = send_mail($TO, $subject, $mail, $headers, $FROM);

if ($sent) {
    send_confirmation($email, $lang, $type, isset($OFFERS[$offre]) ? $offre : '', $photo !== null, $lien !== '', $FROM, $TO);
}
reply($sent ? 200 : 500, ['ok' => (bool) $sent]);

/**
 * Confirmation au visiteur, dans la langue du site : récapitulatif de ses choix et prochaines étapes.
 * Seules des valeurs issues des listes du formulaire sont reprises (jamais le texte libre saisi) :
 * le formulaire ne peut pas servir à écrire un message arbitraire à un tiers.
 */
function send_confirmation(string $to, string $lang, string $type, string $offre, bool $hasPhoto, bool $hasLink, string $from, string $replyTo): void
{
    $L = [
        'fr' => [
            'subject' => 'Votre demande est bien reçue – DYRO STUDIOS', 'hello' => 'Bonjour,',
            'intro' => 'Merci, votre demande a bien été reçue. Nous vous répondons personnellement <strong>sous 1 jour ouvré</strong>.',
            'recap' => 'VOTRE DEMANDE', 'r_type' => 'Demande', 'r_offer' => 'Offre choisie', 'r_photo' => 'Photo',
            'photo_yes' => 'Photo reçue', 'link_yes' => 'Lien de téléchargement reçu', 'photo_both' => 'Photo et lien reçus', 'photo_no' => 'Aucune photo pour l’instant',
            'undecided' => 'À définir ensemble',
            'next' => 'LES PROCHAINES ÉTAPES',
            'steps' => [
                'devis' => ['Nous vérifions vos photos : netteté, lumière, cadrage.', 'Vous recevez votre devis par email, sous 1 jour ouvré.', 'Après votre accord, vos visuels HD sont livrés en 2 à 3 jours ouvrés.'],
                'essai' => ['Nous vérifions que votre photo se prête à l’essai.', 'Nous réalisons votre photo d’essai, offerte et sans engagement.', 'Vous la recevez par email, avec nos conseils pour la suite.'],
                'partenariat' => ['Nous étudions votre activité et vos volumes.', 'Nous vous proposons un échange pour définir le rythme et les conditions.', 'Vous recevez une proposition claire, sans engagement.'],
                'pub' => ['Nous étudions votre produit et votre objectif.', 'Vous recevez un concept et un devis clair, sous 1 jour ouvré.', 'Après votre accord, nous produisons et vous validez chaque plan avant livraison.'],
            ],
            'add_photo' => 'Pensez à nous envoyer vos photos : <strong>répondez simplement à cet email</strong> en les joignant, ou avec un lien de téléchargement.',
            'add' => 'Une précision ou une autre photo ? <strong>Répondez simplement à cet email.</strong>',
            'urgent' => 'Une urgence ? WhatsApp ou téléphone :', 'regards' => 'Bien cordialement,', 'team' => 'L’équipe DYRO STUDIOS',
            'tagline' => 'Home staging et rénovation virtuels, publicités par IA',
            'footer' => 'Vous recevez ce message car votre adresse a été saisie dans le formulaire de dyrostudios.com. Si ce n’est pas vous, ignorez-le.',
            'types' => ['essai' => 'Essai gratuit', 'devis' => 'Devis', 'partenariat' => 'Partenariat agence', 'pub' => 'Publicité IA'],
            'vat' => 'HT', 'from' => 'dès', 'quote' => 'sur devis', 'photo_u' => '/ photo', 'month' => '/ mois', 'video' => '/ vidéo',
        ],
        'de' => [
            'subject' => 'Ihre Anfrage ist eingegangen – DYRO STUDIOS', 'hello' => 'Guten Tag,',
            'intro' => 'Vielen Dank, Ihre Anfrage ist bei uns eingegangen. Wir antworten Ihnen persönlich <strong>innerhalb eines Werktages</strong>.',
            'recap' => 'IHRE ANFRAGE', 'r_type' => 'Anfrage', 'r_offer' => 'Gewähltes Angebot', 'r_photo' => 'Foto',
            'photo_yes' => 'Foto erhalten', 'link_yes' => 'Download-Link erhalten', 'photo_both' => 'Foto und Link erhalten', 'photo_no' => 'Noch kein Foto',
            'undecided' => 'Gemeinsam festzulegen',
            'next' => 'DIE NÄCHSTEN SCHRITTE',
            'steps' => [
                'devis' => ['Wir prüfen Ihre Fotos: Schärfe, Licht, Bildausschnitt.', 'Sie erhalten Ihr Angebot per E-Mail, innerhalb eines Werktages.', 'Nach Ihrer Zustimmung liefern wir Ihre HD-Bilder in 2 bis 3 Werktagen.'],
                'essai' => ['Wir prüfen, ob sich Ihr Foto für den Test eignet.', 'Wir erstellen Ihr Testfoto – kostenlos und unverbindlich.', 'Sie erhalten es per E-Mail, mit unseren Empfehlungen.'],
                'partenariat' => ['Wir sehen uns Ihre Tätigkeit und Ihre Mengen an.', 'Wir schlagen Ihnen ein Gespräch vor, um Rhythmus und Konditionen festzulegen.', 'Sie erhalten ein klares, unverbindliches Angebot.'],
                'pub' => ['Wir prüfen Ihr Produkt und Ihr Ziel.', 'Sie erhalten ein Konzept und ein klares Angebot, innerhalb eines Werktages.', 'Nach Ihrer Zustimmung produzieren wir, und Sie geben jede Einstellung vor der Lieferung frei.'],
            ],
            'add_photo' => 'Denken Sie an Ihre Fotos: <strong>Antworten Sie einfach auf diese E-Mail</strong> mit den Fotos im Anhang oder einem Download-Link.',
            'add' => 'Eine Ergänzung oder ein weiteres Foto? <strong>Antworten Sie einfach auf diese E-Mail.</strong>',
            'urgent' => 'Dringend? WhatsApp oder Telefon:', 'regards' => 'Mit freundlichen Grüßen', 'team' => 'Ihr DYRO STUDIOS Team',
            'tagline' => 'Virtuelles Home Staging und virtuelle Renovierung, KI-Werbung',
            'footer' => 'Sie erhalten diese Nachricht, weil Ihre Adresse im Formular auf dyrostudios.com eingegeben wurde. Falls Sie das nicht waren, ignorieren Sie sie bitte.',
            'types' => ['essai' => 'Gratistest', 'devis' => 'Angebot', 'partenariat' => 'Agentur-Partnerschaft', 'pub' => 'KI-Werbung'],
            'vat' => 'zzgl. MwSt.', 'from' => 'ab', 'quote' => 'auf Anfrage', 'photo_u' => '/ Foto', 'month' => '/ Monat', 'video' => '/ Video',
        ],
        'en' => [
            'subject' => 'We have received your request – DYRO STUDIOS', 'hello' => 'Hello,',
            'intro' => 'Thank you, we have received your request. We will reply personally <strong>within one business day</strong>.',
            'recap' => 'YOUR REQUEST', 'r_type' => 'Request', 'r_offer' => 'Selected offer', 'r_photo' => 'Photo',
            'photo_yes' => 'Photo received', 'link_yes' => 'Download link received', 'photo_both' => 'Photo and link received', 'photo_no' => 'No photo yet',
            'undecided' => 'To be defined together',
            'next' => 'NEXT STEPS',
            'steps' => [
                'devis' => ['We check your photos: sharpness, light, framing.', 'You receive your quote by email within one business day.', 'Once you approve, your HD images are delivered in 2 to 3 business days.'],
                'essai' => ['We check that your photo is suitable for the trial.', 'We create your trial photo, free and with no commitment.', 'You receive it by email, with our advice for next steps.'],
                'partenariat' => ['We look at your business and your volumes.', 'We suggest a call to agree on pace and terms.', 'You receive a clear proposal, with no commitment.'],
                'pub' => ['We study your product and your goal.', 'You receive a concept and a clear quote within one business day.', 'Once you approve, we produce and you approve every shot before delivery.'],
            ],
            'add_photo' => 'Remember to send your photos: <strong>simply reply to this email</strong> with them attached, or with a download link.',
            'add' => 'Anything to add, or another photo? <strong>Simply reply to this email.</strong>',
            'urgent' => 'Urgent? WhatsApp or phone:', 'regards' => 'Kind regards,', 'team' => 'The DYRO STUDIOS team',
            'tagline' => 'Virtual home staging and renovation, AI ads',
            'footer' => 'You are receiving this message because your address was entered in the form on dyrostudios.com. If this wasn’t you, please ignore it.',
            'types' => ['essai' => 'Free trial', 'devis' => 'Quote', 'partenariat' => 'Agency partnership', 'pub' => 'AI ad'],
            'vat' => 'excl. VAT', 'from' => 'from', 'quote' => 'on quote', 'photo_u' => '/ photo', 'month' => '/ month', 'video' => '/ video',
        ],
    ];
    $t = $L[$lang] ?? $L['fr'];
    $eur = static fn(int $n) => $lang === 'en' ? '€' . number_format($n, 0, '.', ',') : number_format($n, 0, ',', "\u{202F}") . "\u{00A0}€";
    $price = static function (?int $n, string $unit = '', bool $from = false) use ($t, $eur): string {
        if ($n === null) {
            return $t['quote'];
        }
        return trim(($from ? $t['from'] . ' ' : '') . $eur($n) . "\u{00A0}" . str_replace(' ', "\u{00A0}", $t['vat']) . ' ' . $unit);
    };
    // nom de l'offre (par langue) et prix
    $N = [
        'a-la-carte' => [['À la carte', 'À la carte', 'À la carte'], 39, 'photo_u', false, ['1 photo', '1 Foto', '1 photo']],
        'pack-essentiel' => [['Pack Essentiel', 'Paket Essentiel', 'Essentiel pack'], 149, '', false, ['5 photos', '5 Fotos', '5 photos']],
        'pack-signature' => [['Pack Signature', 'Paket Signature', 'Signature pack'], 249, '', false, ['8 photos + 3 visuels réseaux sociaux', '8 Fotos + 3 Social-Media-Visuals', '8 photos + 3 social media visuals']],
        'agence-partenaire' => [['Agence partenaire', 'Partneragentur', 'Partner agency'], 499, 'month', false, ['jusqu’à 20 photos par mois', 'bis zu 20 Fotos pro Monat', 'up to 20 photos per month']],
        'renovation-virtuelle' => [['Rénovation virtuelle', 'Virtuelle Renovierung', 'Virtual renovation'], null, '', false, null],
        'retouche-lumiere-couleurs' => [['Retouche lumière / couleurs', 'Licht- / Farbretusche', 'Light / colour retouching'], 15, 'photo_u', true, null],
        'video-immobiliere' => [['Vidéo à partir de vos photos', 'Video aus Ihren Fotos', 'Video from your photos'], 99, '', true, null],
        'carrousel-social' => [['Carrousel réseaux sociaux', 'Social-Media-Karussell', 'Social media carousel'], 49, '', true, null],
        'auto-essential' => [['Publicité auto Essential', 'Autowerbung Essential', 'Car ad Essential'], 149, 'video', false, null],
        'auto-cinematic' => [['Publicité auto Cinematic', 'Autowerbung Cinematic', 'Car ad Cinematic'], 249, 'video', false, null],
        'auto-premium' => [['Publicité auto Premium', 'Autowerbung Premium', 'Car ad Premium'], 449, 'video', true, null],
        'auto-forfait' => [['Forfait mensuel garage', 'Monatspaket für Werkstätten', 'Monthly plan for garages'], null, '', false, null],
        'pub-ugc-produit' => [['Publicité UGC IA / produit', 'UGC-KI- / Produktwerbung', 'AI UGC / product ad'], 249, '', true, null],
        'pub-commerces-agences' => [['Publicité commerce ou agence', 'Werbung für Geschäft oder Agentur', 'Ad for a shop or agency'], 349, '', true, null],
        'pub-cinematique-premium' => [['Publicité cinématique premium', 'Premium-Kinowerbung', 'Premium cinematic ad'], 599, '', true, null],
        'pub-film-sur-mesure' => [['Film publicitaire sur mesure', 'Maßgeschneiderter Werbefilm', 'Bespoke ad film'], 990, '', true, null],
        'pub-pack-3-videos' => [['Pack de 3 vidéos courtes', 'Paket mit 3 kurzen Videos', 'Pack of 3 short videos'], 599, '', true, null],
    ];
    $i = ['fr' => 0, 'de' => 1, 'en' => 2][$lang] ?? 0;
    if (isset($N[$offre])) {
        [$names, $amount, $unitKey, $isFrom, $detail] = $N[$offre];
        $offerName = $names[$i];
        $offerPrice = $price($amount, $unitKey ? $t[$unitKey] : '', $isFrom);
        $offerDetail = $detail[$i] ?? '';
    } else {
        $offerName = $t['undecided'];
        $offerPrice = '';
        $offerDetail = '';
    }
    $photo = $hasPhoto && $hasLink ? $t['photo_both'] : ($hasPhoto ? $t['photo_yes'] : ($hasLink ? $t['link_yes'] : $t['photo_no']));
    $steps = $t['steps'][$type] ?? $t['steps']['devis'];
    $needPhoto = !$hasPhoto && !$hasLink && in_array($type, ['devis', 'essai'], true);

    // HTML (tableaux + styles en ligne : compatible avec les messageries)
    $c = '#8c6d2f';
    $F = 'font-family:Arial,Helvetica,sans-serif;';
    $row = static fn(string $k, string $v, bool $last = false) =>
        '<tr><td style="' . $F . 'padding:9px 0;font-size:14px;color:#6b6b6b;vertical-align:top;width:38%;' . ($last ? '' : 'border-bottom:1px solid #eadfc8;') . '">' . $k . '</td>'
        . '<td style="' . $F . 'padding:9px 0 9px 10px;font-size:15px;color:#1a1a1a;' . ($last ? '' : 'border-bottom:1px solid #eadfc8;') . '">' . $v . '</td></tr>';
    $offerHtml = '<strong>' . $offerName . '</strong>'
        . ($offerPrice !== '' ? '<br><span style="font-family:Georgia,\'Times New Roman\',serif;font-size:19px;font-weight:bold;color:' . $c . '">' . $offerPrice . '</span>' : '')
        . ($offerDetail !== '' ? '<br><span style="font-size:13px;color:#6b6b6b">' . $offerDetail . '</span>' : '');
    $stepsHtml = '';
    foreach ($steps as $n => $s) {
        $stepsHtml .= '<tr><td width="34" valign="top" style="padding:6px 0"><span style="' . $F . 'display:inline-block;width:24px;height:24px;line-height:24px;border-radius:12px;background:' . $c . ';color:#ffffff;font-size:13px;font-weight:bold;text-align:center">' . ($n + 1) . '</span></td>'
            . '<td style="' . $F . 'padding:7px 0;font-size:15px;color:#1a1a1a;line-height:1.5">' . $s . '</td></tr>';
    }
    $html = '<div style="' . $F . 'font-size:15px;line-height:1.6;color:#1a1a1a;max-width:600px">'
        . '<p style="margin:0 0 14px">' . $t['hello'] . '</p>'
        . '<p style="margin:0 0 20px">' . $t['intro'] . '</p>'
        . '<p style="margin:0 0 6px;font-size:13px;font-weight:bold;letter-spacing:1px;color:' . $c . '">' . $t['recap'] . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf6ee;border:1px solid #eadfc8;border-radius:10px;margin:0 0 22px"><tr><td style="padding:6px 18px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . $row($t['r_type'], '<strong>' . ($t['types'][$type] ?? $t['types']['devis']) . '</strong>')
        . $row($t['r_offer'], $offerHtml)
        . $row($t['r_photo'], $photo, true)
        . '</table></td></tr></table>'
        . '<p style="margin:0 0 6px;font-size:13px;font-weight:bold;letter-spacing:1px;color:' . $c . '">' . $t['next'] . '</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px">' . $stepsHtml . '</table>'
        . '<p style="margin:0 0 18px' . ($needPhoto ? ';padding:12px 16px;background:#fff8e6;border-left:3px solid ' . $c : '') . '">' . ($needPhoto ? $t['add_photo'] : $t['add']) . '</p>'
        . '<p style="margin:0 0 18px">' . $t['urgent'] . ' <a href="https://wa.me/352691349632" style="color:' . $c . ';white-space:nowrap">+352 691 349 632</a></p>'
        . '<p style="margin:0 0 4px">' . $t['regards'] . '</p>'
        . '<p style="margin:0 0 4px"><strong>' . $t['team'] . '</strong></p>'
        . '<p style="margin:0;font-size:14px;color:#555">' . $t['tagline'] . '<br><a href="mailto:contact@dyrostudios.com" style="color:' . $c . '">contact@dyrostudios.com</a> · <span style="white-space:nowrap">+352 691 349 632</span> · <a href="https://dyrostudios.com" style="color:' . $c . '">dyrostudios.com</a></p>'
        . '<hr style="border:0;border-top:1px solid #dddddd;margin:22px 0 12px">'
        . '<p style="margin:0;font-size:13px;color:#777">' . $t['footer'] . '</p></div>';

    // Version texte (même contenu)
    $plain = static fn(string $s) => html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8');
    $text = $t['hello'] . "\n\n" . $plain($t['intro']) . "\n\n" . $plain($t['recap']) . "\n"
        . '- ' . $t['r_type'] . ' : ' . ($t['types'][$type] ?? $t['types']['devis']) . "\n"
        . '- ' . $t['r_offer'] . ' : ' . $offerName . ($offerPrice !== '' ? ' – ' . $offerPrice : '') . ($offerDetail !== '' ? ' (' . $offerDetail . ')' : '') . "\n"
        . '- ' . $t['r_photo'] . ' : ' . $photo . "\n\n" . $t['next'] . "\n";
    foreach ($steps as $n => $s) {
        $text .= ($n + 1) . '. ' . $s . "\n";
    }
    $text .= "\n" . $plain($needPhoto ? $t['add_photo'] : $t['add']) . "\n" . $t['urgent'] . " +352 691 349 632\n\n"
        . $t['regards'] . "\n" . $t['team'] . "\n" . $t['tagline'] . "\ncontact@dyrostudios.com · +352 691 349 632 · dyrostudios.com\n\n—\n" . $t['footer'] . "\n";
    if ($lang !== 'fr') {
        $text = str_replace(' : ', ': ', $text);
    }

    $b = 'alt-' . bin2hex(random_bytes(10));
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(str_replace("\n", "\r\n", $text)) . "\r\n"
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($html) . "\r\n--$b--";
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('DYRO STUDIOS') . '?= <' . $from . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
        'Auto-Submitted: auto-replied',
    ];
    send_mail($to, '=?UTF-8?B?' . base64_encode($t['subject']) . '?=', $body, $headers, $from);
}

/**
 * Envoi : par la boîte noreply (SMTP authentifié, signé DKIM : évite les courriers indésirables)
 * si le fichier de configuration privé existe, sinon par la fonction mail() du serveur.
 *
 * Fichier privé (jamais dans Git) : api/.smtp.php
 *   <?php return ['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => 'noreply@…', 'pass' => '…'];
 */
function send_mail(string $to, string $subject, string $body, array $headers, string $from): bool
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/.smtp.php';
        $cfg = is_file($file) ? (include $file) : false;
    }
    if (is_array($cfg) && !empty($cfg['pass'])) {
        if (smtp_send($cfg, $to, $subject, $body, $headers, $from)) {
            return true;
        }
    }
    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
}

function smtp_send(array $cfg, string $to, string $subject, string $body, array $headers, string $from): bool
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client('ssl://' . $cfg['host'] . ':' . (int) ($cfg['port'] ?? 465), $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log('DYRO SMTP connexion : ' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $step = static function (?string $command, array $codes) use ($fp, $read): bool {
        if ($command !== null) {
            fwrite($fp, $command . "\r\n");
        }
        $answer = $read();
        if (!in_array((int) substr($answer, 0, 3), $codes, true)) {
            error_log('DYRO SMTP : ' . trim($answer));
            return false;
        }
        return true;
    };
    $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
    $message = 'Date: ' . date('r') . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . $subject . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . ">\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n"
        . preg_replace('/\r\n|\r|\n/', "\r\n", $body);
    $message = preg_replace('/^\./m', '..', $message); // un point en début de ligne est doublé (RFC 5321)
    $ok = $step(null, [220])
        && $step('EHLO ' . $domain, [250])
        && $step('AUTH LOGIN', [334])
        && $step(base64_encode($cfg['user']), [334])
        && $step(base64_encode($cfg['pass']), [235])
        && $step('MAIL FROM:<' . $from . '>', [250])
        && $step('RCPT TO:<' . $to . '>', [250, 251])
        && $step('DATA', [354])
        && $step($message . "\r\n.", [250]);
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
