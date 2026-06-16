<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/empresa.php';
require_once __DIR__ . '/includes/monitor_helpers.php';

monitor_openssl_legacy_env();

$empresaId = monitor_empresa_id_from_cli() ?? empresa_id_do_config();
$ajustar = null;

foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--ajustar=')) {
        $ajustar = substr($arg, 9);
    }
}

$empresa = monitor_empresa_por_id($empresaId);

echo "🏢 {$empresa['nome_fantasia']} (id=$empresaId)\n\n";

if ($ajustar !== null && $ajustar !== '') {
    $resultado = monitor_definir_ultimo_nsu_manual($empresaId, $ajustar);
    if (!$resultado['ok']) {
        echo '❌ ' . ($resultado['erro'] ?? 'Erro ao ajustar NSU.') . "\n";
        exit(1);
    }
    echo '✅ NSU gravado manualmente: ' . $resultado['nsu'] . "\n\n";
}

$info = monitor_consultar_distancia_nsu($empresaId);

echo "📌 Último NSU gravado (banco): {$info['ultimo_gravado']}\n";

if (!$info['ok']) {
    echo '❌ ' . ($info['erro'] ?? 'Falha na consulta.') . "\n";
    exit(1);
}

echo "📡 SEFAZ [$info[cStat]] {$info['xMotivo']}\n";
if (!empty($info['ultNSU'])) {
    echo "↪ ultNSU (resposta): {$info['ultNSU']}\n";
}
if (!empty($info['maxNSU'])) {
    echo "🏁 maxNSU (fim da fila): {$info['maxNSU']}\n";
}
if (isset($info['distancia'])) {
    echo "📏 Distância (maxNSU − gravado): {$info['distancia']} documento(s)\n";
}

echo "\nAjustar manualmente:\n";
echo "  php consultar_distancia.php --empresa=$empresaId --ajustar=NUMERO\n";
