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

/**
 * Limite anti-abus : 3 demandes par 15 minutes et par visiteur.
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
    $file = $dir . '/dyroweb-devis-' . substr(hash('sha256', 'dyroweb|' . date('Y-m-d') . '|' . $ip), 0, 32) . '.json';
    $now  = time();
    $hits = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - 900));
    if (count($hits) >= 3) {
        return true;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    foreach (glob($dir . '/dyroweb-devis-*.json') ?: [] as $old) {
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

if ($sent) {
    send_confirmation($email, $lang, $data, $FROM, $TO);
}

reply($sent ? 200 : 500, ['ok' => (bool) $sent]);

/**
 * Email de confirmation au prospect, dans la langue du site.
 * Textes fixes uniquement (aucun texte saisi par le visiteur n'est recopié) :
 * le formulaire ne peut pas servir à envoyer des messages à un tiers.
 */
function send_confirmation(string $to, string $lang, array $data, string $from, string $replyTo): void
{
    $T = [
        'fr' => [
            'subject' => 'Votre demande de devis – DYRO WEB', 'hello' => 'Bonjour,',
            'thanks' => "Merci pour votre demande. Nous l'avons bien reçue et revenons vers vous avec une proposition claire, en trois options.",
            'recap' => 'Récapitulatif', 'type' => 'Projet', 'langs' => 'Langues', 'deadline' => 'Délai souhaité',
            'reply' => 'Pour toute question, répondez simplement à cet email ou appelez le +352 691 349 632.',
            'footer' => "Vous recevez ce message car votre adresse a été saisie dans le formulaire de devis de dyroweb.online. Si ce n'est pas vous, ignorez-le.",
            'types' => ['landing' => 'Landing page', 'pro' => 'Site vitrine Pro', 'premium' => 'Premium multilingue', 'ecommerce' => 'E-commerce', 'unsure' => 'À définir ensemble'],
            'deadlines' => ['asap' => 'Dès que possible', 'soon' => "D'ici 1 à 2 mois", 'later' => 'Dans 3 mois ou plus', 'none' => 'Pas de date fixe'],
        ],
        'de' => [
            'subject' => 'Ihre Angebotsanfrage – DYRO WEB', 'hello' => 'Guten Tag,',
            'thanks' => 'Vielen Dank für Ihre Anfrage. Wir haben sie erhalten und melden uns mit einem klaren Angebot mit drei Optionen.',
            'recap' => 'Zusammenfassung', 'type' => 'Projekt', 'langs' => 'Sprachen', 'deadline' => 'Gewünschter Zeitrahmen',
            'reply' => 'Bei Fragen antworten Sie einfach auf diese E-Mail oder rufen Sie +352 691 349 632 an.',
            'footer' => 'Sie erhalten diese Nachricht, weil Ihre Adresse im Angebotsformular von dyroweb.online eingegeben wurde. Falls Sie das nicht waren, ignorieren Sie sie bitte.',
            'types' => ['landing' => 'Landingpage', 'pro' => 'Firmenwebsite Pro', 'premium' => 'Premium mehrsprachig', 'ecommerce' => 'Onlineshop', 'unsure' => 'Gemeinsam festzulegen'],
            'deadlines' => ['asap' => 'So bald wie möglich', 'soon' => 'In 1 bis 2 Monaten', 'later' => 'In 3 Monaten oder später', 'none' => 'Kein festes Datum'],
        ],
        'en' => [
            'subject' => 'Your quote request – DYRO WEB', 'hello' => 'Hello,',
            'thanks' => "Thank you for your request. We've received it and will get back to you with a clear proposal with three options.",
            'recap' => 'Summary', 'type' => 'Project', 'langs' => 'Languages', 'deadline' => 'Preferred timeline',
            'reply' => 'If you have any questions, simply reply to this email or call +352 691 349 632.',
            'footer' => "You're receiving this message because your address was entered in the quote form on dyroweb.online. If this wasn't you, please ignore it.",
            'types' => ['landing' => 'Landing page', 'pro' => 'Business website Pro', 'premium' => 'Premium multilingual', 'ecommerce' => 'E-commerce', 'unsure' => 'To be defined together'],
            'deadlines' => ['asap' => 'As soon as possible', 'soon' => 'Within 1 to 2 months', 'later' => 'In 3 months or more', 'none' => 'No fixed date'],
        ],
        'pt' => [
            'subject' => 'O seu pedido de orçamento – DYRO WEB', 'hello' => 'Olá,',
            'thanks' => 'Obrigado pelo seu pedido. Recebemo-lo e voltaremos a contactá-lo com uma proposta clara, com três opções.',
            'recap' => 'Resumo', 'type' => 'Projeto', 'langs' => 'Línguas', 'deadline' => 'Prazo pretendido',
            'reply' => 'Para qualquer questão, basta responder a este email ou ligar para +352 691 349 632.',
            'footer' => 'Recebe esta mensagem porque o seu endereço foi introduzido no formulário de orçamento de dyroweb.online. Se não foi você, ignore-a.',
            'types' => ['landing' => 'Landing page', 'pro' => 'Site institucional Pro', 'premium' => 'Premium multilingue', 'ecommerce' => 'Loja online', 'unsure' => 'A definir em conjunto'],
            'deadlines' => ['asap' => 'O mais rápido possível', 'soon' => 'Dentro de 1 a 2 meses', 'later' => 'Daqui a 3 meses ou mais', 'none' => 'Sem data fixa'],
        ],
        'lb' => [
            'subject' => 'Är Offer-Ufro – DYRO WEB', 'hello' => 'Moien,',
            'thanks' => 'Merci fir Är Ufro. Mir hunn se kritt a mellen eis mat enger kloerer Offer mat dräi Optiounen.',
            'recap' => 'Resumé', 'type' => 'Projet', 'langs' => 'Sproochen', 'deadline' => 'Gewënschten Délai',
            'reply' => 'Bei Froen äntwert einfach op dës E-Mail oder rufft op +352 691 349 632 un.',
            'footer' => 'Dir kritt dëse Message, well Är Adress am Offer-Formulaire vun dyroweb.online agi gouf. Wann Dir dat net wart, ignoréiert en w.e.g.',
            'types' => ['landing' => 'Landing Page', 'pro' => 'Firmewebsäit Pro', 'premium' => 'Premium méisproocheg', 'ecommerce' => 'Online-Buttek', 'unsure' => 'Zesumme festzeleeën'],
            'deadlines' => ['asap' => 'Esou séier wéi méiglech', 'soon' => 'An 1 bis 2 Méint', 'later' => 'An 3 Méint oder méi spéit', 'none' => 'Keen fixen Datum'],
        ],
    ];
    $t = $T[$lang] ?? $T['fr'];
    $sep = ($lang === 'fr' || !isset($T[$lang])) ? ' : ' : ': '; // espace avant « : » en français seulement
    $codes = ['fr' => 'FR', 'de' => 'DE', 'en' => 'EN', 'pt' => 'PT', 'lb' => 'LU'];

    $type = is_string($data['type'] ?? null) ? $data['type'] : '';
    $langs = array_values(array_filter(is_array($data['langs'] ?? null) ? $data['langs'] : [], static fn($l) => is_string($l) && isset($codes[$l])));
    $deadline = is_string($data['deadline'] ?? null) ? $data['deadline'] : '';

    $lines = [$t['hello'], '', $t['thanks'], '', rtrim($t['recap'] . $sep)];
    if (isset($t['types'][$type])) {
        $lines[] = '– ' . $t['type'] . $sep . $t['types'][$type];
    }
    if ($langs) {
        $lines[] = '– ' . $t['langs'] . $sep . implode(', ', array_map(static fn($l) => $codes[$l], $langs));
    }
    if (isset($t['deadlines'][$deadline])) {
        $lines[] = '– ' . $t['deadline'] . $sep . $t['deadlines'][$deadline];
    }
    array_push($lines, '', $t['reply'], '', 'DYRO WEB', 'https://dyroweb.online', '', '—', $t['footer']);

    $headers = [
        'From: =?UTF-8?B?' . base64_encode('DYRO WEB') . '?= <' . $from . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-replied',
    ];
    @mail($to, '=?UTF-8?B?' . base64_encode($t['subject']) . '?=', implode("\n", $lines), implode("\r\n", $headers), '-f' . $from);
}
