#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/empresa.php';
require_once __DIR__ . '/../includes/monitor_helpers.php';

$empresaId = null;
$forcar = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--empresa=')) {
        $empresaId = (int) substr($arg, 10);
    }
    if ($arg === '--forcar') {
        $forcar = true;
    }
}

if ($empresaId === null || $empresaId <= 0) {
    fwrite(STDERR, "Uso: php monitor-deve-iniciar.php --empresa=ID [--forcar]\n");
    exit(2);
}

empresa_garantir_schema();
echo monitor_deve_iniciar_ciclo($empresaId, $forcar) ? "SIM\n" : "NAO\n";
