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
    'Auto-Submitted: auto-generated', // indispensable : sans lui, la notification part dans les indésirables
];

$sent = send_mail($TO, $encodedSubject, $body, $headers, $FROM);

if ($sent) {
    send_confirmation($email, $lang, $data, $FROM, $TO);
}

reply($sent ? 200 : 500, ['ok' => (bool) $sent]);

/**
 * Confirmation au prospect, dans la langue du site : récapitulatif de ses choix et prochaines étapes.
 * Seules des valeurs issues des listes du formulaire sont reprises (jamais le texte libre saisi) :
 * le formulaire ne peut pas servir à écrire un message arbitraire à un tiers.
 */
function send_confirmation(string $to, string $lang, array $data, string $from, string $replyTo): void
{
    $T = [
        'fr' => [
            'subject' => 'Votre demande de devis est bien reçue – DYRO WEB', 'hello' => 'Bonjour,',
            'intro' => 'Merci, votre demande de devis a bien été reçue. Nous vous envoyons <strong>sous 1 jour ouvré</strong> une proposition claire en trois options, prix hors TVA.',
            'recap' => 'VOTRE DEMANDE', 'l_type' => 'Projet', 'l_range' => 'Fourchette indicative', 'l_langs' => 'Langues', 'l_feat' => 'Fonctionnalités', 'l_maint' => 'Maintenance', 'l_deadline' => 'Délai souhaité',
            'range_note' => 'selon le périmètre, options en sus',
            'types' => ['landing' => 'Landing page', 'pro' => 'Site vitrine Pro', 'premium' => 'Premium multilingue', 'ecommerce' => 'E-commerce', 'unsure' => 'À définir ensemble'],
            'ranges' => ['landing' => '890 à 1 290 € HT', 'pro' => '1 890 à 2 900 € HT', 'premium' => '3 500 à 6 000 € HT', 'ecommerce' => 'dès 4 500 € HT'],
            'features' => ['form' => 'Formulaire de contact', 'booking' => 'Réservation en ligne', 'adv' => 'Formulaires avancés', 'pay' => 'Paiement en ligne', 'seo' => 'SEO local', 'blog' => 'Blog ou actualités'],
            'maint' => ['none' => 'Pas pour l’instant', 'ess' => 'Essentiel · 49 € HT / mois', 'pro' => 'Pro · 99 € HT / mois', 'gro' => 'Croissance · 199 € HT / mois'],
            'deadlines' => ['asap' => 'Dès que possible', 'soon' => 'D’ici 1 à 2 mois', 'later' => 'Dans 3 mois ou plus', 'none' => 'Pas de date fixe'],
            'next' => 'LES PROCHAINES ÉTAPES',
            'steps' => ['Nous étudions votre projet et, si besoin, votre site actuel.', 'Vous recevez une proposition en trois options, prix HT, sous 1 jour ouvré.', 'Après votre accord : planning étape par étape et démarrage (acompte de 40 % pour les projets de plus de 1 000 € HT).'],
            'add' => 'Pour aller plus vite, <strong>répondez simplement à cet email</strong> avec votre logo, vos textes, des photos ou des sites que vous aimez.',
            'call' => 'Vous préférez en parler ? Réservez un appel de 20 minutes ou écrivez-nous sur WhatsApp :',
            'regards' => 'Bien cordialement,', 'team' => 'L’équipe DYRO WEB', 'tagline' => 'Création de sites web au Luxembourg',
            'footer' => 'Vous recevez ce message car votre adresse a été saisie dans le formulaire de devis de dyroweb.online. Si ce n’est pas vous, ignorez-le.',
        ],
        'de' => [
            'subject' => 'Ihre Angebotsanfrage ist eingegangen – DYRO WEB', 'hello' => 'Guten Tag,',
            'intro' => 'Vielen Dank, Ihre Angebotsanfrage ist eingegangen. Sie erhalten <strong>innerhalb eines Werktages</strong> ein klares Angebot mit drei Optionen, Preise netto.',
            'recap' => 'IHRE ANFRAGE', 'l_type' => 'Projekt', 'l_range' => 'Richtpreis', 'l_langs' => 'Sprachen', 'l_feat' => 'Funktionen', 'l_maint' => 'Wartung', 'l_deadline' => 'Zeitrahmen',
            'range_note' => 'je nach Umfang, Optionen zusätzlich',
            'types' => ['landing' => 'Landingpage', 'pro' => 'Firmenwebsite Pro', 'premium' => 'Premium mehrsprachig', 'ecommerce' => 'Onlineshop', 'unsure' => 'Gemeinsam festzulegen'],
            'ranges' => ['landing' => '890 bis 1.290 € netto', 'pro' => '1.890 bis 2.900 € netto', 'premium' => '3.500 bis 6.000 € netto', 'ecommerce' => 'ab 4.500 € netto'],
            'features' => ['form' => 'Kontaktformular', 'booking' => 'Online-Buchung', 'adv' => 'Erweiterte Formulare', 'pay' => 'Online-Zahlung', 'seo' => 'Lokales SEO', 'blog' => 'Blog oder News'],
            'maint' => ['none' => 'Vorerst nicht', 'ess' => 'Essentiel · 49 € netto / Monat', 'pro' => 'Pro · 99 € netto / Monat', 'gro' => 'Croissance · 199 € netto / Monat'],
            'deadlines' => ['asap' => 'So bald wie möglich', 'soon' => 'In 1 bis 2 Monaten', 'later' => 'In 3 Monaten oder später', 'none' => 'Kein festes Datum'],
            'next' => 'DIE NÄCHSTEN SCHRITTE',
            'steps' => ['Wir prüfen Ihr Projekt und, falls vorhanden, Ihre aktuelle Website.', 'Sie erhalten innerhalb eines Werktages ein Angebot mit drei Optionen, Preise netto.', 'Nach Ihrer Zustimmung: Zeitplan Schritt für Schritt und Start (40 % Anzahlung bei Projekten über 1.000 € netto).'],
            'add' => 'Schneller geht es, wenn Sie <strong>einfach auf diese E-Mail antworten</strong> – mit Ihrem Logo, Ihren Texten, Fotos oder Websites, die Ihnen gefallen.',
            'call' => 'Lieber persönlich? Buchen Sie ein 20-minütiges Gespräch oder schreiben Sie uns auf WhatsApp:',
            'regards' => 'Mit freundlichen Grüßen', 'team' => 'Ihr DYRO WEB Team', 'tagline' => 'Websites für Unternehmen in Luxemburg',
            'footer' => 'Sie erhalten diese Nachricht, weil Ihre Adresse im Angebotsformular von dyroweb.online eingegeben wurde. Falls Sie das nicht waren, ignorieren Sie sie bitte.',
        ],
        'en' => [
            'subject' => 'We have received your quote request – DYRO WEB', 'hello' => 'Hello,',
            'intro' => 'Thank you, we have received your quote request. You will receive a clear proposal with three options, prices excl. VAT, <strong>within one business day</strong>.',
            'recap' => 'YOUR REQUEST', 'l_type' => 'Project', 'l_range' => 'Indicative range', 'l_langs' => 'Languages', 'l_feat' => 'Features', 'l_maint' => 'Maintenance', 'l_deadline' => 'Timeline',
            'range_note' => 'depending on scope, options extra',
            'types' => ['landing' => 'Landing page', 'pro' => 'Business website Pro', 'premium' => 'Premium multilingual', 'ecommerce' => 'E-commerce', 'unsure' => 'To be defined together'],
            'ranges' => ['landing' => '€890 to €1,290 excl. VAT', 'pro' => '€1,890 to €2,900 excl. VAT', 'premium' => '€3,500 to €6,000 excl. VAT', 'ecommerce' => 'from €4,500 excl. VAT'],
            'features' => ['form' => 'Contact form', 'booking' => 'Online booking', 'adv' => 'Advanced forms', 'pay' => 'Online payment', 'seo' => 'Local SEO', 'blog' => 'Blog or news'],
            'maint' => ['none' => 'Not for now', 'ess' => 'Essentiel · €49 excl. VAT / month', 'pro' => 'Pro · €99 excl. VAT / month', 'gro' => 'Croissance · €199 excl. VAT / month'],
            'deadlines' => ['asap' => 'As soon as possible', 'soon' => 'Within 1 to 2 months', 'later' => 'In 3 months or more', 'none' => 'No fixed date'],
            'next' => 'NEXT STEPS',
            'steps' => ['We study your project and, if relevant, your current website.', 'You receive a proposal with three options, prices excl. VAT, within one business day.', 'Once you approve: a step-by-step schedule and kick-off (40% deposit for projects over €1,000 excl. VAT).'],
            'add' => 'To speed things up, <strong>simply reply to this email</strong> with your logo, texts, photos or websites you like.',
            'call' => 'Prefer to talk? Book a 20-minute call or message us on WhatsApp:',
            'regards' => 'Kind regards,', 'team' => 'The DYRO WEB team', 'tagline' => 'Websites for businesses in Luxembourg',
            'footer' => 'You are receiving this message because your address was entered in the quote form on dyroweb.online. If this wasn’t you, please ignore it.',
        ],
        'pt' => [
            'subject' => 'Recebemos o seu pedido de orçamento – DYRO WEB', 'hello' => 'Olá,',
            'intro' => 'Obrigado, recebemos o seu pedido de orçamento. Vai receber <strong>no prazo de um dia útil</strong> uma proposta clara com três opções, preços sem IVA.',
            'recap' => 'O SEU PEDIDO', 'l_type' => 'Projeto', 'l_range' => 'Intervalo indicativo', 'l_langs' => 'Línguas', 'l_feat' => 'Funcionalidades', 'l_maint' => 'Manutenção', 'l_deadline' => 'Prazo',
            'range_note' => 'conforme o âmbito, opções à parte',
            'types' => ['landing' => 'Landing page', 'pro' => 'Site institucional Pro', 'premium' => 'Premium multilingue', 'ecommerce' => 'Loja online', 'unsure' => 'A definir em conjunto'],
            'ranges' => ['landing' => '890 a 1 290 € + IVA', 'pro' => '1 890 a 2 900 € + IVA', 'premium' => '3 500 a 6 000 € + IVA', 'ecommerce' => 'a partir de 4 500 € + IVA'],
            'features' => ['form' => 'Formulário de contacto', 'booking' => 'Reservas online', 'adv' => 'Formulários avançados', 'pay' => 'Pagamento online', 'seo' => 'SEO local', 'blog' => 'Blogue ou notícias'],
            'maint' => ['none' => 'Por agora não', 'ess' => 'Essentiel · 49 € + IVA / mês', 'pro' => 'Pro · 99 € + IVA / mês', 'gro' => 'Croissance · 199 € + IVA / mês'],
            'deadlines' => ['asap' => 'O mais rápido possível', 'soon' => 'Dentro de 1 a 2 meses', 'later' => 'Daqui a 3 meses ou mais', 'none' => 'Sem data fixa'],
            'next' => 'OS PRÓXIMOS PASSOS',
            'steps' => ['Analisamos o seu projeto e, se existir, o seu site atual.', 'Recebe uma proposta com três opções, preços sem IVA, no prazo de um dia útil.', 'Após o seu acordo: planeamento passo a passo e arranque (sinal de 40 % para projetos acima de 1 000 € + IVA).'],
            'add' => 'Para avançar mais depressa, <strong>basta responder a este email</strong> com o seu logótipo, textos, fotografias ou sites de que gosta.',
            'call' => 'Prefere falar connosco? Marque uma chamada de 20 minutos ou escreva-nos no WhatsApp:',
            'regards' => 'Com os melhores cumprimentos,', 'team' => 'A equipa DYRO WEB', 'tagline' => 'Criação de sites no Luxemburgo',
            'footer' => 'Recebe esta mensagem porque o seu endereço foi introduzido no formulário de orçamento de dyroweb.online. Se não foi você, ignore-a.',
        ],
        'lb' => [
            'subject' => 'Är Offer-Ufro ass ukomm – DYRO WEB', 'hello' => 'Moien,',
            'intro' => 'Merci, Är Offer-Ufro ass gutt ukomm. Dir kritt <strong>bannent engem Aarbechtsdag</strong> eng kloer Offer mat dräi Optiounen, Präisser ouni TVA.',
            'recap' => 'ÄR UFRO', 'l_type' => 'Projet', 'l_range' => 'Richtpräis', 'l_langs' => 'Sproochen', 'l_feat' => 'Funktiounen', 'l_maint' => 'Maintenance', 'l_deadline' => 'Délai',
            'range_note' => 'jee no Ëmfang, Optiounen extra',
            'types' => ['landing' => 'Landing Page', 'pro' => 'Firmewebsäit Pro', 'premium' => 'Premium méisproocheg', 'ecommerce' => 'Online-Buttek', 'unsure' => 'Zesumme festzeleeën'],
            'ranges' => ['landing' => '890 bis 1.290 € ouni TVA', 'pro' => '1.890 bis 2.900 € ouni TVA', 'premium' => '3.500 bis 6.000 € ouni TVA', 'ecommerce' => 'ab 4.500 € ouni TVA'],
            'features' => ['form' => 'Kontaktformulaire', 'booking' => 'Online-Reservatioun', 'adv' => 'Erweidert Formulairen', 'pay' => 'Online-Bezuelung', 'seo' => 'Lokale SEO', 'blog' => 'Blog oder News'],
            'maint' => ['none' => 'Fir de Moment net', 'ess' => 'Essentiel · 49 € ouni TVA / Mount', 'pro' => 'Pro · 99 € ouni TVA / Mount', 'gro' => 'Croissance · 199 € ouni TVA / Mount'],
            'deadlines' => ['asap' => 'Esou séier wéi méiglech', 'soon' => 'An 1 bis 2 Méint', 'later' => 'An 3 Méint oder méi spéit', 'none' => 'Keen fixen Datum'],
            'next' => 'DÉI NÄCHST SCHRËTT',
            'steps' => ['Mir kucken Äre Projet an, wann et se gëtt, Är aktuell Websäit.', 'Dir kritt bannent engem Aarbechtsdag eng Offer mat dräi Optiounen, Präisser ouni TVA.', 'No Ärem Accord: Zäitplang Schrëtt fir Schrëtt a Start (40 % Acompte fir Projeten iwwer 1.000 € ouni TVA).'],
            'add' => 'Fir méi séier virunzekommen, <strong>äntwert einfach op dës E-Mail</strong> mat Ärem Logo, Ären Texter, Fotoen oder Websäiten, déi Iech gefalen.',
            'call' => 'Léiwer schwätzen? Reservéiert en Uruff vun 20 Minutten oder schreift eis op WhatsApp:',
            'regards' => 'Mat beschte Gréiss,', 'team' => 'Den DYRO WEB Team', 'tagline' => 'Websäite fir Betriber zu Lëtzebuerg',
            'footer' => 'Dir kritt dëse Message, well Är Adress am Offer-Formulaire vun dyroweb.online agi gouf. Wann Dir dat net wart, ignoréiert en w.e.g.',
        ],
    ];
    $t = $T[$lang] ?? $T['fr'];
    $codes = ['fr' => 'FR', 'de' => 'DE', 'en' => 'EN', 'pt' => 'PT', 'lb' => 'LU'];
    $pick = static fn($v) => is_string($v) ? $v : '';
    $type = $pick($data['type'] ?? null);
    $type = isset($t['types'][$type]) ? $type : 'unsure';
    $langs = array_values(array_unique(array_filter(is_array($data['langs'] ?? null) ? $data['langs'] : [], static fn($l) => is_string($l) && isset($codes[$l]))));
    $feats = array_values(array_unique(array_filter(is_array($data['features'] ?? null) ? $data['features'] : [], static fn($f) => is_string($f) && isset($t['features'][$f]))));
    $maint = $pick($data['maint'] ?? null);
    $deadline = $pick($data['deadline'] ?? null);

    $rows = [[$t['l_type'], '<strong>' . $t['types'][$type] . '</strong>']];
    if (isset($t['ranges'][$type])) {
        $rows[] = [$t['l_range'], '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:19px;font-weight:bold;color:#0062d6">' . $t['ranges'][$type] . '</span><br><span style="font-size:13px;color:#6b6b6b">' . $t['range_note'] . '</span>'];
    }
    if ($langs) {
        $rows[] = [$t['l_langs'], implode(' · ', array_map(static fn($l) => $codes[$l], $langs))];
    }
    if ($feats) {
        $rows[] = [$t['l_feat'], implode(', ', array_map(static fn($f) => $t['features'][$f], $feats))];
    }
    if (isset($t['maint'][$maint]) && $maint !== 'none') {
        $rows[] = [$t['l_maint'], $t['maint'][$maint]];
    }
    if (isset($t['deadlines'][$deadline])) {
        $rows[] = [$t['l_deadline'], $t['deadlines'][$deadline]];
    }

    $plain = static fn(string $s) => html_entity_decode(strip_tags(str_replace('<br>', ' – ', $s)), ENT_QUOTES, 'UTF-8');
    $sep = $lang === 'fr' ? ' : ' : ': ';
    $rule = str_repeat('─', 28);
    $text = $t['hello'] . "\n\n" . $plain($t['intro']) . "\n\n" . $t['recap'] . "\n" . $rule . "\n";
    foreach ($rows as [$label, $value]) {
        $text .= '- ' . $label . $sep . $plain($value) . "\n";
    }
    $text .= "\n" . $t['next'] . "\n" . $rule . "\n";
    foreach ($t['steps'] as $n => $s) {
        $text .= ($n + 1) . '. ' . $s . "\n";
    }
    $text .= "\n" . $plain($t['add']) . "\n" . $t['call'] . " +352 691 349 632\n\n" . $t['regards'] . "\n" . $t['team'] . "\n" . $t['tagline']
        . "\ncontact@dyroweb.online · +352 691 349 632 · dyroweb.online\n\n—\n" . $t['footer'] . "\n";

    // Texte simple : c'est le format que les filtres anti-spam laissent passer en boîte de réception
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('DYRO WEB') . '?= <' . $from . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-replied',
    ];
    $body = $text;
    send_mail($to, '=?UTF-8?B?' . base64_encode($t['subject']) . '?=', $body, $headers, $from);
}

