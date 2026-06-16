<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
$imgDir = $root . '/assets/img';
$files = ['logo.png', 'emblema.png', 'emblema.svg'];

echo "document root: $root\n";
echo 'PHP user: ' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user()) . "\n\n";

echo "=== Permissoes da pasta assets ===\n";
$assets = $root . '/assets';
foreach ([$assets, $imgDir] as $dir) {
    if (!is_dir($dir)) {
        echo "NAO EXISTE: $dir\n";
        continue;
    }
    $perms = substr(sprintf('%o', fileperms($dir)), -4);
    $readable = is_readable($dir) ? 'legivel' : 'SEM LEITURA';
    $executable = is_executable($dir) ? 'acessivel' : 'SEM ACESSO (x)';
    echo "$dir  perms=$perms  $readable  $executable\n";
}

echo "\n=== Arquivos em assets/img/ ===\n";
if (!is_dir($imgDir)) {
    echo "PASTA img NAO EXISTE\n";
} else {
    foreach (scandir($imgDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $imgDir . '/' . $entry;
        $perms = is_file($path) ? substr(sprintf('%o', fileperms($path)), -4) : '?';
        $ok = is_readable($path) ? 'OK' : 'BLOQUEADO';
        $size = is_file($path) ? filesize($path) : 0;
        echo "$entry  perms=$perms  $ok  {$size} bytes\n";
    }
}

echo "\n=== Teste www-data (leitura) ===\n";
foreach ($files as $name) {
    $path = $imgDir . '/' . $name;
    if (!is_file($path)) {
        echo "FALTA: $name\n";
        continue;
    }
    echo is_readable($path) ? "OK leitura PHP: $name\n" : "PHP nao le: $name\n";
}

echo "\n=== URLs para testar ===\n";
echo "https://{$_SERVER['HTTP_HOST']}/assets/img/logo.png\n";
echo "https://{$_SERVER['HTTP_HOST']}/asset.php?f=logo.png\n";

echo "\n=== Correcao na VPS (como root) ===\n";
echo "chmod 755 $assets\n";
echo "chmod -R a+rX $assets\n";
echo "bash /root/nfe-monitor/scripts/fix-permissoes-vps.sh\n";
