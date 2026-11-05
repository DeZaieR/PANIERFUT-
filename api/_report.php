<?php
/**
 * Rapport hebdomadaire d'audience des deux sites, envoyé par email chaque lundi.
 * Semaine écoulée (lundi → dimanche) comparée à la précédente. Texte simple : c'est ce qui
 * arrive le mieux en boîte de réception. Utilisé par stats_report_tick(), api/report.php et /stats/.
 */
declare(strict_types=1);

require_once __DIR__ . '/_stats.php';

const REPORT_TO = 'contact@dyrostudios.com';
const REPORT_FROM = 'noreply@dyrostudios.com';
const REPORT_REMOTE = 'https://dyroweb.online/api/stats.php';
/** Premier envoi automatique (la mesure a démarré le 4 novembre 2026). */
const REPORT_START = '2026-11-09';

/** Dernière semaine complète : [lundi, dimanche] au format Y-m-d. */
function report_week(): array
{
    $sunday = (new DateTimeImmutable('monday this week'))->modify('-1 day');
    return [$sunday->modify('-6 days')->format('Y-m-d'), $sunday->format('Y-m-d')];
}

function report_remote(string $end, string $secret): ?array
{
    $ts = time();
    $url = REPORT_REMOTE . '?days=7&ts=' . $ts . '&end=' . $end . '&sig=' . hash_hmac('sha256', '7|' . $ts . '|' . $end, $secret);
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'DyroStats/1.0']);
    $body = curl_exec($c);
    curl_close($c);
    $j = is_string($body) ? json_decode($body, true) : null;
    return is_array($j) && !empty($j['ok']) && is_array($j['data'] ?? null) ? $j['data'] : null;
}

function report_nf($n): string
{
    return number_format((float)$n, 0, ',', "\u{202F}");
}

function report_delta(float $cur, float $prev): string
{
    if ($prev <= 0) return $cur > 0 ? '(nouveau)' : '';
    $d = ($cur - $prev) / $prev * 100;
    if (abs($d) < 0.5) return '(stable)';
    return '(' . ($d > 0 ? '+' : '−') . round(abs($d)) . ' %)';
}

function report_top(array $g, int $n = 3, ?callable $label = null): string
{
    $rows = [];
    foreach ($g as $k => $v) $rows[(string)$k] = is_array($v) ? (int)$v['v'] : (int)$v;
    arsort($rows);
    $out = [];
    foreach (array_slice($rows, 0, $n, true) as $k => $v) $out[] = ($label ? $label($k) : ($k === '—' ? 'Inconnu' : $k)) . ' ' . $v;
    return $out ? implode(' · ', $out) : '—';
}

function report_section(string $title, ?array $d): string
{
    if (!$d) return "$title\n" . str_repeat('─', 28) . "\nChiffres indisponibles cette semaine.\n\n";
    $e = $d['events'] ?? [];
    $rate = $d['visits'] ? round($d['conv_visits'] / $d['visits'] * 100, 1) : 0;
    $pages = static function (string $p): string {
        $map = ['/' => 'Accueil', '/contact/' => 'Contact', '/publicites/' => 'Publicités IA', '/devis.html' => 'Devis', '/audit.html' => 'Audit', '/cgv/' => 'CGV', '/cgv.html' => 'CGV'];
        $lang = '';
        if (preg_match('#^/(de|en|pt|lb)(/.*)$#', $p, $m)) { $lang = ' (' . strtoupper($m[1] === 'lb' ? 'LU' : $m[1]) . ')'; $p = $m[2]; }
        return ($map[$p] ?? $p) . $lang;
    };
    $country = static function (string $cc): string {
        $n = ['LU' => 'Luxembourg', 'FR' => 'France', 'BE' => 'Belgique', 'DE' => 'Allemagne', 'PT' => 'Portugal', 'NL' => 'Pays-Bas', 'CH' => 'Suisse', 'GB' => 'Royaume-Uni', 'US' => 'États-Unis'];
        return $n[$cc] ?? ($cc === '—' || $cc === '' ? 'Inconnu' : $cc);
    };
    $contacts = [];
    foreach (['form' => 'formulaire', 'book' => 'rendez-vous', 'tel' => 'appel', 'email' => 'email', 'whatsapp' => 'WhatsApp', 'mailto_quote' => 'devis par email'] as $k => $lab) {
        if (!empty($e[$k])) $contacts[] = $e[$k] . ' ' . $lab . ($e[$k] > 1 && !in_array($k, ['whatsapp', 'book', 'mailto_quote'], true) ? 's' : '');
    }
    $forms = [];
    $fl = ['essai' => 'essai photo', 'devis' => 'devis', 'partenariat' => 'partenariat', 'pub' => 'publicité IA', 'audit' => 'audit de site',
        'landing' => 'landing page', 'pro' => 'site vitrine', 'premium' => 'premium', 'ecommerce' => 'e-commerce', 'unsure' => 'à définir'];
    foreach ($d['forms'] ?? [] as $k => $n) $forms[] = $n . ' ' . ($fl[$k] ?? $k);
    return "$title\n" . str_repeat('─', 28) . "\n"
        . 'Visites : ' . report_nf($d['visits']) . ' ' . report_delta($d['visits'], $d['prev']['visits']) . "\n"
        . 'Pages vues : ' . report_nf($d['pageviews']) . "\n"
        . 'Prises de contact : ' . report_nf($d['conversions']) . ' ' . report_delta($d['conversions'], $d['prev']['conversions']) . ($contacts ? ' — ' . implode(', ', $contacts) : '') . "\n"
        . ($forms ? 'Formulaires : ' . implode(', ', $forms) . "\n" : '')
        . 'Taux de contact : ' . str_replace('.', ',', (string)$rate) . " %\n"
        . (!empty($e['quote_bar']) ? 'Clics « Devis gratuit 48 h » : ' . $e['quote_bar'] . "\n" : '')
        . 'Sources : ' . report_top($d['sources'] ?? []) . "\n"
        . 'Pages : ' . report_top($d['pages'] ?? [], 3, $pages) . "\n"
        . 'Pays : ' . report_top($d['countries'] ?? [], 3, $country) . "\n\n";
}

