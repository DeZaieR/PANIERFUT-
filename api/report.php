<?php
/**
 * Déclenchement du rapport hebdomadaire.
 * - Appel signé depuis dyroweb.online (première visite de la semaine là-bas) : ?ts=…&sig=HMAC("report|ts").
 * - En ligne de commande, pour une tâche planifiée Hostinger : php api/report.php
 * Le rapport ne part qu'une fois par semaine (voir report_send_weekly()).
 */
declare(strict_types=1);

require __DIR__ . '/_report.php';

if (PHP_SAPI === 'cli') {
    echo report_send_weekly() ? "envoyé\n" : "déjà envoyé ou échec\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$cfg = stats_cfg();
$ts = (int)($_GET['ts'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');
if (!$cfg || abs(time() - $ts) > 300 || !hash_equals(hash_hmac('sha256', 'report|' . $ts, (string)$cfg['secret']), $sig)) {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}
echo json_encode(['ok' => true, 'sent' => report_send_weekly()]);
