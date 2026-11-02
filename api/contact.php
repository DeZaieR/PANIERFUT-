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

$rows = [
    'Demande'    => $TYPES[$type],
    'Offre'      => $OFFERS[$offre] ?? 'À définir',
    'Nom'        => $nom,
    'Entreprise' => $societe,
    'Email'      => $email,
    'Téléphone'  => $tel !== '' ? $tel : '—',
    'Pays'       => $pays,
    'Lien'       => $lien !== '' ? $lien : '—',
    'Photo'      => $photo ? $photo['name'] . ' (jointe)' : '—',
];
$body = '';
foreach ($rows as $k => $v) {
    $body .= $k . str_repeat(' ', max(1, 12 - (function_exists('mb_strlen') ? mb_strlen($k) : strlen($k)))) . ': ' . $v . "\n";
}
$body .= "\nProjet :\n" . $message . "\n\n---\n";
$body .= 'Langue du site : ' . strtoupper($lang) . "\n";
$body .= 'Reçu le : ' . date('d/m/Y H:i') . "\n";
$body .= 'Consentement RGPD : oui · Autorisation photos : ' . ($auth ? 'oui' : 'sans objet') . ' · Newsletter : ' . ($news ? 'oui' : 'non') . "\n";

$subject  = '=?UTF-8?B?' . base64_encode($TYPES[$type] . ' – ' . $nom . ' (' . $societe . ')') . '?=';
$fromName = '=?UTF-8?B?' . base64_encode('DYRO STUDIOS · Site') . '?=';
$headers  = [
    'From: ' . $fromName . ' <' . $FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'X-Mailer: DYRO STUDIOS',
];

if ($photo) {
    $b = 'dyro-' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $b . '"';
    $mail  = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $mail .= chunk_split(base64_encode($body)) . "\r\n";
    $mail .= "--$b\r\nContent-Type: {$photo['mime']}; name=\"{$photo['name']}\"\r\nContent-Transfer-Encoding: base64\r\n";
    $mail .= "Content-Disposition: attachment; filename=\"{$photo['name']}\"\r\n\r\n";
    $mail .= chunk_split(base64_encode($photo['data'])) . "\r\n--$b--";
} else {
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $mail = $body;
}

$sent = @mail($TO, $subject, $mail, implode("\r\n", $headers), '-f' . $FROM);

if ($sent) {
    send_confirmation($email, $lang, $type, $FROM, $TO);
}
reply($sent ? 200 : 500, ['ok' => (bool) $sent]);

/**
 * Confirmation au visiteur, dans la langue du site.
 * Textes fixes uniquement : le formulaire ne peut pas servir à écrire à un tiers.
 */
function send_confirmation(string $to, string $lang, string $type, string $from, string $replyTo): void
{
    $T = [
        'fr' => [
            'subject' => 'Votre demande – DYRO STUDIOS', 'hello' => 'Bonjour,',
            'thanks' => 'Merci pour votre demande. Nous l’avons bien reçue et vous répondons par email.',
            'trial' => 'Pour votre essai gratuit, nous vérifions d’abord que la photo s’y prête, puis nous vous envoyons le résultat.',
            'reply' => 'Une précision ou une autre photo ? Répondez simplement à cet email, ou appelez le +352 691 349 632.',
            'footer' => 'Vous recevez ce message car votre adresse a été saisie dans le formulaire de dyrostudios.com. Si ce n’est pas vous, ignorez-le.',
        ],
        'de' => [
            'subject' => 'Ihre Anfrage – DYRO STUDIOS', 'hello' => 'Guten Tag,',
            'thanks' => 'Vielen Dank für Ihre Anfrage. Wir haben sie erhalten und antworten Ihnen per E-Mail.',
            'trial' => 'Für Ihren kostenlosen Test prüfen wir zuerst, ob sich das Foto eignet, und senden Ihnen dann das Ergebnis.',
            'reply' => 'Eine Ergänzung oder ein weiteres Foto? Antworten Sie einfach auf diese E-Mail oder rufen Sie +352 691 349 632 an.',
            'footer' => 'Sie erhalten diese Nachricht, weil Ihre Adresse im Formular auf dyrostudios.com eingegeben wurde. Falls Sie das nicht waren, ignorieren Sie sie bitte.',
        ],
        'en' => [
            'subject' => 'Your request – DYRO STUDIOS', 'hello' => 'Hello,',
            'thanks' => 'Thank you for your request. We have received it and will reply by email.',
            'trial' => 'For your free trial, we first check that the photo is suitable, then send you the result.',
            'reply' => 'Anything to add, or another photo? Just reply to this email or call +352 691 349 632.',
            'footer' => 'You are receiving this message because your address was entered in the form on dyrostudios.com. If this wasn’t you, please ignore it.',
        ],
    ];
    $t = $T[$lang] ?? $T['fr'];
    $lines = [$t['hello'], '', $t['thanks']];
    if ($type === 'essai') {
        $lines[] = $t['trial'];
    }
    array_push($lines, '', $t['reply'], '', 'DYRO STUDIOS', 'https://dyrostudios.com', '', '—', $t['footer']);
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('DYRO STUDIOS') . '?= <' . $from . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-replied',
    ];
    @mail($to, '=?UTF-8?B?' . base64_encode($t['subject']) . '?=', implode("\n", $lines), implode("\r\n", $headers), '-f' . $from);
}
