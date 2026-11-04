<?php
/**
 * Tableau de bord d'audience des deux sites (dyrostudios.com et dyroweb.online).
 * Accès par mot de passe (haché dans api/.stats.php, hors dépôt). Données anonymes, sans cookies
 * côté visiteurs ; seul ce tableau de bord pose un cookie de connexion, pour son propriétaire.
 */
declare(strict_types=1);

require __DIR__ . '/../api/_stats.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

const REMOTE_DEFAULT = 'https://dyroweb.online/api/stats.php';
const COOKIE = 'dyro_stats';
const PERIODS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'];
const SITES = ['all' => 'Les deux', 'studios' => 'Dyro Studios', 'web' => 'DYRO WEB'];

$cfg = stats_cfg();
if (!$cfg || empty($cfg['pass'])) {
    http_response_code(503);
    exit('Configuration manquante (api/.stats.php).');
}
$secret = (string)$cfg['secret'];
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* ---------------- Connexion ---------------- */

$authed = false;
if (isset($_COOKIE[COOKIE]) && preg_match('/^(\d{10})\.([a-f0-9]{64})$/', (string)$_COOKIE[COOKIE], $m)) {
    $authed = (int)$m[1] > time() && hash_equals(hash_hmac('sha256', 'auth|' . $m[1], $secret), $m[2]);
}
$cookieOpts = static fn(int $exp) => ['expires' => $exp, 'path' => '/stats/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict'];
if (isset($_GET['logout'])) {
    setcookie(COOKIE, '', $cookieOpts(1));
    header('Location: /stats/');
    exit;
}
$error = '';
if (!$authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (password_verify((string)($_POST['pass'] ?? ''), (string)$cfg['pass'])) {
        $exp = time() + 90 * 86400;
        setcookie(COOKIE, $exp . '.' . hash_hmac('sha256', 'auth|' . $exp, $secret), $cookieOpts($exp));
        header('Location: /stats/');
        exit;
    }
    sleep(1);
    $error = 'Mot de passe incorrect.';
}

/* ---------------- Données ---------------- */

$days = (int)($_GET['p'] ?? 30);
if (!isset(PERIODS[$days])) $days = 30;
$site = (string)($_GET['s'] ?? 'all');
if (!isset(SITES[$site])) $site = 'all';

function fetch_remote(int $days, string $secret): ?array
{
    $ts = time();
    $url = (stats_cfg()['remote'] ?? REMOTE_DEFAULT) . '?days=' . $days . '&ts=' . $ts . '&sig=' . hash_hmac('sha256', $days . '|' . $ts, $secret);
    $body = false;
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'DyroStats/1.0']);
        $body = curl_exec($c);
        curl_close($c);
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: DyroStats/1.0\r\n"]]));
    }
    $j = is_string($body) ? json_decode($body, true) : null;
    return is_array($j) && !empty($j['ok']) && is_array($j['data'] ?? null) ? $j['data'] : null;
}

/** Additionne deux agrégats (mêmes clés). Les pages sont préfixées par le site. */
function merge_stats(array $a, array $b): array
{
    $out = $a;
    foreach (['visits', 'pageviews', 'conversions', 'conv_visits', 'bounce'] as $k) $out[$k] = ($a[$k] ?? 0) + ($b[$k] ?? 0);
    foreach (['visits', 'pageviews', 'conversions', 'conv_visits'] as $k) $out['prev'][$k] = ($a['prev'][$k] ?? 0) + ($b['prev'][$k] ?? 0);
    foreach ($b['daily'] as $d => $v) foreach ($v as $k => $n) $out['daily'][$d][$k] = ($out['daily'][$d][$k] ?? 0) + $n;
    foreach (['events', 'forms', 'campaigns', 'pages'] as $g) foreach ($b[$g] as $k => $n) $out[$g][$k] = ($out[$g][$k] ?? 0) + $n;
    foreach (['sources', 'countries', 'devices', 'langs', 'entries'] as $g) {
        foreach ($b[$g] as $k => $v) {
            $out[$g][$k]['v'] = ($out[$g][$k]['v'] ?? 0) + $v['v'];
            $out[$g][$k]['c'] = ($out[$g][$k]['c'] ?? 0) + $v['c'];
        }
    }
    return $out;
}

function prefix_pages(array $s, string $tag): array
{
    $p = [];
    foreach ($s['pages'] as $k => $n) $p[$tag . "\u{2009}" . $k] = $n;
    $s['pages'] = $p;
    return $s;
}

