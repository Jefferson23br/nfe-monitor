<?php
require 'vendor/autoload.php';
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;

$config = require 'config.php';

try {
    $pdo = new PDO("pgsql:host=localhost;dbname=nfe_monitor", "postgres", "356985Dp@");
    $certificate = Certificate::readPfx(file_get_contents($config['pfx_path']), $config['pfx_password']);
    $tools = new Tools(json_encode($config), $certificate);

    $stmt = $pdo->query("SELECT valor FROM config_monitor WHERE campo = 'ultimo_nsu'");
    $seuNSU = (int)$stmt->fetchColumn();

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
