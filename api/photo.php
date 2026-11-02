<?php
/**
 * DYRO STUDIOS — affiche une photo reçue par le formulaire, via le lien secret de l'email.
 * Le dossier api/uploads est fermé au public ; seul ce script le lit, avec une clé de 32 caractères.
 */
declare(strict_types=1);

$key = (string) ($_GET['k'] ?? '');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
    http_response_code(404);
    exit;
}
foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'] as $ext => $mime) {
    $file = __DIR__ . '/uploads/' . $key . '.' . $ext;
    if (is_file($file)) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="photo-' . substr($key, 0, 8) . '.' . $ext . '"');
        readfile($file);
        exit;
    }
}
http_response_code(404);
