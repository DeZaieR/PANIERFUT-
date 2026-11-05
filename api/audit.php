<?php
/**
 * Audit gratuit de site (page audit.html).
 *
 * POST {"url": "...", "lang": "fr"}                → analyse la page d'accueil et renvoie les résultats (JSON).
 * POST {"action": "lead", ...}                    → demande du rapport détaillé : email à DYRO WEB + confirmation.
 *
 * Sécurité : seules les adresses publiques en http(s) sur les ports 80/443 sont analysées (pas de réseau
 * interne), l'adresse IP est vérifiée à chaque redirection, 1,5 Mo et 8 s au maximum par page.
 * Rien n'est enregistré pour un simple audit ; une demande de rapport est envoyée par email.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

const AUDIT_TO = 'contact@dyroweb.online';
const AUDIT_FROM = 'noreply@dyroweb.online';
const AUDIT_UA = 'DyroWebAudit/1.0 (+https://dyroweb.online/audit.html)';
const AUDIT_MAX = 1572864;

function out(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'method']);
$host = preg_replace(['/^www\./', '/:\d+$/'], '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST))) !== $host) out(403, ['ok' => false, 'error' => 'origin']);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'format']);

/** Limite par visiteur (empreinte de l'IP, jamais l'IP elle-même), fenêtre glissante. */
function limited(string $bucket, int $max, int $window): bool
{
    $ip = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    if ($ip === '') return false;
    $dir = rtrim(sys_get_temp_dir(), '/');
    $file = $dir . '/dyroweb-' . $bucket . '-' . substr(hash('sha256', $bucket . '|' . date('Y-m-d') . '|' . $ip), 0, 32) . '.json';
    $now = time();
    $hits = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - $window));
    if (count($hits) >= $max) return true;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

$langs = ['fr', 'de', 'en', 'pt', 'lb'];
$lang = in_array($in['lang'] ?? '', $langs, true) ? $in['lang'] : 'fr';

/* =====================================================================
   1. Demande du rapport détaillé
   ===================================================================== */
