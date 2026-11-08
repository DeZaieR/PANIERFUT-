<?php
/**
 * DYRO WEB — assistant IA du site (widget de la barre d'actions, assets/js/assistant.js).
 *
 * GET  ?status  → {"on": true|false}  (le bouton n'apparaît que si l'assistant est configuré)
 * POST {messages:[{role,content}], lang, page, hp} → {"ok":true,"reply":"…","lead":bool}
 *
 * Configuration privée, hors dépôt : api/.assistant.php (voir docs/assistant-ia.md)
 *   <?php return ['key' => 'sk-ant-…', 'model' => 'claude-haiku-5-5', 'daily_cap' => 300];
 * Sans ce fichier, l'assistant est désactivé et le bouton reste caché.
 *
 * Confidentialité : aucune conversation n'est enregistrée sur le serveur. Les messages sont
 * envoyés à l'API Anthropic pour générer la réponse ; si le visiteur accepte d'être recontacté,
 * la conversation est envoyée par email à contact@dyroweb.online.
 * L'adresse IP n'est conservée que sous forme d'empreinte non réversible, 24 heures au plus (limite anti-abus).
 */
declare(strict_types=1);

require __DIR__ . '/_assistant_kb.php';

const AS_TO = 'contact@dyroweb.online';
const AS_FROM = 'noreply@dyroweb.online';
const AS_MAX_MESSAGES = 16;      // historique envoyé au modèle
const AS_MAX_CHARS = 1000;       // par message du visiteur
const AS_MAX_TOKENS = 600;       // longueur maximale d'une réponse
const AS_PER_IP_10MIN = 20;      // messages par visiteur en 10 minutes
const AS_PER_IP_DAY = 80;        // messages par visiteur et par jour

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function as_reply(int $status, array $data, bool $cache = false): void
{
    header($cache ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function as_cfg(): ?array
{
    $f = __DIR__ . '/.assistant.php';
    if (!is_file($f)) return null;
    $c = include $f;
    return is_array($c) && !empty($c['key']) ? $c : null;
}

function as_cut(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function as_line($v, int $max): string
{
    return trim(as_cut(preg_replace('/[\r\n\t]+/', ' ', (string) $v) ?? '', $max));
}

function as_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Compteur dans le dossier temporaire, protégé par un verrou. Renvoie la valeur après incrément. */
function as_counter(string $name, int $window): array
{
    $file = rtrim(sys_get_temp_dir(), '/') . '/dyroweb-as-' . $name . '.json';
    $fp = @fopen($file, 'c+');
    $now = time();
    if (!$fp) return [0, $now];
    flock($fp, LOCK_EX);
    $hits = json_decode((string) stream_get_contents($fp), true) ?: [];
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - $window));
    $hits[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);
    return [count($hits), $now];
}

function as_key(string $what): string
{
    return substr(hash('sha256', 'dyroweb-as|' . date('Y-m-d') . '|' . $what), 0, 32);
}

function as_cleanup(): void
{
    $now = time();
    foreach (glob(rtrim(sys_get_temp_dir(), '/') . '/dyroweb-as-*.json') ?: [] as $old) {
        if (@filemtime($old) < $now - 86400) @unlink($old);
    }
}

/** Nombre d'appels à l'API aujourd'hui (plafond de coût). */
function as_daily_calls(bool $increment): int
{
    $file = rtrim(sys_get_temp_dir(), '/') . '/dyroweb-as-day-' . date('Y-m-d') . '.cnt';
    $fp = @fopen($file, 'c+');
    if (!$fp) return 0;
    flock($fp, LOCK_EX);
    $n = (int) stream_get_contents($fp);
    if ($increment) {
        $n++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $n);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $n;
}

/** Appel à l'API Messages d'Anthropic. */
function as_call(array $cfg, array $payload): ?array
{
    $ch = curl_init($cfg['endpoint'] ?? 'https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $cfg['key'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log('DYRO assistant : API ' . $code . ' ' . $err . ' ' . as_cut((string) $body, 300));
        return null;
    }
    $json = json_decode((string) $body, true);
    return is_array($json) ? $json : null;
}

/** Envoie la demande du visiteur par email à DYRO WEB. Renvoie le texte du résultat pour le modèle. */
function as_lead(array $in, array $history, string $lang, string $page): array
{
    $nom = as_line($in['nom'] ?? '', 120);
    $email = as_line($in['email'] ?? '', 160);
    $tel = as_line($in['telephone'] ?? '', 40);
    $emailOk = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    $telOk = preg_match('/\d{6,}/', preg_replace('/\D+/', '', $tel) ?? '') === 1;
    if (($in['accord_recontact'] ?? false) !== true) {
        return [false, "Erreur : le visiteur n'a pas encore accepté explicitement d'être recontacté. Demande-lui son accord avant de transmettre."];
    }
    if ($nom === '' || (!$emailOk && !$telOk)) {
        return [false, 'Erreur : il manque le nom ou un moyen de contact valide (email ou téléphone). Demande-le au visiteur.'];
    }
    // Une seule demande transmise par visiteur et par heure (anti-abus)
    [$n] = as_counter('lead-' . as_key(as_ip()), 3600);
    if ($n > 1) {
        return [true, 'Demande déjà transmise : ne la renvoie pas. Confirme simplement au visiteur que Dany le recontacte.'];
    }

    date_default_timezone_set('Europe/Luxembourg');
    $rows = [
        'Nom' => $nom,
        'Entreprise' => as_line($in['entreprise'] ?? '', 120),
        'Email' => $emailOk ? $email : '',
        'Téléphone' => $tel,
        'Activité' => as_line($in['activite'] ?? '', 120),
        'Formule' => as_line($in['formule'] ?? '', 40),
        'Aide SME Packages' => as_line($in['aide_sme'] ?? '', 20),
        'Langues' => as_line($in['langues'] ?? '', 80),
        'Délai' => as_line($in['delai'] ?? '', 80),
    ];
    $body = "Nouvelle demande reçue par l'assistant IA de dyroweb.online.\n\n";
    foreach ($rows as $k => $v) {
        if ($v !== '') $body .= $k . ' : ' . $v . "\n";
    }
    $body .= "\nBesoin :\n" . trim(as_cut(strip_tags((string) ($in['besoin'] ?? '')), 1500)) . "\n";
    $body .= "\n--- Conversation ---\n";
    foreach ($history as $m) {
        $who = $m['role'] === 'user' ? 'Visiteur' : 'Assistant';
        $body .= $who . " : " . trim(strip_tags($m['content'])) . "\n\n";
    }
    $body .= "---\nLangue du site : " . strtoupper($lang) . "\nPage : " . $page . "\nReçu le : " . date('d/m/Y H:i') . "\nAccord pour être recontacté : oui (donné dans la conversation)\n";

    $headers = [
        'From: =?UTF-8?B?' . base64_encode('DYRO WEB · Assistant IA') . '?= <' . AS_FROM . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
    ];
    if ($emailOk) $headers[] = 'Reply-To: ' . $email;
    require_once __DIR__ . '/_mail.php';
    $subject = '=?UTF-8?B?' . base64_encode('Assistant IA – demande de ' . $nom) . '?=';
    $sent = send_mail(AS_TO, $subject, $body, $headers, AS_FROM);
    return $sent
        ? [true, 'Demande transmise avec succès à DYRO WEB.']
        : [false, "Erreur technique : la demande n'a pas pu être envoyée. Propose l'email contact@dyroweb.online ou le téléphone +352 691 349 632."];
}

/* ------------------------------------------------------------------ */

$cfg = as_cfg();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    as_reply(200, ['on' => $cfg !== null], true);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    as_reply(405, ['ok' => false, 'error' => 'method']);
}
if ($cfg === null) {
    as_reply(503, ['ok' => false, 'error' => 'off']);
}

