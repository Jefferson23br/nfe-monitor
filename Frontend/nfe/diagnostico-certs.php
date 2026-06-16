<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_require();

header('Content-Type: text/plain; charset=utf-8');

$user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : (get_current_user() ?: '?');

echo "=== Diagnóstico certificados ===\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'Usuário PHP: ' . $user . "\n";
echo 'OPENSSL_CONF: ' . (getenv('OPENSSL_CONF') ?: '(não definido)') . "\n\n";

$dirs = array_unique([
    '/var/lib/nfe-monitor/certs',
    (defined('NFE_PROJECT_ROOT') ? NFE_PROJECT_ROOT : dirname(__DIR__, 2)) . '/certs',
    empresa_certs_dir(),
]);

foreach ($dirs as $dir) {
    echo "--- $dir ---\n";
    echo 'existe: ' . (is_dir($dir) ? 'sim' : 'não') . "\n";
    if (!is_dir($dir)) {
        echo "\n";
        continue;
    }
    echo 'gravável (is_writable): ' . (is_writable($dir) ? 'sim' : 'não') . "\n";
    $teste = $dir . '/.teste-' . bin2hex(random_bytes(4));
    $ok = @file_put_contents($teste, 'ok') !== false;
    echo 'teste file_put_contents: ' . ($ok ? 'sim' : 'não') . "\n";
    if ($ok) {
        @unlink($teste);
    }
    $perms = substr(sprintf('%o', fileperms($dir)), -4);
    $owner = function_exists('posix_getpwuid')
        ? (posix_getpwuid(fileowner($dir))['name'] ?? fileowner($dir))
        : fileowner($dir);
    echo "permissões: $perms | dono: $owner\n\n";
}

echo "Pasta usada no cadastro: " . empresa_certs_dir() . "\n";
