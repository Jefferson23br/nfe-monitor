<?php
$projectRoots = [
    '/root/nfe-monitor',        // VPS (producao)
    dirname(__DIR__, 2),        // ambiente local
];

$autoloadPath = null;
$configPath = null;

foreach ($projectRoots as $root) {
    $candidateAutoload = $root . '/vendor/autoload.php';
    $candidateConfig = $root . '/config.php';

    if (file_exists($candidateAutoload) && file_exists($candidateConfig)) {
        $autoloadPath = $candidateAutoload;
        $configPath = $candidateConfig;
        break;
    }
}

if ($autoloadPath === null || $configPath === null) {
    http_response_code(500);
    exit('Erro interno: caminhos de autoload/config nao encontrados.');
}

require $autoloadPath;

use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;

$config = require $configPath;

$chave = isset($_GET['chave']) ? preg_replace('/\D/', '', (string)$_GET['chave']) : '';
if (strlen($chave) !== 44) {
    http_response_code(400);
    exit('Chave invalida.');
}

try {
    $certificate = Certificate::readPfx(file_get_contents($config['pfx_path']), $config['pfx_password']);
    $tools = new Tools(json_encode($config), $certificate);
    $tools->model('55');

    // Tenta manifestar para facilitar liberacao do XML completo.
    try {
        $tools->sefazManifesta($chave, '210210', 'Ciencia da Operacao', 1);
    } catch (\Throwable $e) {
        // Ignora erro de duplicidade/manifesto ja existente.
    }

    // Mantido com 3 parametros para compatibilidade com a versao do seu projeto.
    $xmlResponse = $tools->sefazDistDFe(0, 0, $chave);
    $dom = new DOMDocument();
    $dom->loadXML($xmlResponse);

    $docZipList = $dom->getElementsByTagName('docZip');
    if ($docZipList->length === 0) {
        $xMotivoNode = $dom->getElementsByTagName('xMotivo')->item(0);
        $xMotivo = $xMotivoNode ? $xMotivoNode->nodeValue : 'Sem retorno da SEFAZ.';
        http_response_code(404);
        exit('XML nao disponivel. Motivo: ' . $xMotivo);
    }

    $xmlCompleto = null;
    foreach ($docZipList as $docZip) {
        $gzData = base64_decode($docZip->nodeValue, true);
        if ($gzData === false) {
            continue;
        }

        $xmlInterno = gzdecode($gzData);
        if ($xmlInterno === false) {
            continue;
        }

        $domInterno = new DOMDocument();
        if (!@$domInterno->loadXML($xmlInterno)) {
            continue;
        }

        if (
            $domInterno->getElementsByTagName('nfeProc')->length > 0 ||
            $domInterno->getElementsByTagName('procNFe')->length > 0 ||
            $domInterno->getElementsByTagName('NFe')->length > 0
        ) {
            $xmlCompleto = $xmlInterno;
            break;
        }
    }

    if ($xmlCompleto === null) {
        http_response_code(409);
        exit('SEFAZ ainda nao liberou o XML completo desta nota. Tente novamente em alguns minutos.');
    }

    $arquivo = 'NFe-' . $chave . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    header('Content-Length: ' . strlen($xmlCompleto));
    echo $xmlCompleto;
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'Erro ao baixar XML: ' . $e->getMessage();
}
