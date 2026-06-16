<?php

declare(strict_types=1);

/**
 * Download de XML via CLI (OPENSSL_CONF no ambiente — certificados .pfx legados).
 * Uso: php scripts/baixar-xml-cli.php --empresa=1 --chave=44digitos
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Apenas CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/empresa.php';
require_once dirname(__DIR__) . '/includes/monitor_helpers.php';

monitor_openssl_legacy_env();

global $argv;

$empresaId = 0;
$chave = '';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--empresa=')) {
        $empresaId = (int) substr($arg, 10);
    }
    if (str_starts_with($arg, '--chave=')) {
        $chave = preg_replace('/\D/', '', substr($arg, 8));
    }
}

if ($empresaId < 1 || strlen($chave) !== 44) {
    echo json_encode([
        'ok' => false,
        'erro' => 'Parâmetros inválidos: --empresa=ID --chave=44dígitos',
        'http_code' => 400,
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$resultado = monitor_baixar_xml_sefaz($empresaId, $chave);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
exit(($resultado['ok'] ?? false) ? 0 : 1);