$data = json_decode((string) file_get_contents('php://input', false, null, 0, 40000), true);
if (!is_array($data) || !is_array($data['messages'] ?? null)) {
    as_reply(400, ['ok' => false, 'error' => 'format']);
}
if (!empty($data['hp'])) {
    as_reply(200, ['ok' => true, 'reply' => '', 'lead' => false]); // robot
}

$lang = is_string($data['lang'] ?? null) && isset(ASSISTANT_LANG_NAMES[$data['lang']]) ? $data['lang'] : 'fr';
$page = as_line($data['page'] ?? '/', 200);

// Historique : rôles alternés, messages successifs du même rôle fusionnés, commence par le visiteur
$history = [];
foreach (array_slice($data['messages'], -40) as $m) {
    if (!is_array($m) || !in_array($m['role'] ?? '', ['user', 'assistant'], true) || !is_string($m['content'] ?? null)) continue;
    $text = trim(str_replace("\r", '', $m['content']));
    if ($text === '') continue;
    $text = as_cut($text, $m['role'] === 'user' ? AS_MAX_CHARS : 3000);
    $last = count($history) - 1;
    if ($last >= 0 && $history[$last]['role'] === $m['role']) $history[$last]['content'] .= "\n\n" . $text;
    else $history[] = ['role' => $m['role'], 'content' => $text];
}
$history = array_slice($history, -AS_MAX_MESSAGES);
while ($history && $history[0]['role'] !== 'user') array_shift($history);
if (!$history || end($history)['role'] !== 'user') {
    as_reply(400, ['ok' => false, 'error' => 'format']);
}