$data = null;
$warn = '';
if ($authed) {
    $local = stats_aggregate($days);
    if ($site === 'studios') {
        $data = $local;
    } else {
        $remote = fetch_remote($days, $secret);
        if ($site === 'web') {
            $data = $remote;
            if (!$data) $warn = 'Les chiffres de DYRO WEB sont injoignables pour le moment. Réessayez dans un instant.';
        } elseif ($remote) {
            $data = merge_stats(prefix_pages($local, 'Studios'), prefix_pages($remote, 'Web'));
        } else {
            $data = prefix_pages($local, 'Studios');
            $warn = 'DYRO WEB injoignable : seuls les chiffres de Dyro Studios sont affichés.';
        }
    }
}

/* ---------------- Mise en forme ---------------- */

$nf = static fn($n) => number_format((float)$n, 0, ',', "\u{202F}");
$pct = static fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) : 0.0;

function delta(float $cur, float $prev, bool $points = false): string
{
    if (!$points && $prev <= 0) return $cur > 0 ? '<span class="dl dl--up">nouveau</span>' : '<span class="dl">—</span>';
    $d = $points ? $cur - $prev : ($cur - $prev) / $prev * 100;
    if (abs($d) < 0.05) return '<span class="dl">= stable</span>';
    $up = $d > 0;
    $txt = ($up ? '+' : '−') . str_replace('.', ',', (string)round(abs($d), $points ? 1 : 0)) . ($points ? ' pt' : ' %');
    return '<span class="dl dl--' . ($up ? 'up' : 'down') . '"><span aria-hidden="true">' . ($up ? '▲' : '▼') . '</span> ' . $txt . '</span>';
}

function page_label(string $p): string
{
    $tag = '';
    if (str_contains($p, "\u{2009}")) [$tag, $p] = explode("\u{2009}", $p, 2);
    $lang = '';
    if (preg_match('#^/(de|en|pt|lb)(/.*)$#', $p, $m)) { $lang = strtoupper($m[1] === 'lb' ? 'LU' : $m[1]); $p = $m[2]; }
    $names = ['/' => 'Accueil', '/index.html' => 'Accueil', '/contact/' => 'Contact', '/publicites/' => 'Publicités IA',
        '/devis.html' => 'Devis', '/cgv.html' => 'CGV', '/mentions-legales/' => 'Mentions légales', '/mentions-legales.html' => 'Mentions légales',
        '/confidentialite/' => 'Confidentialité', '/confidentialite.html' => 'Confidentialité', '/404.html' => 'Page introuvable'];
    $name = $names[$p] ?? $p;
    return ($tag ? $tag . ' · ' : '') . $name . ($lang ? " ($lang)" : '');
}

function country_label(string $cc): string
{
    if (!preg_match('/^[A-Z]{2}$/', $cc)) return 'Inconnu';
    $names = ['LU' => 'Luxembourg', 'FR' => 'France', 'BE' => 'Belgique', 'DE' => 'Allemagne', 'PT' => 'Portugal', 'NL' => 'Pays-Bas',
        'CH' => 'Suisse', 'GB' => 'Royaume-Uni', 'US' => 'États-Unis', 'ES' => 'Espagne', 'IT' => 'Italie', 'BR' => 'Brésil', 'IE' => 'Irlande',
        'AT' => 'Autriche', 'PL' => 'Pologne', 'CA' => 'Canada', 'MA' => 'Maroc', 'CV' => 'Cap-Vert', 'AO' => 'Angola', 'SE' => 'Suède', 'DK' => 'Danemark'];
    $name = $names[$cc] ?? (class_exists('Locale') ? (string)Locale::getDisplayRegion('-' . $cc, 'fr') : $cc);
    $flag = mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
    return $flag . ' ' . $name;
}

