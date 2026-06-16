<?php
require 'vendor/autoload.php';
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;

$config = require 'config.php';

try {
    require_once __DIR__ . '/includes/database.php';
    $pdo = db();
    $empresaId = empresa_id_do_config();
    $certificate = Certificate::readPfx(file_get_contents($config['pfx_path']), $config['pfx_password']);
    $tools = new Tools(json_encode($config), $certificate);

    $stmt = $pdo->prepare("SELECT valor FROM config_monitor WHERE empresa_id = :e AND campo = 'ultimo_nsu'");
    $stmt->execute(['e' => $empresaId]);
    $seuNSU = (int) $stmt->fetchColumn();

    $xml = $tools->sefazDistDFe($seuNSU);
    $dom = new DOMDocument();
    $dom->loadXML($xml);

    $cStat = $dom->getElementsByTagName('cStat')->item(0)->nodeValue;
    $xMotivo = $dom->getElementsByTagName('xMotivo')->item(0)->nodeValue;

    echo "\n----------------------------------------\n";
    echo "Resposta SEFAZ: [$cStat] $xMotivo\n";

    if ($cStat == '138' || $cStat == '137') {
        $maxNSU = (int)$dom->getElementsByTagName('maxNSU')->item(0)->nodeValue;
        echo "Sua posição atual (Banco): $seuNSU\n";
        echo "Fim da fila na SEFAZ (MaxNSU): $maxNSU\n";
        echo "Faltam: " . ($maxNSU - $seuNSU) . " documentos.\n";
    } else {
        echo "⚠️ A SEFAZ recusou a consulta. Por isso o MaxNSU veio como 0.\n";
        if ($cStat == '656') {
            echo "🚨 MOTIVO: Consumo Indevido. Aguarde 60 min sem rodar NADA.\n";
        }
    }
    echo "----------------------------------------\n\n";

} catch (\Exception $e) { echo "Erro: " . $e->getMessage(); }
