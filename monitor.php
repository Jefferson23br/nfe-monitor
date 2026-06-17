<?php

// monitor-cron-v2 — throttle por slot + bypass quando NFE_MONITOR_CRON=1 / --cron



require __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/includes/database.php';

require_once __DIR__ . '/includes/empresa.php';

require_once __DIR__ . '/includes/monitor_helpers.php';



monitor_openssl_legacy_env();

monitor_garantir_timezone();



use NFePHP\NFe\Tools;

use NFePHP\Common\Certificate;



$empresaId = monitor_empresa_id_from_cli() ?? empresa_id_do_config();

$force = monitor_cli_tem_flag('--force');

$viaCron = monitor_invocado_pelo_cron();



empresa_garantir_schema();

$empresaQuota = monitor_empresa_por_id($empresaId);

$bloqueioConsulta = empresa_pode_consultar($empresaQuota);

if (!$bloqueioConsulta['ok']) {

    exit('🚫 Empresa ' . $empresaId . ': ' . ($bloqueioConsulta['mensagem'] ?? 'Consulta bloqueada.') . "\n");

}



monitor_garantir_nsu_inicial($empresaId);



if (!$force && !monitor_pode_consultar_agora($empresaId, $viaCron)) {

    $restante = monitor_minutos_ate_proxima($empresaId);

    $slotAtual = monitor_slot_consulta();

    exit("⏳ Intervalo de 3h (slot $slotAtual): aguarde ~{$restante} min ou use --force.\n");

}



$config = monitor_config_fiscal($empresaId);



$status = monitor_ler_status($empresaId);

if ($status !== null) {

    $tempoPassado = time() - (int) ($status['ultima_consulta'] ?? 0);

    if (($status['cStat'] ?? '') === '656' && $tempoPassado < 21600) {

        $faltamMin = (int) ceil((21600 - $tempoPassado) / 60);

        exit("⚠️ Empresa $empresaId: erro 656 — aguarde ~{$faltamMin} min (bloqueio SEFAZ 6h).\n");

    }

}



try {

    $pdo = db();

    $empresa = monitor_empresa_por_id($empresaId);



    $certificate = Certificate::readPfx(

        file_get_contents($config['pfx_path']),

        $config['pfx_password']

    );

    $tools = new Tools(json_encode($config), $certificate);



    if ($viaCron) {

        monitor_ciclo_consultas_continuo($empresaId, $tools, $pdo, $empresa);

        exit(0);

    }



    $resultado = monitor_executar_consulta_consnsu($empresaId, $tools, $pdo, $empresa, false);

    exit($resultado['mensagem_saida']);



} catch (\Exception $e) {

    echo '❌ Erro: ' . $e->getMessage() . "\n";

    exit(1);

}

