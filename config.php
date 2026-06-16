<?php

declare(strict_types=1);

/**
 * Carrega configuração fiscal local (não versionada).
 * Na primeira instalação: cp config.example.php config.local.php
 */
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    return require $local;
}

throw new RuntimeException(
    'Arquivo config.local.php não encontrado. Copie config.example.php para config.local.php e configure.'
);