// Limites anti-abus et plafond de coût
$ipKey = as_key(as_ip());
[$n10] = as_counter('ip10-' . $ipKey, 600);
[$nDay] = as_counter('ipday-' . $ipKey, 86400);
as_cleanup();
if ($n10 > AS_PER_IP_10MIN || $nDay > AS_PER_IP_DAY) {
    as_reply(429, ['ok' => false, 'error' => 'rate']);
}
if (as_daily_calls(false) >= (int) ($cfg['daily_cap'] ?? 300)) {
    as_reply(503, ['ok' => false, 'error' => 'cap']);
}

$system = assistant_system_prompt()
    . "\n\n# Contexte de cette conversation\nLangue du site affichée : " . ASSISTANT_LANG_NAMES[$lang]
    . " (réponds dans la langue du visiteur). Page consultée : " . $page . '.';

$messages = $history;
$reply = '';
$lead = false;
for ($round = 0; $round < 3; $round++) {
    as_daily_calls(true);
    $res = as_call($cfg, [
        'model' => $cfg['model'] ?? 'claude-haiku-5-5',
        'max_tokens' => AS_MAX_TOKENS,
        'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
        'tools' => assistant_tools(),
        'messages' => $messages,
    ]);
    if ($res === null || !is_array($res['content'] ?? null)) {
        as_reply(502, ['ok' => false, 'error' => 'upstream']);
    }
    $texts = [];
    $results = [];
    foreach ($res['content'] as $block) {
        if (($block['type'] ?? '') === 'text') $texts[] = (string) $block['text'];
        if (($block['type'] ?? '') === 'tool_use') {
            if (($block['name'] ?? '') === 'transmettre_demande' && !$lead) {
                [$ok, $msg] = as_lead(is_array($block['input'] ?? null) ? $block['input'] : [], $history, $lang, $page);
                $lead = $lead || $ok;
            } else {
                [$ok, $msg] = [false, 'Outil inconnu ou déjà utilisé.'];
            }
            $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) ($block['id'] ?? ''), 'content' => $msg, 'is_error' => !$ok];
        }
    }
    $reply = trim(implode("\n\n", $texts));
    if (($res['stop_reason'] ?? '') !== 'tool_use' || !$results) break;
    $messages[] = ['role' => 'assistant', 'content' => $res['content']];
    $messages[] = ['role' => 'user', 'content' => $results];
}

if ($reply === '') {
    as_reply(502, ['ok' => false, 'error' => 'empty']);
}
as_reply(200, ['ok' => true, 'reply' => $reply, 'lead' => $lead]);
