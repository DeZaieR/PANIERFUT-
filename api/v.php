<?php
/**
 * Réception des mesures d'audience envoyées par le navigateur (navigator.sendBeacon).
 * Corps JSON, 2 Ko maximum : {"t":"pv"|"ev", ...}. Répond 204 sans contenu.
 * Voir api/_stats.php pour ce qui est enregistré (rien d'identifiant).
 */
declare(strict_types=1);

require __DIR__ . '/_stats.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
// Uniquement depuis le site lui-même
$host = preg_replace(['/^www\./', '/:\d+$/'], '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST))) !== $host) {
    http_response_code(403);
    exit;
}

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true);
if (!is_array($in)) {
    http_response_code(400);
    exit;
}
$s = static function (string $k, int $max) use ($in): string {
    $v = $in[$k] ?? '';
    if (!is_scalar($v)) return '';
    return mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', '', (string)$v)), 0, $max);
};

$path = $s('p', 160);
if ($path === '' || $path[0] !== '/') $path = '/';

$t = $s('t', 4);
if ($t === 'pv') {
    $ref = preg_replace('/^www\./', '', strtolower((string)parse_url($s('r', 500), PHP_URL_HOST)));
    if ($ref === $host) $ref = '';
    $lang = strtolower($s('l', 5));
    $e = [
        't' => 'pv',
        'p' => $path,
        'r' => $ref,
        'us' => strtolower($s('us', 40)),
        'um' => strtolower($s('um', 40)),
        'uc' => $s('uc', 60),
        'w' => max(0, min(9999, (int)($in['w'] ?? 0))),
        'l' => preg_match('/^[a-z]{2}$/', $lang) ? $lang : '',
    ];
} elseif ($t === 'ev') {
    $n = $s('n', 20);
    if (!in_array($n, STATS_EVENTS, true)) {
        http_response_code(400);
        exit;
    }
    $e = ['t' => 'ev', 'n' => $n, 'm' => $s('m', 40), 'p' => $path];
} else {
    http_response_code(400);
    exit;
}

stats_log($e);
http_response_code(204);