const EVENT_LABELS = [
    'form' => 'Formulaires envoyés', 'book' => 'Réservations d’appel', 'whatsapp' => 'Clics WhatsApp', 'tel' => 'Clics téléphone',
    'email' => 'Clics email', 'mailto_quote' => 'Devis préparés par email', 'instagram' => 'Clics Instagram', 'linkedin' => 'Clics LinkedIn',
    'sister' => 'Vers l’autre marque', 'lang' => 'Changements de langue',
];
const FORM_LABELS = [
    'essai' => 'Essai photo (Studios)', 'devis' => 'Devis (Studios)', 'partenariat' => 'Partenariat agence (Studios)', 'pub' => 'Publicité IA (Studios)',
    'landing' => 'Landing page (Web)', 'pro' => 'Site vitrine Pro (Web)', 'premium' => 'Premium multilingue (Web)', 'ecommerce' => 'E-commerce (Web)', 'unsure' => 'À définir (Web)',
];
const LANG_LABELS = ['fr' => 'Français', 'de' => 'Allemand', 'en' => 'Anglais', 'pt' => 'Portugais', 'lb' => 'Luxembourgeois', '—' => 'Inconnue'];

/** Liste à barres horizontales : [libellé, valeur, détail facultatif]. */
function bars(array $rows, int $limit = 8, string $empty = 'Pas encore de données.'): string
{
    if (!$rows) return '<p class="empty">' . htmlspecialchars($empty) . '</p>';
    $rows = array_slice($rows, 0, $limit);
    $max = max(array_map(static fn($r) => $r[1], $rows)) ?: 1;
    $o = '<ul class="bars">';
    foreach ($rows as $r) {
        $w = max(1.5, $r[1] / $max * 100);
        $o .= '<li><div class="bars__row"><span class="bars__k">' . htmlspecialchars((string)$r[0]) . '</span><span class="bars__v">'
            . number_format((float)$r[1], 0, ',', "\u{202F}") . (isset($r[2]) && $r[2] !== '' ? '<small>' . htmlspecialchars((string)$r[2]) . '</small>' : '')
            . '</span></div><span class="bars__bar" style="width:' . round($w, 1) . '%"></span></li>';
    }
    return $o . '</ul>';
}

/** Lignes triées depuis un groupe {nom: {v, c}} avec le nombre de contacts en détail. */
function group_rows(array $g, ?callable $label = null): array
{
    uasort($g, static fn($a, $b) => $b['v'] <=> $a['v']);
    $rows = [];
    foreach ($g as $k => $v) {
        $rows[] = [$label ? $label((string)$k) : (string)$k, $v['v'], $v['c'] ? $v['c'] . ' contact' . ($v['c'] > 1 ? 's' : '') : ''];
    }
    return $rows;
}

function flat_rows(array $g, ?callable $label = null): array
{
    arsort($g);
    $rows = [];
    foreach ($g as $k => $n) $rows[] = [$label ? $label((string)$k) : (string)$k, $n];
    return $rows;
}

/** Colonnes : par jour (7/30 j), par semaine (90 j), par mois (12 mois). */
function series(array $daily, int $days): array
{
    $mois = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $fmt = static function (string $d) use ($mois): string { $t = strtotime($d); return (int)date('j', $t) . ' ' . $mois[(int)date('n', $t)]; };
    $out = [];
    if ($days <= 30) {
        foreach ($daily as $d => $v) $out[] = ['label' => $fmt($d), 'v' => $v['v'], 'c' => $v['c']];
        return $out;
    }
    foreach ($daily as $d => $v) {
        $t = strtotime($d);
        $key = $days > 90 ? date('Y-m', $t) : date('o-W', $t);
        if (!isset($out[$key])) {
            $out[$key] = ['label' => $days > 90 ? ucfirst($mois[(int)date('n', $t)]) . ' ' . date('Y', $t) : 'Sem. du ' . $fmt($d), 'v' => 0, 'c' => 0];
        }
        $out[$key]['v'] += $v['v'];
        $out[$key]['c'] += $v['c'];
    }
    return array_values($out);
}

function nice_max(int $n): int
{
    if ($n <= 4) return 4;
    $p = 10 ** (int)floor(log10($n));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $p >= $n) return (int)($m * $p);
    return $n;
}

