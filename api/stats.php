<?php
/**
 * Agrégats d'audience au format JSON, pour le tableau de bord (dyrostudios.com/stats/).
 * Accès signé : ?days=30&ts=<horodatage>&sig=<HMAC-SHA256("days|ts", clé partagée)>,
 * valable 5 minutes. La clé est dans api/.stats.php (hors dépôt), identique sur les deux sites.
 */
declare(strict_types=1);

require __DIR__ . '/_stats.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Content-Type: application/json; charset=utf-8');

$cfg = stats_cfg();
$days = (int)($_GET['days'] ?? 0);
$ts = (int)($_GET['ts'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');
$diag = ($_GET['diag'] ?? '') === '1';
$end = (string)($_GET['end'] ?? '');
if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = '';

if (!$cfg || !in_array($days, [1, 7, 30, 90, 365], true) || abs(time() - $ts) > 300
    || !hash_equals(hash_hmac('sha256', $days . '|' . $ts . ($end !== '' ? '|' . $end : '') . ($diag ? '|diag' : ''), (string)$cfg['secret']), $sig)) {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}

$out = ['ok' => true, 'data' => stats_aggregate($days, $end !== '' ? $end : null)];
if ($diag) {
    // Contrôle technique : l'adresse vue par le serveur et le pays trouvé (rien n'est enregistré)
    $out['diag'] = [
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? '',
        'used' => stats_ip(),
        'country' => stats_country(stats_ip()),
        'writable' => is_dir(STATS_DIR) ? is_writable(STATS_DIR) : is_writable(__DIR__),
    ];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
