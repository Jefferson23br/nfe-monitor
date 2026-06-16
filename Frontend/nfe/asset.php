<?php

declare(strict_types=1);

/**
 * Serve imagens via PHP (contorna 404 do Nginx em /assets/img/ na VPS).
 * Uso: asset.php?f=logo.png
 */

$tipos = [
    'logo.png' => 'image/png',
    'emblema.png' => 'image/png',
    'emblema.svg' => 'image/svg+xml',
];

$arquivo = basename((string) ($_GET['f'] ?? ''));
if (!isset($tipos[$arquivo])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Arquivo não permitido.';
    exit;
}

$projeto = dirname(__DIR__, 2);
$candidatos = [
    __DIR__ . '/assets/img/' . $arquivo,
    $projeto . '/images/' . $arquivo,
    $projeto . '/Frontend/nfe/assets/img/' . $arquivo,
];

if ($arquivo === 'emblema.png') {
    $candidatos[] = $projeto . '/images/emblema-removebg-preview.png';
}

if ($arquivo === 'logo.png') {
    $candidatos[] = $projeto . '/images/logo-removebg-preview.png';
    $candidatos[] = $projeto . '/images/logo.png';
}

$caminho = null;
foreach ($candidatos as $c) {
    if (is_file($c) && is_readable($c)) {
        $caminho = $c;
        break;
    }
}

if ($caminho === null && $arquivo === 'logo.png') {
    foreach ($candidatos as $c) {
        $alt = str_replace('logo.png', 'emblema.png', $c);
        if ($alt !== $c && is_file($alt) && is_readable($alt)) {
            $caminho = $alt;
            $arquivo = 'emblema.png';
            break;
        }
    }
    if ($caminho !== null) {
        $arquivo = 'emblema.png';
    }
}

if ($caminho === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Imagem não encontrada: {$arquivo}\n";
    echo "Envie para /root/nfe-monitor/images/ e rode: bash scripts/instalar-imagens-vps.sh\n";
    exit;
}

header('Content-Type: ' . $tipos[$arquivo]);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . (string) filesize($caminho));
readfile($caminho);