/**
 * Envoi : par la boîte noreply (SMTP authentifié, signé DKIM : évite les courriers indésirables)
 * si le fichier de configuration privé existe, sinon par la fonction mail() du serveur.
 *
 * Fichier privé (jamais dans Git) : api/.smtp.php
 *   <?php return ['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => 'noreply@…', 'pass' => '…'];
 */
function send_mail(string $to, string $subject, string $body, array $headers, string $from): bool
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/.smtp.php';
        $cfg = is_file($file) ? (include $file) : false;
    }
    if (is_array($cfg) && !empty($cfg['pass'])) {
        if (smtp_send($cfg, $to, $subject, $body, $headers, $from)) {
            return true;
        }
    }
    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
}

function smtp_send(array $cfg, string $to, string $subject, string $body, array $headers, string $from): bool
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client('ssl://' . $cfg['host'] . ':' . (int) ($cfg['port'] ?? 465), $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log('DYRO SMTP connexion : ' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $step = static function (?string $command, array $codes) use ($fp, $read): bool {
        if ($command !== null) {
            fwrite($fp, $command . "\r\n");
        }
        $answer = $read();
        if (!in_array((int) substr($answer, 0, 3), $codes, true)) {
            error_log('DYRO SMTP : ' . trim($answer));
            return false;
        }
        return true;
    };
    $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
    $message = 'Date: ' . date('r') . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . $subject . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . ">\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n"
        . preg_replace('/\r\n|\r|\n/', "\r\n", $body);
    $message = preg_replace('/^\./m', '..', $message); // un point en début de ligne est doublé (RFC 5321)
    $ok = $step(null, [220])
        && $step('EHLO ' . $domain, [250])
        && $step('AUTH LOGIN', [334])
        && $step(base64_encode($cfg['user']), [334])
        && $step(base64_encode($cfg['pass']), [235])
        && $step('MAIL FROM:<' . $from . '>', [250])
        && $step('RCPT TO:<' . $to . '>', [250, 251])
        && $step('DATA', [354])
        && $step($message . "\r\n.", [250]);
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

