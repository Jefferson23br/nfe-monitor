<?php
require 'vendor/autoload.php';
$config = require 'config.php';
$pfxContent = file_get_contents($config['pfx_path']);
$certificate = \NFePHP\Common\Certificate::readPfx($pfxContent, $config['pfx_password']);
$tools = new \NFePHP\NFe\Tools(json_encode($config), $certificate);

$xml = $tools->sefazDistDFe('0'); // Consulta inicial
$dom = new DOMDocument();
$dom->loadXML($xml);

$maxNSU = $dom->getElementsByTagName('maxNSU')->item(0)->nodeValue;
echo "O NSU mais recente na SEFAZ é: " . $maxNSU . "\n";
echo "Você está no: 3552\n";
echo "Faltam aproximadamente " . ($maxNSU - 3552) . " documentos para processar.\n";
