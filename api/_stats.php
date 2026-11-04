<?php
/**
 * Mesure d'audience maison, commune à dyrostudios.com et dyroweb.online.
 *
 * - Aucun cookie, rien n'est stocké sur l'appareil du visiteur.
 * - Aucune adresse IP enregistrée : une visite est reconnue par une empreinte
 *   (sel aléatoire du jour + IP + navigateur) qui change chaque jour et ne peut
 *   pas être reliée d'un jour à l'autre. Le sel de la veille est supprimé.
 * - Le pays vient d'une base locale (DB-IP Lite, CC BY 4.0) : l'IP n'est
 *   envoyée à aucun service extérieur.
 * - Données : api/stats-data/AAAA-MM.jsonl (interdit au public), gardées 25 mois.
 *
 * Ce fichier est identique sur les deux sites : modifier l'un, copier dans l'autre.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Luxembourg');

const STATS_DIR = __DIR__ . '/stats-data';
const STATS_KEEP_MONTHS = 25;
const STATS_BOTS = '/bot|crawl|spider|slurp|preview|headless|lighthouse|pagespeed|pingdom|uptime|monitor|curl|wget|python|java\/|go-http|httpclient|okhttp|axios|node-fetch|facebookexternalhit|whatsapp|telegram|discord|skype|embedly|quora|vkshare|semrush|ahrefs|mj12|dataforseo|bytespider|gptbot|claude|perplexity|ccbot/i';
/** Événements acceptés depuis le navigateur ou les formulaires. */
const STATS_EVENTS = ['form', 'book', 'whatsapp', 'tel', 'email', 'mailto_quote', 'instagram', 'linkedin', 'sister', 'lang', 'quote_bar'];
/** Événements qui comptent comme une prise de contact (conversion). */
const STATS_CONV = ['form', 'book', 'whatsapp', 'tel', 'email', 'mailto_quote'];

/** Configuration privée (mot de passe du tableau de bord, clé partagée) : api/.stats.php, hors dépôt. */
function stats_cfg(): ?array
{
    $f = __DIR__ . '/.stats.php';
    if (!is_file($f)) return null;
    $c = require $f;
    return is_array($c) && !empty($c['secret']) ? $c : null;
}

function stats_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = trim(explode(',', (string)($_SERVER[$k] ?? ''))[0]);
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
    }
    return '';
}

/** Sel du jour : créé au premier passage, celui de la veille est effacé. */
function stats_salt(string $day): string
{
    $f = STATS_DIR . "/salt-$day";
    $s = @file_get_contents($f);
    if (is_string($s) && strlen($s) === 64) return $s;
    $new = bin2hex(random_bytes(32));
    $fp = @fopen($f, 'x');
    if ($fp) {
        fwrite($fp, $new);
        fclose($fp);
        stats_cleanup($day);
        return $new;
    }
    $s = @file_get_contents($f);
    return is_string($s) && strlen($s) === 64 ? $s : $new;
}

function stats_cleanup(string $day): void
{
    foreach (glob(STATS_DIR . '/salt-*') ?: [] as $f) {
        if (basename($f) !== "salt-$day") @unlink($f);
    }
    $limit = date('Y-m', strtotime('-' . STATS_KEEP_MONTHS . ' months'));
    foreach (glob(STATS_DIR . '/*.jsonl') ?: [] as $f) {
        if (basename($f, '.jsonl') < $limit) @unlink($f);
    }
}

/** Code pays ISO (LU, FR…) d'une IP, depuis api/geo/v4.bin et v6.bin. */
function stats_country(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) return '';
    if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") $bin = substr($bin, 12);
    [$file, $w, $key] = strlen($bin) === 4 ? ['v4.bin', 4, $bin] : ['v6.bin', 8, substr($bin, 0, 8)];
    $path = __DIR__ . '/geo/' . $file;
    $size = @filesize($path);
    $fp = $size ? @fopen($path, 'rb') : false;
    if (!$fp) return '';
    $rs = $w + 2;
    $lo = 0;
    $hi = intdiv($size, $rs) - 1;
    $cc = '';
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        fseek($fp, $mid * $rs);
        $rec = (string)fread($fp, $rs);
        if (strcmp(substr($rec, 0, $w), $key) <= 0) {
            $cc = substr($rec, $w, 2);
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    fclose($fp);
    return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'ZZ' ? $cc : '';
}

