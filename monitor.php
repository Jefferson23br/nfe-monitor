<?php
require 'vendor/autoload.php';

use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;

$config = require 'config.php';
$statusFile = __DIR__ . '/status_robot.json';

// --- 1. PROTEÇÃO DE QUARENTENA ---
if (file_exists($statusFile)) {
    $status = json_decode(file_get_contents($statusFile), true);
    $tempoPassado = time() - ($status['ultima_consulta'] ?? 0);
    if (($status['cStat'] ?? '') == '656' && $tempoPassado < 21600) {
        exit("⚠️ [AGUARDANDO] Sistema em gelo preventivo.\n");
    }
}

try {
    $pdo = new PDO("pgsql:host=localhost;dbname=nfe_monitor", "postgres", "356985Dp@");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $certificate = Certificate::readPfx(file_get_contents($config['pfx_path']), $config['pfx_password']);
    $tools = new Tools(json_encode($config), $certificate);

    $stmt = $pdo->query("SELECT valor FROM config_monitor WHERE campo = 'ultimo_nsu'");
    $ultimoNSU = $stmt->fetchColumn() ?: '0';
    $proximoNSU = (int)$ultimoNSU + 1;

    echo "🔍 Tentando NSU: $proximoNSU...\n";

    // --- 2. CONSULTA À SEFAZ ---
    $xml = $tools->sefazDistDFe($ultimoNSU, $proximoNSU); 
    
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    
    $cStat = $dom->getElementsByTagName('cStat')->item(0)->nodeValue;
    $xMotivo = $dom->getElementsByTagName('xMotivo')->item(0)->nodeValue;

    // --- 3. REGISTRO DE TENTATIVA (A NOVIDADE AQUI) ---
    // Mesmo que não salve nota, ele vai registrar o que a SEFAZ falou no banco
    $pdo->prepare("INSERT INTO registros_robot (tipo, descricao, nsu) VALUES (?, ?, ?)")
        ->execute(['TENTATIVA', "Resposta SEFAZ para NSU $proximoNSU: [$cStat] $xMotivo", $ultimoNSU]);

    file_put_contents($statusFile, json_encode([
        'ultima_consulta' => time(),
        'cStat' => $cStat,
        'nsu_tentado' => $proximoNSU
    ]));

    // --- 4. TRATAMENTO DE RETORNOS ---

    if ($cStat == '656') {
        exit("🚨 Erro 656: Consumo indevido.\n");
    }

    if ($cStat == '137') {
        exit("✅ SEFAZ confirmou: NSU $proximoNSU ainda não existe.\n");
    }

    if ($cStat == '138') {
        $lote = $dom->getElementsByTagName('docZip');
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

                try { $tools->sefazManifesta($chave, '210210', 'Ciencia da Operacao', 1); } catch (\Exception $e) {}

                $sql = "INSERT INTO notas_fiscais (chNFe, cnpj_emitente, nome_emitente, valor_nota, data_emissao, nsu) 
                        VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (chNFe) DO NOTHING";
                $pdo->prepare($sql)->execute([
                    $chave,
                    $node->getElementsByTagName('CNPJ')->item(0)->nodeValue ?? $node->getElementsByTagName('CPF')->item(0)->nodeValue,
                    $nome,
                    $valor,
                    substr($node->getElementsByTagName('dhEmi')->item(0)->nodeValue, 0, 19),
                    $nsuEncontrado
                ]);

                $pdo->prepare("INSERT INTO registros_robot (tipo, descricao, nsu) VALUES (?, ?, ?)")
                    ->execute(['SUCESSO', "Nota R$ $valor salva com sucesso.", $nsuEncontrado]);
            } else {
                $pdo->prepare("INSERT INTO registros_robot (tipo, descricao, nsu) VALUES (?, ?, ?)")
                    ->execute(['SUCESSO', "Evento de sistema processado.", $nsuEncontrado]);
            }
            
            $pdo->prepare("UPDATE config_monitor SET valor = ? WHERE campo = 'ultimo_nsu'")->execute([$nsuEncontrado]);
        }
    }

} catch (\Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