/** Prépare [sujet, texte] pour la semaine donnée. */
function report_build(?string $end = null): array
{
    [$from, $to] = report_week();
    if ($end) { $to = $end; $from = (new DateTimeImmutable($end))->modify('-6 days')->format('Y-m-d'); }
    $cfg = stats_cfg();
    $st = stats_aggregate(7, $to);
    $web = $cfg ? report_remote($to, (string)$cfg['secret']) : null;
    $mois = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $f = static fn(string $d) => ((int)substr($d, 8, 2) === 1 ? '1er' : (string)(int)substr($d, 8, 2)) . ' ' . $mois[(int)substr($d, 5, 2)];
    $visits = $st['visits'] + ($web['visits'] ?? 0);
    $conv = $st['conversions'] + ($web['conversions'] ?? 0);
    $prevV = $st['prev']['visits'] + ($web['prev']['visits'] ?? 0);
    $forms = ($st['events']['form'] ?? 0) + ($web['events']['form'] ?? 0);

    $tips = [];
    if ($forms > 0) $tips[] = "$forms demande" . ($forms > 1 ? 's' : '') . ' reçue' . ($forms > 1 ? 's' : '') . ' : vérifie que chacune a eu sa réponse sous 48 h, comme promis sur les sites.';
    if ($visits >= 20 && $conv === 0) $tips[] = 'Des visites mais aucune prise de contact : teste toi-même le formulaire et le bouton de rendez-vous depuis ton téléphone.';
    if ($prevV >= 20 && $visits < $prevV * 0.7) $tips[] = 'Les visites baissent nettement : une publication Instagram ou LinkedIn cette semaine relancera le trafic.';
    if ($visits < 10) $tips[] = 'Encore peu de visites : la fiche Google et la prospection des agences sont les deux leviers les plus rapides.';
    if (!$tips) $tips[] = 'Rien d’urgent : continue sur le même rythme.';

    $subject = 'Rapport hebdo · ' . report_nf($visits) . ' visite' . ($visits > 1 ? 's' : '') . ', ' . report_nf($conv) . ' contact' . ($conv > 1 ? 's' : '') . ' · du ' . $f($from) . ' au ' . $f($to);
    $text = "Bonjour Dany,\n\nVoici tes chiffres du " . $f($from) . ' au ' . $f($to) . ' ' . substr($to, 0, 4) . ", comparés aux 7 jours précédents.\n\n"
        . "LES DEUX SITES\n" . str_repeat('─', 28) . "\n"
        . 'Visites : ' . report_nf($visits) . ' ' . report_delta($visits, $prevV) . "\n"
        . 'Prises de contact : ' . report_nf($conv) . ' ' . report_delta($conv, $st['prev']['conversions'] + ($web['prev']['conversions'] ?? 0)) . "\n\n"
        . report_section('DYRO STUDIOS', $st)
        . report_section('DYRO WEB', $web)
        . "À RETENIR\n" . str_repeat('─', 28) . "\n- " . implode("\n- ", $tips) . "\n\n"
        . "Tableau de bord complet : https://dyrostudios.com/stats/?p=7\n\n"
        . "—\nRapport automatique envoyé chaque lundi. Une « visite » = une personne sur une journée ; les clics WhatsApp, appel et email sont des intentions de contact, les formulaires des demandes certaines.\n";
    return [$subject, $text];
}

function report_send(?string $end = null): bool
{
    require_once __DIR__ . '/_mail.php';
    [$subject, $text] = report_build($end);
    return send_mail(REPORT_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, [
        'From: =?UTF-8?B?' . base64_encode('Audience Dyro') . '?= <' . REPORT_FROM . '>',
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
    ], REPORT_FROM);
}

/** Une seule fois par semaine (3 essais au plus si l'envoi échoue). */
function report_send_weekly(): bool
{
    if (date('Y-m-d') < REPORT_START) return false;
    $week = (new DateTimeImmutable('now'))->format('o-W');
    $lock = STATS_DIR . "/report-sent-$week";
    $tries = is_file($lock) ? (int)@file_get_contents($lock) : 0;
    if ($tries < 0 || $tries >= 3) return false; // -1 = déjà envoyé
    $fp = @fopen($lock . '.busy', 'x');
    if (!$fp) return false;
    fclose($fp);
    $ok = false;
    try {
        $ok = report_send();
    } finally {
        @file_put_contents($lock, $ok ? '-1' : (string)($tries + 1), LOCK_EX);
        @unlink($lock . '.busy');
        foreach (glob(STATS_DIR . '/report-sent-*') ?: [] as $f) if (!str_contains($f, $week)) @unlink($f);
    }
    return $ok;
}
