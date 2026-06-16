<?php

declare(strict_types=1);

/**
 * Sincroniza o NSU inicial de empresas com a fila SEFAZ (maxNSU).
 *
 * Uso:
 *   php scripts/sincronizar-nsu-inicial.php              # todas ativas com NSU 0
 *   php scripts/sincronizar-nsu-inicial.php --empresa=2   # uma empresa
 *   php scripts/sincronizar-nsu-inicial.php --todas       # força todas as ativas
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Execute apenas via linha de comando.\n");
    exit(1);
}

require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/empresa.php';
require dirname(__DIR__) . '/includes/monitor_helpers.php';

monitor_openssl_legacy_env();

$opts = getopt('', ['empresa::', 'todas']);
$empresaId = isset($opts['empresa']) ? (int) $opts['empresa'] : null;
$todas = array_key_exists('todas', $opts);

$pdo = db();

if ($empresaId !== null && $empresaId > 0) {
    $ids = [$empresaId];
} elseif ($todas) {
    $ids = array_map('intval', $pdo->query('SELECT id FROM empresas WHERE ativo = TRUE ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
} else {
    $stmt = $pdo->query(
        "SELECT e.id FROM empresas e
         LEFT JOIN config_monitor c ON c.empresa_id = e.id AND c.campo = 'ultimo_nsu'
         WHERE e.ativo = TRUE AND COALESCE(c.valor, '0') IN ('0', '000000000000000')
         ORDER BY e.id"
    );
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

if ($ids === []) {
    echo "Nenhuma empresa pendente de sincronização NSU.\n";
    exit(0);
}

$erros = 0;
foreach ($ids as $id) {
    $empresa = monitor_empresa_por_id($id);
    echo "🏢 {$empresa['nome_fantasia']} (id=$id)\n";
    $sync = monitor_sincronizar_nsu_inicial($id);
    if ($sync['ok']) {
        echo '✅ NSU: ' . ($sync['nsu'] ?? '0');
        if (!empty($sync['mensagem'])) {
            echo ' — ' . $sync['mensagem'];
        }
        echo "\n\n";
    } else {
        echo '❌ ' . ($sync['erro'] ?? 'Erro desconhecido') . "\n\n";
        $erros++;
    }
}

exit($erros > 0 ? 1 : 0);