/** Enregistre un événement. Ne lève jamais d'erreur : la mesure ne doit rien casser. */
function stats_log(array $e): void
{
    try {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match(STATS_BOTS, $ua)) return;
        if (!is_dir(STATS_DIR) && !@mkdir(STATS_DIR, 0755, true)) return;
        $day = date('Y-m-d');
        $ip = stats_ip();
        $host = preg_replace('/^www\./', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
        $head = [
            'ts' => time(),
            'd' => $day,
            'v' => substr(hash('sha256', stats_salt($day) . '|' . $ip . '|' . $ua . '|' . $host), 0, 16),
        ];
        if (($e['t'] ?? '') === 'pv') $e['cc'] = stats_country($ip);
        $e = array_filter($head + $e, static fn($v) => $v !== '' && $v !== 0 && $v !== null);
        @file_put_contents(
            STATS_DIR . '/' . date('Y-m') . '.jsonl',
            json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $t) {
        // silencieux
    }
}

/* ------------------------------------------------------------------------
   Lecture : agrégats pour le tableau de bord
   ------------------------------------------------------------------------ */

function stats_source(string $ref, string $utm): string
{
    if ($utm !== '') {
        $u = strtolower($utm);
        $map = ['ig' => 'Instagram', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'li' => 'LinkedIn',
            'gbp' => 'Fiche Google', 'gmb' => 'Fiche Google', 'google_business' => 'Fiche Google',
            'facebook' => 'Facebook', 'fb' => 'Facebook', 'whatsapp' => 'WhatsApp', 'wa' => 'WhatsApp',
            'qr' => 'QR code', 'email' => 'Email', 'newsletter' => 'Email', 'signature' => 'Signature email',
            'google' => 'Google', 'tiktok' => 'TikTok'];
        return $map[$u] ?? ucfirst($u);
    }
    if ($ref === '') return 'Direct';
    $rules = [
        '/(^|\.)gemini\.google\./' => 'Gemini',
        '/(^|\.)google\./' => 'Google',
        '/(^|\.)bing\.com$/' => 'Bing',
        '/(^|\.)duckduckgo\.com$/' => 'DuckDuckGo',
        '/(^|\.)ecosia\.org$/' => 'Ecosia',
        '/(^|\.)qwant\.com$/' => 'Qwant',
        '/(^|\.)yahoo\./' => 'Yahoo',
        '/(^|\.)instagram\.com$/' => 'Instagram',
        '/(^|\.)facebook\.com$|^fb\.me$/' => 'Facebook',
        '/(^|\.)linkedin\.com$|^lnkd\.in$/' => 'LinkedIn',
        '/^(t\.co|x\.com|twitter\.com)$/' => 'X',
        '/(^|\.)tiktok\.com$/' => 'TikTok',
        '/(^|\.)(whatsapp\.com|wa\.me)$/' => 'WhatsApp',
        '/^(chatgpt\.com|chat\.openai\.com)$/' => 'ChatGPT',
        '/(^|\.)perplexity\.ai$/' => 'Perplexity',
        '/(^|\.)copilot\.microsoft\.com$/' => 'Copilot',
        '/^claude\.ai$/' => 'Claude',
        '/(^|\.)dyroweb\.online$/' => 'Site DYRO WEB',
        '/(^|\.)dyrostudios\.com$/' => 'Site Dyro Studios',
        '/(^|\.)(mail\.google\.com|outlook\.(live|office)\.com)$/' => 'Email',
    ];
    foreach ($rules as $re => $name) {
        if (preg_match($re, $ref)) return $name;
    }
    return $ref;
}

function stats_device(int $w): string
{
    if ($w <= 0) return 'Inconnu';
    if ($w < 768) return 'Mobile';
    if ($w < 1100) return 'Tablette';
    return 'Ordinateur';
}

/**
 * Agrège les $days derniers jours (aujourd'hui compris) et la période précédente
 * de même durée (pour les évolutions). Toutes les valeurs sont additionnables,
 * ce qui permet de fusionner deux sites.
 */
function stats_aggregate(int $days): array
{
    $today = new DateTimeImmutable('today');
    $start = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $prevStart = $today->modify('-' . (2 * $days - 1) . ' days')->format('Y-m-d');

    $daily = [];
    for ($i = $days - 1; $i >= 0; $i--) $daily[$today->modify("-$i days")->format('Y-m-d')] = ['v' => 0, 'pv' => 0, 'c' => 0];

    $visits = [];   // "jour|empreinte" => infos de la visite
    $pages = [];
    $events = [];
    $forms = [];
    $prev = ['visits' => [], 'pv' => 0, 'conv' => 0, 'cv' => []];
    $convVisits = [];
    $pv = 0;
    $conv = 0;

    $month = new DateTimeImmutable(substr($prevStart, 0, 7) . '-01');
    for (; $month <= $today; $month = $month->modify('+1 month')) {
        $f = STATS_DIR . '/' . $month->format('Y-m') . '.jsonl';
        $h = is_file($f) ? @fopen($f, 'r') : false;
        if (!$h) continue;
        while (($line = fgets($h)) !== false) {
            $e = json_decode($line, true);
            if (!is_array($e) || !isset($e['d'], $e['v'], $e['t'])) continue;
            $d = (string)$e['d'];
            if ($d < $prevStart || $d > $today->format('Y-m-d')) continue;
            $key = $d . '|' . $e['v'];
            $isPv = $e['t'] === 'pv';
            $n = (string)($e['n'] ?? '');
            $isConv = !$isPv && in_array($n, STATS_CONV, true);

            if ($d < $start) {
                if ($isPv) { $prev['pv']++; $prev['visits'][$key] = 1; }
                if ($isConv) { $prev['conv']++; $prev['cv'][$key] = 1; }
                continue;
            }
            if ($isPv) {
                $pv++;
                $daily[$d]['pv']++;
                $p = (string)($e['p'] ?? '/');
                $pages[$p] = ($pages[$p] ?? 0) + 1;
                if (!isset($visits[$key])) {
                    $visits[$key] = [
                        'src' => stats_source((string)($e['r'] ?? ''), (string)($e['us'] ?? '')),
                        'camp' => (string)($e['uc'] ?? ''),
                        'dev' => stats_device((int)($e['w'] ?? 0)),
                        'cc' => (string)($e['cc'] ?? ''),
                        'l' => (string)($e['l'] ?? ''),
                        'entry' => $p,
                        'pv' => 0,
                    ];
                    $daily[$d]['v']++;
                }
                $visits[$key]['pv']++;
            } else {
                $events[$n] = ($events[$n] ?? 0) + 1;
                if ($n === 'form') {
                    $m = (string)($e['m'] ?? 'autre');
                    $forms[$m] = ($forms[$m] ?? 0) + 1;
                }
                if ($isConv) {
                    $conv++;
                    $daily[$d]['c']++;
                    $convVisits[$key] = 1;
                }
            }
        }
        fclose($h);
    }

    $count = static function (string $field) use ($visits, $convVisits): array {
        $out = [];
        foreach ($visits as $k => $v) {
            $name = $v[$field] !== '' ? $v[$field] : '—';
            $out[$name]['v'] = ($out[$name]['v'] ?? 0) + 1;
            $out[$name]['c'] = ($out[$name]['c'] ?? 0) + (isset($convVisits[$k]) ? 1 : 0);
        }
        return $out;
    };
    $bounce = 0;
    foreach ($visits as $k => $v) if ($v['pv'] === 1 && !isset($convVisits[$k])) $bounce++;
    $camps = [];
    foreach ($visits as $v) if ($v['camp'] !== '') $camps[$v['camp']] = ($camps[$v['camp']] ?? 0) + 1;

    return [
        'days' => $days,
        'from' => $start,
        'to' => $today->format('Y-m-d'),
        'visits' => count($visits),
        'pageviews' => $pv,
        'conversions' => $conv,
        'conv_visits' => count($convVisits),
        'bounce' => $bounce,
        'prev' => ['visits' => count($prev['visits']), 'pageviews' => $prev['pv'], 'conversions' => $prev['conv'], 'conv_visits' => count($prev['cv'])],
        'daily' => $daily,
        'pages' => $pages,
        'sources' => $count('src'),
        'countries' => $count('cc'),
        'devices' => $count('dev'),
        'langs' => $count('l'),
        'entries' => $count('entry'),
        'campaigns' => $camps,
        'events' => $events,
        'forms' => $forms,
    ];
}
