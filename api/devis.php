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
    // Mesure d'audience : un devis envoyé (anonyme, voir _stats.php)
    require_once __DIR__ . '/_stats.php';
    $formule = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) (is_scalar($data['type'] ?? null) ? $data['type'] : '')));
    stats_log(['t' => 'ev', 'n' => 'form', 'm' => $formule !== '' ? $formule : 'devis', 'p' => (in_array($lang, ['de', 'en', 'pt', 'lb'], true) ? "/$lang" : '') . '/devis.html']);
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
            'intro' => 'Merci, votre demande de devis a bien été reçue. Nous vous envoyons <strong>sous 48 h</strong> une proposition claire en trois options, prix hors TVA.',
            'recap' => 'VOTRE DEMANDE', 'l_type' => 'Projet', 'l_range' => 'Prix', 'l_langs' => 'Langues', 'l_feat' => 'Fonctionnalités', 'l_maint' => 'Maintenance', 'l_deadline' => 'Délai souhaité',
            'range_note' => 'options en sus, confirmées dans le devis', 'l_aid' => 'Aide SME Packages', 'aid_note' => '* si votre dossier est accepté : l’État rembourse 70 % des coûts éligibles. Nous vous accompagnons pour la préanalyse obligatoire.', 'aids' => ['yes' => 'Oui, vous souhaitez la demander', 'info' => 'Vous souhaitez en savoir plus', 'no' => 'Non'],
            'types' => ['landing' => 'Landing page', 'pro' => 'Pack Pro', 'premium' => 'Pack Croissance', 'ecommerce' => 'Sur mesure et e-commerce', 'ia' => 'Assistant IA', 'unsure' => 'À définir ensemble'],
            'ranges' => ['landing' => '1 290 € HT', 'pro' => '3 900 € HT', 'premium' => '6 900 € HT', 'ecommerce' => '9 900 à 25 000 € HT', 'ia' => '4 900 € HT'], 'nets' => ['pro' => '1 170 € HT après aide*', 'premium' => '2 070 € HT après aide*', 'ecommerce' => '2 970 à 7 500 € HT après aide*', 'ia' => '1 470 € HT après aide*'],
            'features' => ['form' => 'Formulaire de contact', 'booking' => 'Réservation en ligne', 'adv' => 'Formulaires avancés', 'pay' => 'Paiement en ligne', 'seo' => 'SEO local', 'blog' => 'Blog ou actualités'],
            'maint' => ['none' => 'Pas pour l’instant', 'ess' => 'Essentiel · 49 € HT / mois', 'pro' => 'Pro · 99 € HT / mois', 'gro' => 'Croissance · 199 € HT / mois'],
            'deadlines' => ['asap' => 'Dès que possible', 'soon' => 'D’ici 1 à 2 mois', 'later' => 'Dans 3 mois ou plus', 'none' => 'Pas de date fixe'],
            'next' => 'LES PROCHAINES ÉTAPES',
            'steps' => ['Nous étudions votre projet et, si besoin, votre site actuel.', 'Vous recevez une proposition en trois options, prix HT, sous 48 h.', 'Après votre accord : planning étape par étape et démarrage, avec un acompte de 30 %. Si vous demandez l’aide SME Packages, le projet démarre seulement après la validation de votre dossier.'],
            'add' => 'Pour aller plus vite, <strong>répondez simplement à cet email</strong> avec votre logo, vos textes, des photos ou des sites que vous aimez.',
            'call' => 'Vous préférez en parler ? Réservez un appel de 20 minutes :', 'wa' => 'Ou par téléphone :',
            'regards' => 'Bien cordialement,', 'team' => 'L’équipe DYRO WEB', 'tagline' => 'Création de sites web au Luxembourg',
            'footer' => 'Vous recevez ce message car votre adresse a été saisie dans le formulaire de devis de dyroweb.online. Si ce n’est pas vous, ignorez-le.',
        ],
        'de' => [
            'subject' => 'Ihre Angebotsanfrage ist eingegangen – DYRO WEB', 'hello' => 'Guten Tag,',
            'intro' => 'Vielen Dank, Ihre Angebotsanfrage ist eingegangen. Sie erhalten <strong>innerhalb von 48 Stunden</strong> ein klares Angebot mit drei Optionen, Preise netto.',
            'recap' => 'IHRE ANFRAGE', 'l_type' => 'Projekt', 'l_range' => 'Preis', 'l_langs' => 'Sprachen', 'l_feat' => 'Funktionen', 'l_maint' => 'Wartung', 'l_deadline' => 'Zeitrahmen',
            'range_note' => 'Optionen zusätzlich, im Angebot bestätigt', 'l_aid' => 'SME-Packages-Förderung', 'aid_note' => '* wenn Ihr Antrag genehmigt wird: Der Staat erstattet 70 % der förderfähigen Kosten. Wir begleiten Sie bei der verpflichtenden Voranalyse.', 'aids' => ['yes' => 'Ja, Sie möchten sie beantragen', 'info' => 'Sie möchten mehr erfahren', 'no' => 'Nein'],
            'types' => ['landing' => 'Landingpage', 'pro' => 'Pack Pro', 'premium' => 'Pack Croissance', 'ecommerce' => 'Individuell und Onlineshop', 'ia' => 'KI-Assistent', 'unsure' => 'Gemeinsam festzulegen'],
            'ranges' => ['landing' => '1.290 € netto', 'pro' => '3.900 € netto', 'premium' => '6.900 € netto', 'ecommerce' => '9.900 bis 25.000 € netto', 'ia' => '4.900 € netto'], 'nets' => ['pro' => '1.170 € netto nach Förderung*', 'premium' => '2.070 € netto nach Förderung*', 'ecommerce' => '2.970 bis 7.500 € netto nach Förderung*', 'ia' => '1.470 € netto nach Förderung*'],
            'features' => ['form' => 'Kontaktformular', 'booking' => 'Online-Buchung', 'adv' => 'Erweiterte Formulare', 'pay' => 'Online-Zahlung', 'seo' => 'Lokales SEO', 'blog' => 'Blog oder News'],
            'maint' => ['none' => 'Vorerst nicht', 'ess' => 'Essentiel · 49 € netto / Monat', 'pro' => 'Pro · 99 € netto / Monat', 'gro' => 'Croissance · 199 € netto / Monat'],
            'deadlines' => ['asap' => 'So bald wie möglich', 'soon' => 'In 1 bis 2 Monaten', 'later' => 'In 3 Monaten oder später', 'none' => 'Kein festes Datum'],
            'next' => 'DIE NÄCHSTEN SCHRITTE',
            'steps' => ['Wir prüfen Ihr Projekt und, falls vorhanden, Ihre aktuelle Website.', 'Sie erhalten innerhalb von 48 Stunden ein Angebot mit drei Optionen, Preise netto.', 'Nach Ihrer Zustimmung: Zeitplan Schritt für Schritt und Start, mit 30 % Anzahlung. Wenn Sie die SME-Packages-Förderung beantragen, startet das Projekt erst nach Genehmigung Ihres Antrags.'],
            'add' => 'Schneller geht es, wenn Sie <strong>einfach auf diese E-Mail antworten</strong> – mit Ihrem Logo, Ihren Texten, Fotos oder Websites, die Ihnen gefallen.',
            'call' => 'Lieber persönlich? Buchen Sie ein 20-minütiges Gespräch:', 'wa' => 'Oder telefonisch:',
            'regards' => 'Mit freundlichen Grüßen', 'team' => 'Ihr DYRO WEB Team', 'tagline' => 'Websites für Unternehmen in Luxemburg',
            'footer' => 'Sie erhalten diese Nachricht, weil Ihre Adresse im Angebotsformular von dyroweb.online eingegeben wurde. Falls Sie das nicht waren, ignorieren Sie sie bitte.',
        ],
        'en' => [
            'subject' => 'We have received your quote request – DYRO WEB', 'hello' => 'Hello,',
            'intro' => 'Thank you, we have received your quote request. You will receive a clear proposal with three options, prices excl. VAT, <strong>within 48 hours</strong>.',
            'recap' => 'YOUR REQUEST', 'l_type' => 'Project', 'l_range' => 'Price', 'l_langs' => 'Languages', 'l_feat' => 'Features', 'l_maint' => 'Maintenance', 'l_deadline' => 'Timeline',
            'range_note' => 'options extra, confirmed in the quote', 'l_aid' => 'SME Packages grant', 'aid_note' => '* if your application is approved: the State refunds 70% of the eligible costs. We support you with the mandatory pre-analysis.', 'aids' => ['yes' => 'Yes, you want to apply', 'info' => 'You would like to know more', 'no' => 'No'],
            'types' => ['landing' => 'Landing page', 'pro' => 'Pack Pro', 'premium' => 'Pack Croissance', 'ecommerce' => 'Custom and e-commerce', 'ia' => 'AI assistant', 'unsure' => 'To be defined together'],
            'ranges' => ['landing' => '€1,290 excl. VAT', 'pro' => '€3,900 excl. VAT', 'premium' => '€6,900 excl. VAT', 'ecommerce' => '€9,900 to €25,000 excl. VAT', 'ia' => '€4,900 excl. VAT'], 'nets' => ['pro' => '€1,170 excl. VAT after grant*', 'premium' => '€2,070 excl. VAT after grant*', 'ecommerce' => '€2,970 to €7,500 excl. VAT after grant*', 'ia' => '€1,470 excl. VAT after grant*'],
            'features' => ['form' => 'Contact form', 'booking' => 'Online booking', 'adv' => 'Advanced forms', 'pay' => 'Online payment', 'seo' => 'Local SEO', 'blog' => 'Blog or news'],
            'maint' => ['none' => 'Not for now', 'ess' => 'Essentiel · €49 excl. VAT / month', 'pro' => 'Pro · €99 excl. VAT / month', 'gro' => 'Croissance · €199 excl. VAT / month'],
            'deadlines' => ['asap' => 'As soon as possible', 'soon' => 'Within 1 to 2 months', 'later' => 'In 3 months or more', 'none' => 'No fixed date'],
            'next' => 'NEXT STEPS',
            'steps' => ['We study your project and, if relevant, your current website.', 'You receive a proposal with three options, prices excl. VAT, within 48 hours.', 'Once you approve: a step-by-step schedule and kick-off, with a 30% deposit. If you apply for the SME Packages grant, the project only starts once your application is approved.'],
            'add' => 'To speed things up, <strong>simply reply to this email</strong> with your logo, texts, photos or websites you like.',
            'call' => 'Prefer to talk? Book a 20-minute call:', 'wa' => 'Or by phone:',
            'regards' => 'Kind regards,', 'team' => 'The DYRO WEB team', 'tagline' => 'Websites for businesses in Luxembourg',
            'footer' => 'You are receiving this message because your address was entered in the quote form on dyroweb.online. If this wasn’t you, please ignore it.',
        ],
        'pt' => [
            'subject' => 'Recebemos o seu pedido de orçamento – DYRO WEB', 'hello' => 'Olá,',
            'intro' => 'Obrigado, recebemos o seu pedido de orçamento. Vai receber <strong>em 48 horas</strong> uma proposta clara com três opções, preços sem IVA.',
            'recap' => 'O SEU PEDIDO', 'l_type' => 'Projeto', 'l_range' => 'Preço', 'l_langs' => 'Línguas', 'l_feat' => 'Funcionalidades', 'l_maint' => 'Manutenção', 'l_deadline' => 'Prazo',
            'range_note' => 'opções à parte, confirmadas no orçamento', 'l_aid' => 'Apoio SME Packages', 'aid_note' => '* se o seu pedido for aceite: o Estado reembolsa 70 % dos custos elegíveis. Acompanhamo-lo na pré-análise obrigatória.', 'aids' => ['yes' => 'Sim, quer pedi-lo', 'info' => 'Gostaria de saber mais', 'no' => 'Não'],
            'types' => ['landing' => 'Landing page', 'pro' => 'Pack Pro', 'premium' => 'Pack Croissance', 'ecommerce' => 'À medida e loja online', 'ia' => 'Assistente IA', 'unsure' => 'A definir em conjunto'],
            'ranges' => ['landing' => '1 290 € + IVA', 'pro' => '3 900 € + IVA', 'premium' => '6 900 € + IVA', 'ecommerce' => '9 900 a 25 000 € + IVA', 'ia' => '4 900 € + IVA'], 'nets' => ['pro' => '1 170 € + IVA após apoio*', 'premium' => '2 070 € + IVA após apoio*', 'ecommerce' => '2 970 a 7 500 € + IVA após apoio*', 'ia' => '1 470 € + IVA após apoio*'],
            'features' => ['form' => 'Formulário de contacto', 'booking' => 'Reservas online', 'adv' => 'Formulários avançados', 'pay' => 'Pagamento online', 'seo' => 'SEO local', 'blog' => 'Blogue ou notícias'],
            'maint' => ['none' => 'Por agora não', 'ess' => 'Essentiel · 49 € + IVA / mês', 'pro' => 'Pro · 99 € + IVA / mês', 'gro' => 'Croissance · 199 € + IVA / mês'],
            'deadlines' => ['asap' => 'O mais rápido possível', 'soon' => 'Dentro de 1 a 2 meses', 'later' => 'Daqui a 3 meses ou mais', 'none' => 'Sem data fixa'],
            'next' => 'OS PRÓXIMOS PASSOS',
            'steps' => ['Analisamos o seu projeto e, se existir, o seu site atual.', 'Recebe uma proposta com três opções, preços sem IVA, em 48 horas.', 'Após o seu acordo: planeamento passo a passo e arranque, com um sinal de 30 %. Se pedir o apoio SME Packages, o projeto só começa depois da validação do seu pedido.'],
            'add' => 'Para avançar mais depressa, <strong>basta responder a este email</strong> com o seu logótipo, textos, fotografias ou sites de que gosta.',
            'call' => 'Prefere falar connosco? Marque uma chamada de 20 minutos:', 'wa' => 'Ou por telefone:',
            'regards' => 'Com os melhores cumprimentos,', 'team' => 'A equipa DYRO WEB', 'tagline' => 'Criação de sites no Luxemburgo',
            'footer' => 'Recebe esta mensagem porque o seu endereço foi introduzido no formulário de orçamento de dyroweb.online. Se não foi você, ignore-a.',
        ],
        'lb' => [
            'subject' => 'Är Offer-Ufro ass ukomm – DYRO WEB', 'hello' => 'Moien,',
            'intro' => 'Merci, Är Offer-Ufro ass gutt ukomm. Dir kritt <strong>bannent 48 Stonnen</strong> eng kloer Offer mat dräi Optiounen, Präisser ouni TVA.',
            'recap' => 'ÄR UFRO', 'l_type' => 'Projet', 'l_range' => 'Präis', 'l_langs' => 'Sproochen', 'l_feat' => 'Funktiounen', 'l_maint' => 'Maintenance', 'l_deadline' => 'Délai',
            'range_note' => 'Optiounen extra, an der Offer confirméiert', 'l_aid' => 'Hëllef SME Packages', 'aid_note' => '* wann Ären Dossier ugeholl gëtt: de Staat bezillt 70 % vun de förderfäege Käschten zréck. Mir begleeden Iech bei der obligatorescher Virofanalys.', 'aids' => ['yes' => 'Jo, Dir wëllt se ufroen', 'info' => 'Dir wéilt méi wëssen', 'no' => 'Neen'],
            'types' => ['landing' => 'Landing Page', 'pro' => 'Pack Pro', 'premium' => 'Pack Croissance', 'ecommerce' => 'Op Mooss an Online-Buttek', 'ia' => 'KI-Assistent', 'unsure' => 'Zesumme festzeleeën'],
            'ranges' => ['landing' => '1.290 € ouni TVA', 'pro' => '3.900 € ouni TVA', 'premium' => '6.900 € ouni TVA', 'ecommerce' => '9.900 bis 25.000 € ouni TVA', 'ia' => '4.900 € ouni TVA'], 'nets' => ['pro' => '1.170 € ouni TVA no der Hëllef*', 'premium' => '2.070 € ouni TVA no der Hëllef*', 'ecommerce' => '2.970 bis 7.500 € ouni TVA no der Hëllef*', 'ia' => '1.470 € ouni TVA no der Hëllef*'],
            'features' => ['form' => 'Kontaktformulaire', 'booking' => 'Online-Reservatioun', 'adv' => 'Erweidert Formulairen', 'pay' => 'Online-Bezuelung', 'seo' => 'Lokale SEO', 'blog' => 'Blog oder News'],
            'maint' => ['none' => 'Fir de Moment net', 'ess' => 'Essentiel · 49 € ouni TVA / Mount', 'pro' => 'Pro · 99 € ouni TVA / Mount', 'gro' => 'Croissance · 199 € ouni TVA / Mount'],
            'deadlines' => ['asap' => 'Esou séier wéi méiglech', 'soon' => 'An 1 bis 2 Méint', 'later' => 'An 3 Méint oder méi spéit', 'none' => 'Keen fixen Datum'],
            'next' => 'DÉI NÄCHST SCHRËTT',
            'steps' => ['Mir kucken Äre Projet an, wann et se gëtt, Är aktuell Websäit.', 'Dir kritt bannent 48 Stonnen eng Offer mat dräi Optiounen, Präisser ouni TVA.', 'No Ärem Accord: Zäitplang Schrëtt fir Schrëtt a Start, mat engem Acompte vun 30 %. Wann Dir d’Hëllef SME Packages ufrot, fänkt de Projet eréischt no der Validatioun vun Ärem Dossier un.'],
            'add' => 'Fir méi séier virunzekommen, <strong>äntwert einfach op dës E-Mail</strong> mat Ärem Logo, Ären Texter, Fotoen oder Websäiten, déi Iech gefalen.',
            'call' => 'Léiwer schwätzen? Reservéiert en Uruff vun 20 Minutten:', 'wa' => 'Oder per Telefon:',
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
    $aid = $pick($data['aid'] ?? null);
    if (isset($t['ranges'][$type])) {
        $rows[] = [$t['l_range'], '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:19px;font-weight:bold;color:#0062d6">' . $t['ranges'][$type] . '</span><br><span style="font-size:13px;color:#6b6b6b">' . $t['range_note'] . '</span>'];
    }
    // Aide SME Packages : seulement pour les formules éligibles (projet de 3 000 à 25 000 € HT)
    if (isset($t['nets'][$type]) && $aid !== 'no') {
        $v = $t['nets'][$type];
        if (isset($t['aids'][$aid])) $v = $t['aids'][$aid] . '<br>' . $v;
        $rows[] = [$t['l_aid'], $v . '<br><span style="font-size:13px;color:#6b6b6b">' . $t['aid_note'] . '</span>'];
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
    $text .= "\n" . $plain($t['add']) . "\n" . $t['call'] . " https://dyroweb.online/rdv\n" . $t['wa'] . " +352 691 349 632\n\n" . $t['regards'] . "\n" . $t['team'] . "\n" . $t['tagline']
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