if (($in['action'] ?? '') === 'lead') {
    if (!empty($in['website'])) out(200, ['ok' => true]); // champ piège
    if (limited('audit-lead', 3, 900)) out(429, ['ok' => false, 'error' => 'rate']);
    $line = static fn($v, int $max) => mb_substr(trim((string)preg_replace('/[\r\n\t]+/', ' ', is_scalar($v) ? (string)$v : '')), 0, $max);
    $name = $line($in['name'] ?? '', 80);
    $email = $line($in['email'] ?? '', 120);
    $company = $line($in['company'] ?? '', 120);
    $phone = $line($in['phone'] ?? '', 40);
    $url = $line($in['url'] ?? '', 300);
    $score = max(0, min(100, (int)($in['score'] ?? 0)));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($in['consent'])) out(422, ['ok' => false, 'error' => 'fields']);
    $checks = [];
    foreach (array_slice(is_array($in['checks'] ?? null) ? $in['checks'] : [], 0, 30) as $c) {
        if (!is_array($c)) continue;
        $st = in_array($c['status'] ?? '', ['ok', 'warn', 'bad'], true) ? $c['status'] : 'warn';
        $checks[] = ['label' => $line($c['label'] ?? '', 80), 'status' => $st, 'value' => $line($c['value'] ?? '', 120)];
    }
    require __DIR__ . '/_mail.php';
    $sym = ['ok' => '✓', 'warn' => '!', 'bad' => '✗'];
    $body = "Nouvelle demande de rapport d’audit\n\n"
        . "Site analysé : $url\nScore : $score / 100\n\n"
        . "Nom : $name\nEntreprise : " . ($company ?: '—') . "\nEmail : $email\nTéléphone : " . ($phone ?: '—') . "\nLangue du site : " . strtoupper($lang) . "\n\n"
        . "RÉSULTATS\n" . str_repeat('─', 28) . "\n";
    foreach ($checks as $c) $body .= $sym[$c['status']] . ' ' . $c['label'] . ($c['value'] !== '' ? ' — ' . $c['value'] : '') . "\n";
    $body .= "\nPromesse affichée : rapport détaillé sous 48 h.\nRépondez directement à cet email pour écrire au prospect.\n";
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('DYRO WEB · Audit') . '?= <' . AUDIT_FROM . '>',
        'Reply-To: ' . $email,
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
    ];
    $subject = '=?UTF-8?B?' . base64_encode("Audit demandé : $url ($score/100)") . '?=';
    $sent = send_mail(AUDIT_TO, $subject, $body, $headers, AUDIT_FROM);
    if ($sent) {
        $T = [
            'fr' => ['Votre audit de site – DYRO WEB', 'Bonjour %s,', 'Merci pour votre demande. Votre rapport détaillé, avec les corrections à faire en priorité, vous sera envoyé sous 48 h.', 'Site analysé : %s · score : %d / 100', 'Vous préférez en parler ? Réservez un appel de 20 minutes :', 'Bien cordialement,', 'L’équipe DYRO WEB', 'Vous recevez ce message car votre adresse a été saisie sur dyroweb.online. Si ce n’est pas vous, ignorez-le.'],
            'de' => ['Ihr Website-Audit – DYRO WEB', 'Guten Tag %s,', 'vielen Dank für Ihre Anfrage. Ihren ausführlichen Bericht mit den wichtigsten Verbesserungen erhalten Sie innerhalb von 48 Stunden.', 'Geprüfte Website: %s · Ergebnis: %d / 100', 'Lieber persönlich? Buchen Sie ein 20-minütiges Gespräch:', 'Mit freundlichen Grüßen', 'Ihr DYRO WEB Team', 'Sie erhalten diese Nachricht, weil Ihre Adresse auf dyroweb.online eingegeben wurde. Falls Sie das nicht waren, ignorieren Sie sie bitte.'],
            'en' => ['Your website audit – DYRO WEB', 'Hello %s,', 'Thank you for your request. Your detailed report, with the priority fixes, will be sent to you within 48 hours.', 'Website analysed: %s · score: %d / 100', 'Prefer to talk? Book a 20-minute call:', 'Kind regards,', 'The DYRO WEB team', 'You are receiving this message because your address was entered on dyroweb.online. If this was not you, please ignore it.'],
            'pt' => ['A auditoria do seu site – DYRO WEB', 'Olá %s,', 'Obrigado pelo seu pedido. O relatório detalhado, com as correções prioritárias, será enviado em 48 horas.', 'Site analisado: %s · pontuação: %d / 100', 'Prefere falar connosco? Marque uma chamada de 20 minutos:', 'Com os melhores cumprimentos,', 'A equipa DYRO WEB', 'Recebe esta mensagem porque o seu endereço foi introduzido em dyroweb.online. Se não foi você, ignore-a.'],
            'lb' => ['Ären Audit vun der Websäit – DYRO WEB', 'Moien %s,', 'Merci fir Är Ufro. Ären detailléierte Rapport mat de wichtegste Verbesserungen kritt Dir bannent 48 Stonnen.', 'Analyséiert Websäit: %s · Resultat: %d / 100', 'Léiwer schwätzen? Reservéiert en Uruff vun 20 Minutten:', 'Mat beschte Gréiss,', 'D’DYRO WEB Team', 'Dir kritt dëse Message, well Är Adress op dyroweb.online agi gouf. Wann Dir dat net wart, ignoréiert en w.e.g.'],
        ][$lang];
        $text = sprintf($T[1], $name) . "\n\n" . $T[2] . "\n\n" . sprintf($T[3], $url, $score) . "\n\n" . $T[4] . " https://cal.com/dyroweb/appel-web\n\n"
            . $T[5] . "\n" . $T[6] . "\ncontact@dyroweb.online · +352 691 349 632 · dyroweb.online\n\n—\n" . $T[7] . "\n";
        send_mail($email, '=?UTF-8?B?' . base64_encode($T[0]) . '?=', $text, [
            'From: =?UTF-8?B?' . base64_encode('DYRO WEB') . '?= <' . AUDIT_FROM . '>',
            'Reply-To: ' . AUDIT_TO,
            'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-replied',
        ], AUDIT_FROM);
        if (is_file(__DIR__ . '/_stats.php')) {
            require_once __DIR__ . '/_stats.php';
            stats_log(['t' => 'ev', 'n' => 'form', 'm' => 'audit', 'p' => ($lang === 'fr' ? '' : "/$lang") . '/audit.html']);
        }
    }
    out($sent ? 200 : 500, ['ok' => (bool)$sent]);
}

/* =====================================================================
   2. Analyse d'un site
   ===================================================================== */
if (limited('audit', 12, 3600)) out(429, ['ok' => false, 'error' => 'rate']);

