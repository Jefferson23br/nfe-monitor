<?php
require 'vendor/autoload.php';
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;

$config = require 'config.php';
$chave = '35260355973366000139550000008882021848811284';

try {
    $pdo = new PDO("pgsql:host=localhost;dbname=nfe_monitor", "postgres", "356985Dp@");
    $certificate = Certificate::readPfx(file_get_contents($config['pfx_path']), $config['pfx_password']);
    $tools = new Tools(json_encode($config), $certificate);

    echo "1. Verificando/Manifestando nota...\n";
    // Manifestamos. Se já foi feito, a SEFAZ só vai dar um aviso de duplicidade, não tem problema.
    $tools->sefazManifesta($chave, '210210', 'Ciencia da Operacao', 1);

    echo "2. Buscando pacote de dados na SEFAZ...\n";
    $xmlResponse = $tools->sefazDistDFe(0, 0, $chave);
    
    $dom = new DOMDocument();
    $dom->loadXML($xmlResponse);
    
    // O pulo do gato: A SEFAZ manda o dado compactado em 'docZip'
    $docZip = $dom->getElementsByTagName('docZip')->item(0);

    if ($docZip) {
        echo "3. Descompactando documento...\n";
        $conteudoXml = gzdecode(base64_decode($docZip->nodeValue));
        
        $interno = new DOMDocument();
        $interno->loadXML($conteudoXml);

        // Tenta encontrar como Nota Completa (nfeProc) ou Resumo (resNFe)
        $isFull = $interno->getElementsByTagName('infNFe')->item(0);
        $resumo = $interno->getElementsByTagName('resNFe')->item(0);

        if ($isFull || $resumo) {
            $node = $isFull ? $interno : $resumo;
            
            $cnpj  = $node->getElementsByTagName('CNPJ')->item(0)->nodeValue;
            $nome  = $node->getElementsByTagName('xNome')->item(0)->nodeValue;
            $valor = $node->getElementsByTagName('vNF')->item(0)->nodeValue;
            $data  = str_replace('T', ' ', substr($node->getElementsByTagName('dhEmi')->item(0)->nodeValue, 0, 19));

            $sql = "INSERT INTO notas_fiscais (chNFe, cnpj_emitente, nome_emitente, valor_nota, data_emissao, nsu) 
                    VALUES (?, ?, ?, ?, ?, '0') ON CONFLICT (chNFe) DO NOTHING";
            $pdo->prepare($sql)->execute([$chave, $cnpj, $nome, $valor, $data]);
            
            echo "✅ SUCESSO ABSOLUTO: A nota de R$ $valor foi salva!\n";
        } else {
            echo "⚠️ O pacote continha apenas um Evento. A SEFAZ ainda não liberou a nota real.\n";
        }
    } else {
        $xMotivo = $dom->getElementsByTagName('xMotivo')->item(0)->nodeValue;
        echo "❌ SEFAZ respondeu: $xMotivo. Tente novamente em 5 min.\n";
    }

} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