$q = static fn(array $over) => '?' . http_build_query(array_merge(['s' => $site, 'p' => $days], $over));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#08090b">
<meta name="apple-mobile-web-app-title" content="Audience Dyro">
<title>Audience Dyro</title>
<link rel="icon" href="/assets/img/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<style>
@font-face { font-family: "Space Grotesk"; src: url(/assets/fonts/space-grotesk-latin.woff2) format("woff2"); font-weight: 300 700; font-display: swap; }
@font-face { font-family: "Manrope"; src: url(/assets/fonts/manrope-latin.woff2) format("woff2"); font-weight: 200 800; font-display: swap; }
:root { --bg: #08090b; --card: #101318; --card2: #161a21; --line: #262b34; --line2: #3a414d; --ink: #f3f4f6; --text: #c9ced6; --muted: #a7aeb9; --gold: #c9a96a; --gold2: #e8d09a; --up: #86d3a5; --down: #ff9b8c; }
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 Manrope, system-ui, sans-serif; padding: max(16px, env(safe-area-inset-top)) 16px max(28px, env(safe-area-inset-bottom)); }
.wrap { max-width: 1080px; margin: 0 auto; }
h1, h2 { font-family: "Space Grotesk", system-ui, sans-serif; color: var(--ink); margin: 0; letter-spacing: -.01em; }
h1 { font-size: 22px; font-weight: 600; }
h1 b { color: var(--gold); font-weight: 600; }
h2 { font-size: 15px; font-weight: 600; margin-bottom: 12px; }
a { color: var(--gold2); }
.top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.top a { font-size: 13px; color: var(--muted); }
.seg { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin-bottom: 10px; }
.seg::-webkit-scrollbar { display: none; }
.seg a { flex: none; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line2); color: var(--text); text-decoration: none; font-size: 14px; white-space: nowrap; }
.seg a[aria-current] { background: var(--gold); border-color: var(--gold); color: #111; font-weight: 600; }
.range { color: var(--muted); font-size: 13px; margin: 4px 0 16px; }
.warn { background: #2a1d17; border: 1px solid #5a3a2c; color: #ffd2c4; padding: 10px 14px; border-radius: 12px; font-size: 14px; margin-bottom: 14px; }
.kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
@media (min-width: 760px) { .kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.kpi { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; }
.kpi__k { font-size: 13px; color: var(--muted); }
.kpi__v { font: 600 30px/1.15 "Space Grotesk", system-ui, sans-serif; color: var(--ink); margin: 4px 0 2px; font-variant-numeric: tabular-nums; }
.kpi--gold .kpi__v { color: var(--gold2); }
.dl { font-size: 12.5px; color: var(--muted); white-space: nowrap; }
.dl--up { color: var(--up); }
.dl--down { color: var(--down); }
.kpi small { display: block; font-size: 12px; color: var(--muted); margin-top: 2px; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 16px; margin-bottom: 12px; min-width: 0; }
.grid { display: grid; gap: 12px; }
@media (min-width: 760px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .grid .card { margin-bottom: 0; } .grid { margin-bottom: 12px; } }
.chart { position: relative; height: 180px; margin: 6px 0 4px 34px; }
.chart__grid { position: absolute; inset: 0; }
.chart__grid span { position: absolute; left: 0; right: 0; border-top: 1px solid var(--line); }
.chart__grid em { position: absolute; left: -34px; width: 28px; text-align: right; transform: translateY(-50%); font: normal 11px/1 Manrope, sans-serif; color: var(--muted); font-variant-numeric: tabular-nums; }
.chart__cols { position: absolute; inset: 0; display: flex; align-items: flex-end; gap: 2px; }
.col { flex: 1 1 0; height: 100%; display: flex; align-items: flex-end; justify-content: center; position: relative; outline: none; }
.col i { display: block; width: 100%; max-width: 24px; background: var(--gold); border-radius: 4px 4px 0 0; min-height: 0; transition: background .15s; }
.col:hover i, .col:focus i { background: var(--gold2); }
.col::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 0; background: var(--card2); border: 1px solid var(--line2); color: var(--ink); font-size: 12px; line-height: 1.35; padding: 6px 9px; border-radius: 8px; white-space: pre; pointer-events: none; opacity: 0; transition: opacity .12s; z-index: 2; }
.col--r::after { left: auto; right: 0; }
.col:hover::after, .col:focus::after { opacity: 1; }
.col:hover, .col:focus { z-index: 3; }
.chart__x { display: flex; justify-content: space-between; margin-left: 34px; font-size: 11.5px; color: var(--muted); }
details { margin-top: 10px; font-size: 13px; }
summary { cursor: pointer; color: var(--muted); }
table { width: 100%; border-collapse: collapse; margin-top: 8px; font-variant-numeric: tabular-nums; }
td, th { text-align: left; padding: 5px 0; border-bottom: 1px solid var(--line); font-weight: 400; }
td + td, th + th { text-align: right; }
th { color: var(--muted); font-size: 12px; }
.bars { list-style: none; margin: 0; padding: 0; display: grid; gap: 9px; }
.bars li { position: relative; }
.bars__row { display: flex; justify-content: space-between; gap: 10px; font-size: 14px; }
.bars__k { color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.bars__v { flex: none; font-variant-numeric: tabular-nums; color: var(--text); }
.bars__v small { color: var(--muted); font-size: 12px; margin-left: 6px; }
.bars__bar { display: block; height: 6px; margin-top: 4px; background: var(--gold); border-radius: 0 4px 4px 0; opacity: .85; }
.empty { color: var(--muted); font-size: 14px; margin: 0; }
.note { font-size: 12.5px; color: var(--muted); }
.foot { margin-top: 18px; font-size: 12.5px; color: var(--muted); display: grid; gap: 6px; }
.login { max-width: 360px; margin: 18vh auto 0; }
.login p { color: var(--muted); }
.login input { width: 100%; font: inherit; font-size: 16px; padding: 13px 14px; border-radius: 12px; border: 1px solid var(--line2); background: var(--card); color: var(--ink); margin: 14px 0 10px; }
.login input:focus { outline: 2px solid var(--gold); outline-offset: 1px; }
.login button { width: 100%; font: 600 16px "Space Grotesk", sans-serif; padding: 13px; border: 0; border-radius: 12px; background: var(--gold); color: #111; cursor: pointer; }
.login .err { color: var(--down); font-size: 14px; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$authed): ?>
  <form class="login" method="post" action="/stats/">
    <h1>Audience <b>Dyro</b></h1>
    <p>Tableau de bord privé de dyrostudios.com et dyroweb.online.</p>
    <label for="pass" class="note">Mot de passe</label>
    <input id="pass" name="pass" type="password" autocomplete="current-password" required autofocus>
    <?php if ($error): ?><p class="err" role="alert"><?= $h($error) ?></p><?php endif; ?>
    <button type="submit">Ouvrir</button>
  </form>
<?php else:
    $d = $data;
?>
  <div class="top">
    <h1>Audience <b>Dyro</b></h1>
    <a href="?logout=1">Se déconnecter</a>
  </div>
  <nav class="seg" aria-label="Site">
    <?php foreach (SITES as $k => $label): ?><a href="<?= $h($q(['s' => $k])) ?>"<?= $k === $site ? ' aria-current="page"' : '' ?>><?= $h($label) ?></a><?php endforeach; ?>
  </nav>
  <nav class="seg" aria-label="Période">
    <?php foreach (PERIODS as $k => $label): ?><a href="<?= $h($q(['p' => $k])) ?>"<?= $k === $days ? ' aria-current="page"' : '' ?>><?= $h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($warn): ?><p class="warn"><?= $h($warn) ?></p><?php endif; ?>
  <?php if ($d):
      $rate = $pct($d['conv_visits'], $d['visits']);
      $prate = $pct($d['prev']['conv_visits'], $d['prev']['visits']);
      $forms = (int)($d['events']['form'] ?? 0);
      $cols = series($d['daily'], $days);
      $max = nice_max(max(array_merge([0], array_column($cols, 'v'))));
      $unit = $days <= 30 ? 'jour' : ($days > 90 ? 'mois' : 'semaine');
  ?>
  <p class="range">Du <?= $h(date('d/m/Y', strtotime($d['from']))) ?> au <?= $h(date('d/m/Y', strtotime($d['to']))) ?> · comparé à la période précédente</p>

  <section class="kpis" aria-label="Chiffres clés">
    <div class="kpi"><div class="kpi__k">Visites</div><div class="kpi__v"><?= $nf($d['visits']) ?></div><?= delta($d['visits'], $d['prev']['visits']) ?></div>
    <div class="kpi"><div class="kpi__k">Pages vues</div><div class="kpi__v"><?= $nf($d['pageviews']) ?></div><?= delta($d['pageviews'], $d['prev']['pageviews']) ?></div>
    <div class="kpi kpi--gold"><div class="kpi__k">Prises de contact</div><div class="kpi__v"><?= $nf($d['conversions']) ?></div><?= delta($d['conversions'], $d['prev']['conversions']) ?><small>dont <?= $nf($forms) ?> formulaire<?= $forms > 1 ? 's' : '' ?>, le reste en clics WhatsApp, appel ou email</small></div>
    <div class="kpi"><div class="kpi__k">Taux de contact</div><div class="kpi__v"><?= str_replace('.', ',', (string)$rate) ?> %</div><?= $d['prev']['visits'] ? delta($rate, $prate, true) : '<span class="dl">—</span>' ?><small>visites avec au moins un contact</small></div>
  </section>

  <section class="card" aria-label="Visites par <?= $h($unit) ?>">
    <h2>Visites par <?= $h($unit) ?></h2>
    <div class="chart">
      <div class="chart__grid" aria-hidden="true">
        <?php foreach ([0, .5, 1] as $f): ?><span style="bottom:<?= $f * 100 ?>%"><em><?= $nf($max * $f) ?></em></span><?php endforeach; ?>
      </div>
      <div class="chart__cols">
        <?php foreach ($cols as $i => $c):
            $tip = $c['label'] . "\n" . $nf($c['v']) . ' visite' . ($c['v'] > 1 ? 's' : '') . ($c['c'] ? ' · ' . $nf($c['c']) . ' contact' . ($c['c'] > 1 ? 's' : '') : '');
        ?><span class="col<?= $i >= count($cols) / 2 ? ' col--r' : '' ?>" tabindex="0" data-tip="<?= $h($tip) ?>" aria-label="<?= $h(str_replace("\n", ' : ', $tip)) ?>"><i style="height:<?= $c['v'] ? max(1.5, $c['v'] / $max * 100) : 0 ?>%"></i></span><?php endforeach; ?>
      </div>
    </div>
    <div class="chart__x" aria-hidden="true"><span><?= $h($cols[0]['label'] ?? '') ?></span><span><?= $h(end($cols)['label'] ?? '') ?></span></div>
    <details>
      <summary>Voir le tableau</summary>
      <table>
        <tr><th>Période</th><th>Visites</th><th>Contacts</th></tr>
        <?php foreach (array_reverse($cols) as $c): ?><tr><td><?= $h($c['label']) ?></td><td><?= $nf($c['v']) ?></td><td><?= $nf($c['c']) ?></td></tr><?php endforeach; ?>
      </table>
    </details>
  </section>

  <div class="grid">
    <section class="card"><h2>D’où viennent les visiteurs</h2><?= bars(group_rows($d['sources'])) ?></section>
    <section class="card"><h2>Prises de contact et clics</h2><?= bars(flat_rows($d['events'], static fn($k) => EVENT_LABELS[$k] ?? $k), 10, 'Aucun clic ni formulaire pour le moment.') ?>
      <?php if ($d['forms']): ?><h2 style="margin-top:16px">Formulaires par type</h2><?= bars(flat_rows($d['forms'], static fn($k) => FORM_LABELS[$k] ?? $k)) ?><?php endif; ?>
    </section>
    <section class="card"><h2>Pages les plus vues</h2><?= bars(flat_rows($d['pages'], 'page_label'), 10) ?></section>
    <section class="card"><h2>Pays</h2><?= bars(group_rows($d['countries'], 'country_label')) ?></section>
    <section class="card"><h2>Appareils</h2><?= bars(group_rows($d['devices'])) ?></section>
    <section class="card"><h2>Langue de la page</h2><?= bars(group_rows($d['langs'], static fn($k) => LANG_LABELS[$k] ?? strtoupper($k))) ?></section>
    <?php if ($d['campaigns']): ?><section class="card"><h2>Campagnes (liens suivis)</h2><?= bars(flat_rows($d['campaigns'])) ?></section><?php endif; ?>
  </div>
  <p class="note">Visites sans aucun clic ni deuxième page : <?= $nf($d['bounce']) ?> (<?= str_replace('.', ',', (string)$pct($d['bounce'], $d['visits'])) ?> %). Une même personne revenue un autre jour compte comme une nouvelle visite.</p>
  <?php endif; ?>

  <div class="foot">
    <span>Pour ne pas compter vos propres visites, ouvrez une fois sur chaque appareil : <a href="https://dyrostudios.com/?notrack=1">dyrostudios.com/?notrack=1</a> et <a href="https://dyroweb.online/?notrack=1">dyroweb.online/?notrack=1</a>.</span>
    <span>Mesure anonyme, sans cookies ni adresse IP enregistrée. Pays : <a href="https://db-ip.com" rel="noopener">IP Geolocation by DB-IP</a>.</span>
  </div>
<?php endif; ?>
</div>
</body>
</html>
