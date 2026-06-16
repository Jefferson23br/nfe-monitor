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

    $stmt = $pdo->prepare("SELECT valor FROM config_monitor WHERE empresa_id = :e AND campo = 'ultimo_nsu'");
    $stmt->execute(['e' => $empresaId]);
    $ultimoNSU = monitor_nsu_pad($stmt->fetchColumn() ?: '0');
    $ultimoNSUInt = (int) $ultimoNSU;
    $proximoNSUInt = $ultimoNSUInt + 1;
    $proximoNSU = monitor_nsu_pad($proximoNSUInt);

    echo "🏢 {$empresa['nome_fantasia']} (id=$empresaId)\n";
    echo "📌 Último NSU gravado: $ultimoNSU\n";
    echo "🔍 Consulta consNSU direta: $proximoNSU (+1)\n";

    // consNSU: consulta um NSU específico (evita distNSU em massa / bloqueio)
    $xml = $tools->sefazDistDFe(0, $proximoNSUInt);

    monitor_marcar_consulta_realizada($empresaId);

    $dom = new DOMDocument();
    $dom->loadXML($xml);

    $cStat = monitor_xml_tag($dom, 'cStat') ?? '';
    $xMotivo = monitor_xml_tag($dom, 'xMotivo') ?? '';

    monitor_registrar_log(
        $empresaId,
        'CONSULTA',
        "consNSU $proximoNSU: [$cStat] $xMotivo",
        $ultimoNSU
    );

    monitor_salvar_status($empresaId, [
        'empresa_id' => $empresaId,
        'ultima_consulta' => time(),
        'cStat' => $cStat,
        'ultimo_nsu_gravado' => $ultimoNSU,
        'nsu_consultado' => $proximoNSU,
    ]);

    if ($cStat === '656') {
        exit("🚨 Erro 656: consumo indevido.\n");
    }

    // Sem documento / NSU à frente do máximo: mantém ultimo_nsu (ex.: 2319), próxima tentativa 2320
    if ($cStat === '137' || $cStat === '589') {
        exit("✅ Consulta registrada. NSU gravado permanece $ultimoNSU (próxima: $proximoNSU).\n"
            . "[$cStat] $xMotivo\n");
    }

    if ($cStat === '138') {
        $lote = $dom->getElementsByTagName('docZip');
        $qtd = $lote->length;
        echo "📦 Itens no lote: $qtd\n";

        foreach ($lote as $item) {
            $nsuEncontrado = $item->getAttribute('NSU');
            $gz = gzdecode(base64_decode($item->nodeValue));
            $domDoc = new DOMDocument();
            $domDoc->loadXML($gz);

            if ($domDoc->getElementsByTagName('resNFe')->length > 0) {
                $node = $domDoc->getElementsByTagName('resNFe')->item(0);
                $chave = $node->getElementsByTagName('chNFe')->item(0)->nodeValue;
                $nome  = $node->getElementsByTagName('xNome')->item(0)->nodeValue;
                $valor = $node->getElementsByTagName('vNF')->item(0)->nodeValue;

                try {
                    $tools->sefazManifesta($chave, '210210', 'Ciencia da Operacao', 1);
                } catch (\Exception $e) {
                }

                $pdo->prepare(
                    'INSERT INTO notas_fiscais (empresa_id, chnfe, cnpj_emitente, nome_emitente, valor_nota, data_emissao, nsu) 
                     VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (empresa_id, chnfe) DO NOTHING'
                )->execute([
                    $empresaId,
                    $chave,
                    $node->getElementsByTagName('CNPJ')->item(0)->nodeValue
                        ?? $node->getElementsByTagName('CPF')->item(0)->nodeValue,
                    $nome,
                    $valor,
                    substr($node->getElementsByTagName('dhEmi')->item(0)->nodeValue, 0, 19),
                    monitor_nsu_pad($nsuEncontrado),
                ]);

                monitor_registrar_log($empresaId, 'SUCESSO', "Nota R$ $valor salva.", $nsuEncontrado);
            } else {
                monitor_registrar_log($empresaId, 'INFO', 'Evento/documento sem resNFe.', $nsuEncontrado);
            }

            monitor_atualizar_ultimo_nsu($empresaId, $nsuEncontrado);
        }

        $stmtNsu = $pdo->prepare("SELECT valor FROM config_monitor WHERE empresa_id = :e AND campo = 'ultimo_nsu'");
        $stmtNsu->execute(['e' => $empresaId]);
        $novoUltimo = monitor_nsu_pad($stmtNsu->fetchColumn() ?: '0');
        exit("✅ Processado. Último NSU gravado: $novoUltimo\n");
    }

    exit("ℹ️ Consulta registrada. NSU gravado: $ultimoNSU. [$cStat] $xMotivo\n");

} catch (\Exception $e) {
    echo '❌ Erro: ' . $e->getMessage() . "\n";
    exit(1);
}
