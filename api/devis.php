<?php
/**
 * DYRO WEB — réception des demandes de devis (formulaire devis.html).
 *
 * Fonctionne sur tout hébergement PHP (ex. Hostinger). Envoie la demande par email.
 * À configurer : $TO (destinataire) et $FROM (une adresse de votre domaine,
 * sinon l'email risque d'arriver en spam).
 *
 * Réponses JSON : {"ok":true} si l'email est parti, {"ok":false,...} sinon.
 * Si ce script est absent ou échoue, le formulaire bascule automatiquement
 * sur un email pré-rempli côté visiteur.
 */
declare(strict_types=1);

$TO   = 'contact@dyroweb.online';
$FROM = 'noreply@dyroweb.online';

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

$raw = file_get_contents('php://input', false, null, 0, 30000);
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    reply(400, ['ok' => false, 'error' => 'format']);
}

// Anti-spam : champ piège rempli ou formulaire envoyé trop vite → on fait semblant d'accepter
$elapsed = (int) ($data['elapsed'] ?? 0);
if (!empty($data['website']) || $elapsed < 4000) {
    reply(200, ['ok' => true]);
}

/** Texte sur une ligne, sans retour chariot (évite l'injection d'en-têtes), longueur limitée. */
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

$name    = line($data['name'] ?? '', 120);
$email   = line($data['email'] ?? '', 160);
$consent = !empty($data['consent']);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$consent) {
    reply(422, ['ok' => false, 'error' => 'invalid']);
}

$summary = block($data['summary'] ?? '', 6000);
$lang    = line($data['lang'] ?? 'fr', 5);

$body  = $summary . "\n\n";
$body .= "---\n";
$body .= 'Langue du site : ' . strtoupper($lang) . "\n";
$body .= 'Reçu le : ' . date('d/m/Y H:i') . "\n";
$body .= 'Consentement RGPD : oui' . "\n";

$subject = 'Demande de devis – ' . $name;
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$fromName = '=?UTF-8?B?' . base64_encode('DYRO WEB · Site') . '?=';

$headers = [
    'From: ' . $fromName . ' <' . $FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: DYRO WEB',
];

$sent = @mail($TO, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $FROM);

reply($sent ? 200 : 500, ['ok' => (bool) $sent]);