$raw = trim((string)($in['url'] ?? ''));
if ($raw === '' || mb_strlen($raw) > 300) out(422, ['ok' => false, 'error' => 'url']);
if (!preg_match('#^https?://#i', $raw)) $raw = 'https://' . $raw;

/** Adresse publique uniquement : refuse localhost, réseaux privés, réservés et ports inhabituels. */
function safe_target(string $url): ?array
{
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return null;
    if (isset($p['user']) || isset($p['pass'])) return null;
    $scheme = strtolower($p['scheme']);
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) return null;
    $h = strtolower(rtrim($p['host'], '.'));
    if ($h === 'localhost' || str_ends_with($h, '.local') || str_ends_with($h, '.internal') || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $h)) return null;
    $ips = @gethostbynamel($h) ?: [];
    foreach ((array)@dns_get_record($h, DNS_AAAA) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    if (!$ips) return null;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    }
    return ['url' => $url, 'host' => $h, 'port' => $port, 'scheme' => $scheme, 'ip' => $ips[0]];
}

/** Télécharge une adresse en suivant au plus 4 redirections, chacune revérifiée. */
function fetch(string $url, int $max = AUDIT_MAX): ?array
{
    $t0 = microtime(true);
    for ($i = 0; $i < 5; $i++) {
        $t = safe_target($url);
        if (!$t) return null;
        $buf = '';
        $hdrs = [];
        $ch = curl_init($url);
        $ip = str_contains($t['ip'], ':') ? '[' . $t['ip'] . ']' : $t['ip'];
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$t['host'] . ':' . $t['port'] . ':' . $ip],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => AUDIT_UA, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: fr,de;q=0.8,en;q=0.6'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$hdrs) { $kv = explode(':', $line, 2); if (count($kv) === 2) $hdrs[strtolower(trim($kv[0]))] = trim($kv[1]); return strlen($line); },
            CURLOPT_WRITEFUNCTION => static function ($c, $data) use (&$buf, $max) { $buf .= $data; return strlen($buf) > $max ? 0 : strlen($data); },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ttfb = (float)curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && !empty($hdrs['location'])) {
            $loc = $hdrs['location'];
            if (!preg_match('#^https?://#i', $loc)) {
                $base = $t['scheme'] . '://' . $t['host'] . ($t['port'] !== ($t['scheme'] === 'https' ? 443 : 80) ? ':' . $t['port'] : '');
                $loc = $base . ($loc[0] === '/' ? $loc : '/' . $loc);
            }
            $url = $loc;
            continue;
        }
        if ($code === 0) return null;
        return ['url' => $url, 'code' => $code, 'body' => $buf, 'headers' => $hdrs, 'ttfb' => $ttfb, 'total' => microtime(true) - $t0];
    }
    return null;
}

$page = fetch($raw);
if (!$page) out(422, ['ok' => false, 'error' => 'unreachable']);
if ($page['code'] >= 400) out(422, ['ok' => false, 'error' => 'status', 'code' => $page['code']]);

$h = $page['body'];
$final = $page['url'];
$fp = parse_url($final);
$origin = $fp['scheme'] . '://' . $fp['host'];
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8">' . $h, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
$xp = new DOMXPath($doc);
$q = static fn(string $x) => $xp->query($x);
$meta = static function (string $attr, string $val) use ($xp): string {
    $n = $xp->query("//meta[translate(@$attr,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='" . $val . "']/@content");
    return $n && $n->length ? trim($n->item(0)->nodeValue) : '';
};

$checks = [];
$add = static function (string $id, string $cat, string $status, string $value = '', ?float $num = null) use (&$checks) { $checks[] = compact('id', 'cat', 'status', 'value') + ($num === null ? [] : ['num' => $num]); };

// Bases
$add('https', 'base', $fp['scheme'] === 'https' ? 'ok' : 'bad', $fp['scheme'] === 'https' ? 'HTTPS' : 'HTTP');
$secs = round($page['ttfb'], 2);
$add('speed', 'base', $secs < 0.8 ? 'ok' : ($secs < 2 ? 'warn' : 'bad'), number_format($secs, 2, ',', '') . ' s', $secs);
$kb = (int)round(strlen($h) / 1024);
$add('weight', 'base', $kb < 200 ? 'ok' : ($kb < 600 ? 'warn' : 'bad'), $kb . ' Ko', (float)$kb);

