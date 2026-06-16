<?php
require 'vendor/autoload.php';
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;

$config = require 'config.php';
$pfxContent = file_get_contents($config['pfx_path']);
$certificate = Certificate::readPfx($pfxContent, $config['pfx_password']);
$tools = new Tools(json_encode($config), $certificate);

// Forçamos o NSU 0 para ver o que a SEFAZ tem guardado no início
$xml = $tools->sefazDistDFe('0');

// Vamos ver se existem as tags de Nota ou apenas de Eventos
echo "--- Analisando XML ---\n";
if (strpos($xml, '<resNFe') !== false) echo "SINALIZADO: Existem Notas (resNFe) no XML!\n";
if (strpos($xml, '<resEvento') !== false) echo "SINALIZADO: Existem Eventos (resEvento) no XML!\n";
if (strpos($xml, 'cStat>137') !== false) echo "AVISO: SEFAZ respondeu 'Nenhum documento localizado'.\n";

echo "\n--- XML BRUTO (Primeiros 500 caracteres) ---\n";
echo substr($xml, 0, 500) . "...\n";
