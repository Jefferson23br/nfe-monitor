<?php
require 'vendor/autoload.php';
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;

$config = require 'config.php';
$pfxContent = file_get_contents($config['pfx_path']);
$certificate = Certificate::readPfx($pfxContent, $config['pfx_password']);
$tools = new Tools(json_encode($config), $certificate);

// Consultar o NSU 0 (início)
$xml = $tools->sefazDistDFe('0');

// Extrair o código de status e o motivo
preg_match('/<cStat>(.*)<\/cStat>/', $xml, $cStat);
preg_match('/<xMotivo>(.*)<\/xMotivo>/', $xml, $xMotivo);
preg_match('/<ultNSU>(.*)<\/ultNSU>/', $xml, $ultNSU);

echo "Status SEFAZ: " . ($cStat[1] ?? 'Erro') . "\n";
echo "Motivo: " . ($xMotivo[1] ?? 'Desconhecido') . "\n";
echo "Ultimo NSU na SEFAZ: " . ($ultNSU[1] ?? '0') . "\n";

if (strpos($xml, '<resNFe') === false) {
    echo "\nAVISO: O XML veio sem a tag <resNFe>. Não há notas prontas para este CNPJ no momento.\n";
} else {
    echo "\nBOAS NOTÍCIAS: Existem notas no XML! Verifique a conexão com o banco.\n";
}