// Google
$titleN = $q('//title');
$title = $titleN && $titleN->length ? trim(preg_replace('/\s+/', ' ', $titleN->item(0)->textContent)) : '';
$tl = mb_strlen($title);
$add('title', 'seo', $tl === 0 ? 'bad' : ($tl >= 15 && $tl <= 65 ? 'ok' : 'warn'), $tl ? $tl . ' car.' : '', $tl ? (float)$tl : null);
$desc = $meta('name', 'description');
$dl = mb_strlen($desc);
$add('description', 'seo', $dl === 0 ? 'bad' : ($dl >= 70 && $dl <= 165 ? 'ok' : 'warn'), $dl ? $dl . ' car.' : '', $dl ? (float)$dl : null);
$h1 = $q('//h1');
$h1n = $h1 ? $h1->length : 0;
$add('h1', 'seo', $h1n === 1 ? 'ok' : ($h1n === 0 ? 'bad' : 'warn'), (string)$h1n);
$robots = strtolower($meta('name', 'robots') . ' ' . ($page['headers']['x-robots-tag'] ?? ''));
$add('index', 'seo', str_contains($robots, 'noindex') ? 'bad' : 'ok');
$sm = fetch($origin . '/sitemap.xml', 300000);
$hasSm = $sm && $sm['code'] === 200 && preg_match('/<(urlset|sitemapindex)\b/i', $sm['body']);
$add('sitemap', 'seo', $hasSm ? 'ok' : 'warn');
$ld = $q("//script[@type='application/ld+json']");
$add('schema', 'seo', $ld && $ld->length ? 'ok' : 'warn');
$ogT = $meta('property', 'og:title');
$ogI = $meta('property', 'og:image');
$add('social', 'seo', $ogT && $ogI ? 'ok' : 'warn');

// Mobile
$vp = strtolower($meta('name', 'viewport'));
$add('viewport', 'mobile', str_contains($vp, 'width=device-width') ? 'ok' : 'bad');
$imgs = $q('//img');
$ni = $imgs ? $imgs->length : 0;
$noalt = 0;
if ($imgs) foreach ($imgs as $im) if (!$im->hasAttribute('alt')) $noalt++;
$ratio = $ni ? $noalt / $ni : 0;
$add('alt', 'mobile', $ratio == 0 ? 'ok' : ($ratio < 0.2 ? 'warn' : 'bad'), $ni ? ($ni - $noalt) . ' / ' . $ni : '0');

// Langues
$hl = $q("//link[translate(@rel,'ALTERNATE','alternate')='alternate'][@hreflang]/@hreflang");
$codes = [];
if ($hl) foreach ($hl as $a) { $c = strtolower(substr(trim($a->nodeValue), 0, 2)); if ($c !== 'x-' && $c !== '') $codes[$c] = 1; }
$htmlLang = '';
$hn = $q('//html/@lang');
if ($hn && $hn->length) $htmlLang = strtolower(substr(trim($hn->item(0)->nodeValue), 0, 2));
$nl = count($codes);
$add('langs', 'langs', $nl >= 2 ? 'ok' : ($htmlLang !== '' ? 'warn' : 'bad'), $nl >= 2 ? strtoupper(implode(' · ', array_keys($codes))) : strtoupper($htmlLang));

// Contact
$tel = $q("//a[starts-with(translate(@href,'TEL','tel'),'tel:')]");
$mail = $q("//a[starts-with(translate(@href,'MAILTO','mailto'),'mailto:')]");
$form = $q('//form');
$hasTel = $tel && $tel->length > 0;
$hasContact = $hasTel || ($mail && $mail->length > 0) || ($form && $form->length > 0);
$add('contact', 'contact', $hasTel ? 'ok' : ($hasContact ? 'warn' : 'bad'));

$pts = ['ok' => 1, 'warn' => 0.5, 'bad' => 0];
$sum = 0;
$cats = [];
foreach ($checks as $c) {
    $sum += $pts[$c['status']];
    $cats[$c['cat']]['n'] = ($cats[$c['cat']]['n'] ?? 0) + 1;
    $cats[$c['cat']]['p'] = ($cats[$c['cat']]['p'] ?? 0) + $pts[$c['status']];
}
foreach ($cats as $k => $v) $cats[$k] = (int)round($v['p'] / $v['n'] * 100);

out(200, ['ok' => true, 'url' => $final, 'score' => (int)round($sum / count($checks) * 100), 'cats' => $cats, 'checks' => $checks]);
