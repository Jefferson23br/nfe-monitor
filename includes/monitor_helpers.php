<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/empresa.php';

function monitor_openssl_legacy_env(): void
{
    if (function_exists('empresa_openssl_legacy_env')) {
        empresa_openssl_legacy_env();
    }
}

function monitor_empresa_id_from_cli(): ?int
{
    global $argv;
    if (!is_array($argv ?? null)) {
        return null;
    }
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--empresa=')) {
            return (int) substr($arg, 10);
        }
    }
    return null;
}

function monitor_empresa_por_id(int $empresaId): array
{
    $stmt = db()->prepare('SELECT * FROM empresas WHERE id = :id AND ativo = TRUE');
    $stmt->execute(['id' => $empresaId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException("Empresa id=$empresaId não encontrada ou inativa.");
    }
    return $row;
}

/** Config fiscal + certificado da empresa (base em config.php) */
function monitor_config_fiscal(int $empresaId): array
{
    $base = require dirname(__DIR__) . '/config.php';
    $empresa = monitor_empresa_por_id($empresaId);
    $configCnpj = preg_replace('/\D/', '', (string) ($base['cnpj'] ?? ''));
    $empresaCnpj = preg_replace('/\D/', '', (string) $empresa['cnpj']);

    $base['cnpj'] = $empresaCnpj;
    $base['razaosocial'] = $empresa['razao_social'];
    if (!empty($empresa['uf'])) {
        $base['siglaUF'] = $empresa['uf'];
    }

    $pfxPath = empresa_resolver_certificado_path($empresa) ?? '';
    if ($pfxPath === '' && $empresaCnpj === $configCnpj && !empty($base['pfx_path']) && is_file($base['pfx_path'])) {
        $pfxPath = (string) $base['pfx_path'];
    }

    if ($pfxPath === '') {
        $cnpj = $empresaCnpj !== '' ? $empresaCnpj : '?';
        throw new RuntimeException(
            "Empresa id=$empresaId: certificado .pfx não encontrado. "
            . "Procurei em certs/empresa_{$empresaId}.pfx e certs/{$cnpj}.pfx. "
            . 'Cadastre ou reenvie o certificado no painel.'
        );
    }
    if (!is_readable($pfxPath)) {
        throw new RuntimeException(
            "Empresa id=$empresaId: certificado existe mas o PHP não consegue ler ($pfxPath). "
            . 'Na VPS: chmod 644 certs/*.pfx e bash scripts/fix-permissoes-vps.sh'
        );
    }
    $base['pfx_path'] = $pfxPath;

    $senhaCert = empresa_senha_certificado($empresa);
    if ($senhaCert === '' && $empresaCnpj === $configCnpj) {
        $senhaCert = (string) ($base['pfx_password'] ?? '');
    }
    if ($senhaCert === '') {
        throw new RuntimeException("Empresa id=$empresaId: senha do certificado não configurada.");
    }
    $base['pfx_password'] = $senhaCert;

    return $base;
}

function monitor_status_dir(): string
{
    return dirname(__DIR__) . '/var/status';
}

function monitor_garantir_dir_status(): void
{
    $dir = monitor_status_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function monitor_status_file(int $empresaId): string
{
    monitor_garantir_dir_status();

    return monitor_status_dir() . '/empresa_' . $empresaId . '.json';
}

function monitor_salvar_status(int $empresaId, array $dados): void
{
    $path = monitor_status_file($empresaId);
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    if (@file_put_contents($path, $json) !== false) {
        return;
    }
    // Fallback: raiz do projeto (instalações antigas)
    $legado = dirname(__DIR__) . '/status_robot_empresa_' . $empresaId . '.json';
    @file_put_contents($legado, $json);
}

/** Lê cache de status (var/status/ ou arquivo legado na raiz). */
function monitor_ler_status(int $empresaId): ?array
{
    $candidatos = [
        monitor_status_file($empresaId),
        dirname(__DIR__) . '/status_robot_empresa_' . $empresaId . '.json',
    ];
    foreach ($candidatos as $path) {
        if (!is_file($path)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    return null;
}

function monitor_garantir_nsu_inicial(int $empresaId): void
{
    db()->prepare(
        "INSERT INTO config_monitor (empresa_id, campo, valor) VALUES (:e, 'ultimo_nsu', '0')
         ON CONFLICT (empresa_id, campo) DO NOTHING"
    )->execute(['e' => $empresaId]);
}

/** Cria coluna criado_em se o banco for antigo (evita erro no painel). */
function monitor_garantir_schema(): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $pdo = db();
    $pdo->exec(
        'ALTER TABLE registros_robot ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP NOT NULL DEFAULT NOW()'
    );
    $ok = true;
}

function monitor_nsu_pad(int|string $nsu): string
{
    return str_pad((string) (int) $nsu, 15, '0', STR_PAD_LEFT);
}

function monitor_openssl_legacy_conf_path(): ?string
{
    $conf = dirname(__DIR__) . '/deploy/openssl-legacy.cnf';

    return is_file($conf) ? $conf : null;
}

/** Prefixo OPENSSL_CONF para subprocessos CLI (putenv no PHP-FPM não ativa provider legacy). */
function monitor_shell_openssl_prefix(): string
{
    $conf = monitor_openssl_legacy_conf_path();
    if ($conf === null) {
        return '';
    }

    return 'OPENSSL_CONF=' . escapeshellarg($conf) . ' ';
}

/** PHP CLI (nunca php-fpm — no painel PHP_BINARY aponta para php-fpm8.3). */
function monitor_php_cli(): string
{
    if (PHP_SAPI === 'cli' && PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'fpm')) {
        return PHP_BINARY;
    }

    foreach (['/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php'] as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    return 'php';
}

function monitor_xml_tag(DOMDocument $dom, string $tag): ?string
{
    $node = $dom->getElementsByTagName($tag)->item(0);

    return $node ? (string) $node->nodeValue : null;
}

function monitor_registrar_log(int $empresaId, string $tipo, string $descricao, ?string $nsu = null): void
{
    monitor_garantir_schema();
    db()->prepare(
        'INSERT INTO registros_robot (empresa_id, tipo, descricao, nsu, criado_em) VALUES (?, ?, ?, ?, NOW())'
    )->execute([$empresaId, $tipo, $descricao, $nsu !== null ? monitor_nsu_pad($nsu) : null]);
}

function monitor_atualizar_ultimo_nsu(int $empresaId, int|string $nsu): void
{
    db()->prepare(
        "UPDATE config_monitor SET valor = ? WHERE empresa_id = ? AND campo = 'ultimo_nsu'"
    )->execute([monitor_nsu_pad($nsu), $empresaId]);
}

function monitor_cli_tem_flag(string $flag): bool
{
    global $argv;
    return is_array($argv ?? null) && in_array($flag, $argv, true);
}

const MONITOR_INTERVALO_HORAS = 3;

/** SEFAZ bloqueia após 20 consultas em 60 min por CNPJ; robô e manual compartilham 18/h por empresa. */
const MONITOR_SEFAZ_JANELA_MINUTOS = 60;
const MONITOR_SEFAZ_LIMITE_CONSULTAS = 18;
const MONITOR_INTERVALO_ENTRE_CONSULTAS_SEG = 60;

/** @deprecated use MONITOR_SEFAZ_LIMITE_CONSULTAS */
const MONITOR_SEFAZ_LIMITE_MANUAL = MONITOR_SEFAZ_LIMITE_CONSULTAS;

function monitor_contar_consultas_sefaz_periodo(
    int $empresaId,
    int $minutos = MONITOR_SEFAZ_JANELA_MINUTOS
): int {
    monitor_garantir_schema();
    $minutos = max(1, $minutos);
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM registros_robot
         WHERE empresa_id = :e
         AND UPPER(tipo) = 'CONSULTA'
         AND criado_em >= NOW() - INTERVAL '1 minute' * :min"
    );
    $stmt->bindValue(':e', $empresaId, PDO::PARAM_INT);
    $stmt->bindValue(':min', $minutos, PDO::PARAM_INT);
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/** Minutos até a consulta ser liberada para a empresa (quando a contagem cair abaixo do limite). */
function monitor_minutos_ate_liberar_consulta_manual(
    int $empresaId,
    int $janelaMinutos = MONITOR_SEFAZ_JANELA_MINUTOS
): int {
    monitor_garantir_schema();
    $limite = MONITOR_SEFAZ_LIMITE_CONSULTAS;
    $contagem = monitor_contar_consultas_sefaz_periodo($empresaId, $janelaMinutos);
    if ($contagem < $limite) {
        return 0;
    }

    $precisaSair = $contagem - $limite + 1;
    $offset = max(0, (int) $precisaSair - 1);
    $janela = (int) $janelaMinutos;
    $stmt = db()->prepare(
        "SELECT criado_em FROM registros_robot
         WHERE empresa_id = :e
         AND UPPER(tipo) = 'CONSULTA'
         AND criado_em >= NOW() - INTERVAL '1 minute' * :janela
         ORDER BY criado_em ASC
         LIMIT 1 OFFSET {$offset}"
    );
    $stmt->bindValue(':e', $empresaId, PDO::PARAM_INT);
    $stmt->bindValue(':janela', $janela, PDO::PARAM_INT);
    $stmt->execute();
    $criadoEm = $stmt->fetchColumn();
    if ($criadoEm === false) {
        return $janelaMinutos;
    }

    $ts = strtotime((string) $criadoEm);
    if ($ts === false) {
        return $janelaMinutos;
    }

    return max(0, (int) ceil(($ts + $janelaMinutos * 60 - time()) / 60));
}

/**
 * Limite de consultas SEFAZ por empresa (automático + manual na janela de 60 min).
 *
 * @return array{
 *   ok: bool,
 *   contagem: int,
 *   limite: int,
 *   minutos_restantes: int,
 *   mensagem?: string
 * }
 */
function monitor_limite_sefaz_consultas(int $empresaId): array
{
    $limite = MONITOR_SEFAZ_LIMITE_CONSULTAS;
    $janela = MONITOR_SEFAZ_JANELA_MINUTOS;
    $contagem = monitor_contar_consultas_sefaz_periodo($empresaId, $janela);

    if ($contagem < $limite) {
        return [
            'ok' => true,
            'contagem' => $contagem,
            'limite' => $limite,
            'minutos_restantes' => 0,
        ];
    }

    $minutosRestantes = monitor_minutos_ate_liberar_consulta_manual($empresaId, $janela);

    return [
        'ok' => false,
        'contagem' => $contagem,
        'limite' => $limite,
        'minutos_restantes' => $minutosRestantes,
        'mensagem' => sprintf(
            'Limite de consultas atingido (%d/%d em %d min, esta empresa). '
            . 'Aguarde ~%d min para nova consulta manual ou lote automático.',
            $contagem,
            $limite,
            $janela,
            $minutosRestantes
        ),
    ];
}

/** @deprecated use monitor_limite_sefaz_consultas($empresaId) */
function monitor_limite_manual_sefaz(int $empresaId): array
{
    return monitor_limite_sefaz_consultas($empresaId);
}

/** Aguarda a janela SEFAZ liberar após atingir o limite de consultas/hora da empresa. */
function monitor_aguardar_liberacao_sefaz(int $empresaId): void
{
    $minutos = monitor_minutos_ate_liberar_consulta_manual($empresaId);
    if ($minutos <= 0) {
        $minutos = MONITOR_SEFAZ_JANELA_MINUTOS;
    }
    echo "⏳ Limite de " . MONITOR_SEFAZ_LIMITE_CONSULTAS . " consultas/hora (empresa $empresaId) atingido. "
        . "Aguardando {$minutos} min...\n";
    sleep($minutos * 60);
}

function monitor_garantir_timezone(): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    date_default_timezone_set('America/Sao_Paulo');
    $ok = true;
}

/** Slot de 3h alinhado ao cron (00, 03, 06, 09…) em America/Sao_Paulo. */
function monitor_slot_consulta(?int $ts = null): string
{
    monitor_garantir_timezone();
    $ts = $ts ?? time();
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    $slotHora = intdiv((int) $dt->format('G'), MONITOR_INTERVALO_HORAS) * MONITOR_INTERVALO_HORAS;

    return $dt->format('Y-m-d') . '-' . sprintf('%02d', $slotHora);
}

function monitor_invocado_pelo_cron(): bool
{
    if (monitor_cli_tem_flag('--cron')) {
        return true;
    }

    return getenv('NFE_MONITOR_CRON') === '1';
}

function monitor_config_valor(int $empresaId, string $campo): ?string
{
    $stmt = db()->prepare('SELECT valor FROM config_monitor WHERE empresa_id = :e AND campo = :c');
    $stmt->execute(['e' => $empresaId, 'c' => $campo]);
    $v = $stmt->fetchColumn();

    return $v !== false ? (string) $v : null;
}

function monitor_config_salvar(int $empresaId, string $campo, string $valor): void
{
    db()->prepare(
        'INSERT INTO config_monitor (empresa_id, campo, valor) VALUES (:e, :c, :v)
         ON CONFLICT (empresa_id, campo) DO UPDATE SET valor = EXCLUDED.valor'
    )->execute(['e' => $empresaId, 'c' => $campo, 'v' => $valor]);
}

function monitor_pode_consultar_agora(int $empresaId, bool $viaCron = false): bool
{
    if ($viaCron) {
        return true;
    }

    $ultima = monitor_config_valor($empresaId, 'ultima_consulta_at');
    if ($ultima === null || $ultima === '') {
        return true;
    }
    $ts = strtotime($ultima);
    if ($ts === false) {
        return true;
    }

    // Compara slots (03h, 06h…), não segundos — evita pular 06h quando a consulta das 03h termina às 03:00:08.
    return monitor_slot_consulta($ts) !== monitor_slot_consulta();
}

function monitor_minutos_ate_proxima(int $empresaId): int
{
    monitor_garantir_timezone();

    $ultima = monitor_config_valor($empresaId, 'ultima_consulta_at');
    if (!$ultima) {
        return 0;
    }
    $ts = strtotime($ultima);
    if ($ts === false) {
        return 0;
    }
    if (monitor_slot_consulta($ts) !== monitor_slot_consulta()) {
        return 0;
    }

    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $slotHora = intdiv((int) $agora->format('G'), MONITOR_INTERVALO_HORAS) * MONITOR_INTERVALO_HORAS;
    $fimSlot = $agora->setTime($slotHora, 0, 0)->modify('+' . MONITOR_INTERVALO_HORAS . ' hours');

    return max(0, (int) ceil(($fimSlot->getTimestamp() - time()) / 60));
}

function monitor_marcar_consulta_realizada(int $empresaId): void
{
    monitor_garantir_timezone();
    monitor_config_salvar($empresaId, 'ultima_consulta_at', date('Y-m-d H:i:s'));
}

/**
 * Executa uma consulta consNSU (último NSU + 1) e processa a resposta da SEFAZ.
 *
 * @return array{
 *   cStat: string,
 *   xMotivo: string,
 *   ultimo_nsu: string,
 *   nsu_consultado: string,
 *   encerrar: bool,
 *   mensagem_saida: string
 * }
 */
function monitor_executar_consulta_consnsu(
    int $empresaId,
    \NFePHP\NFe\Tools $tools,
    PDO $pdo,
    array $empresa,
    bool $modoContinuo = false
): array {
    $sufixoContinuo = $modoContinuo ? ' Continuando...' : '';
    $stmt = $pdo->prepare("SELECT valor FROM config_monitor WHERE empresa_id = :e AND campo = 'ultimo_nsu'");
    $stmt->execute(['e' => $empresaId]);
    $ultimoNSU = monitor_nsu_pad($stmt->fetchColumn() ?: '0');
    $ultimoNSUInt = (int) $ultimoNSU;
    $proximoNSUInt = $ultimoNSUInt + 1;
    $proximoNSU = monitor_nsu_pad($proximoNSUInt);

    echo "🏢 {$empresa['nome_fantasia']} (id=$empresaId)\n";
    echo "📌 Último NSU gravado: $ultimoNSU\n";
    echo "🔍 Consulta consNSU direta: $proximoNSU (+1)\n";

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
        return [
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'ultimo_nsu' => $ultimoNSU,
            'nsu_consultado' => $proximoNSU,
            'encerrar' => true,
            'mensagem_saida' => "🚨 Erro 656: consumo indevido.\n",
        ];
    }

    if ($cStat === '589') {
        return [
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'ultimo_nsu' => $ultimoNSU,
            'nsu_consultado' => $proximoNSU,
            'encerrar' => true,
            'mensagem_saida' => "✅ Sincronizado com SEFAZ. NSU gravado permanece $ultimoNSU.\n"
                . "[$cStat] $xMotivo\n",
        ];
    }

    if ($cStat === '137') {
        monitor_atualizar_ultimo_nsu($empresaId, $proximoNSU);
        $stmtNsu = $pdo->prepare("SELECT valor FROM config_monitor WHERE empresa_id = :e AND campo = 'ultimo_nsu'");
        $stmtNsu->execute(['e' => $empresaId]);
        $novoUltimo = monitor_nsu_pad($stmtNsu->fetchColumn() ?: '0');

        return [
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'ultimo_nsu' => $novoUltimo,
            'nsu_consultado' => $proximoNSU,
            'encerrar' => false,
            'mensagem_saida' => "ℹ️ [$cStat] $xMotivo — NSU avançado para $novoUltimo.$sufixoContinuo\n",
        ];
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

        return [
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'ultimo_nsu' => $novoUltimo,
            'nsu_consultado' => $proximoNSU,
            'encerrar' => false,
            'mensagem_saida' => "✅ Processado. Último NSU gravado: $novoUltimo.$sufixoContinuo\n",
        ];
    }

    return [
        'cStat' => $cStat,
        'xMotivo' => $xMotivo,
        'ultimo_nsu' => $ultimoNSU,
        'nsu_consultado' => $proximoNSU,
        'encerrar' => false,
        'mensagem_saida' => "ℹ️ Consulta registrada. NSU gravado: $ultimoNSU. [$cStat] $xMotivo$sufixoContinuo\n",
    ];
}

/**
 * Ciclo contínuo do cron: consulta a cada 1 min até [589] ou limite de 18/h (aguarda 60 min e retoma).
 */
function monitor_ciclo_consultas_continuo(int $empresaId, \NFePHP\NFe\Tools $tools, PDO $pdo, array $empresa): void
{
    echo '🔄 Modo contínuo: máx ' . MONITOR_SEFAZ_LIMITE_CONSULTAS . ' consultas/hora, '
        . 'intervalo ' . MONITOR_INTERVALO_ENTRE_CONSULTAS_SEG . " s, para apenas em [589].\n";

    while (true) {
        $limite = monitor_limite_sefaz_consultas($empresaId);
        if (!$limite['ok']) {
            monitor_aguardar_liberacao_sefaz($empresaId);
            continue;
        }

        $resultado = monitor_executar_consulta_consnsu($empresaId, $tools, $pdo, $empresa, true);
        echo $resultado['mensagem_saida'];

        if ($resultado['encerrar']) {
            return;
        }

        $limite = monitor_limite_sefaz_consultas($empresaId);
        if (!$limite['ok']) {
            monitor_aguardar_liberacao_sefaz($empresaId);
            continue;
        }

        echo '⏱️ Próxima consulta em ' . MONITOR_INTERVALO_ENTRE_CONSULTAS_SEG . " segundos...\n";
        sleep(MONITOR_INTERVALO_ENTRE_CONSULTAS_SEG);
    }
}

/** Histórico de consultas NSU / atividade do robô */
function monitor_logs_listar(int $empresaId, int $limite = 100): array
{
    monitor_garantir_schema();

    $pdo = db();
    $limite = max(1, min(500, $limite));

    $pdo->exec(
        'UPDATE registros_robot SET empresa_id = ' . (int) $empresaId . ' WHERE empresa_id IS NULL'
    );

    $sql = "SELECT id, empresa_id, tipo, descricao, nsu, criado_em,
            to_char(criado_em, 'DD/MM/YYYY HH24:MI') AS criado_em_br
            FROM registros_robot WHERE empresa_id = :e ORDER BY id DESC LIMIT " . $limite;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['e' => $empresaId]);

    return $stmt->fetchAll() ?: [];
}

function monitor_log_data_hora(array $log): string
{
    if (!empty($log['criado_em_br'])) {
        return (string) $log['criado_em_br'];
    }

    $tz = new DateTimeZone('America/Sao_Paulo');

    foreach (['criado_em', 'data_hora', 'created_at'] as $col) {
        if (!isset($log[$col]) || $log[$col] === '' || $log[$col] === null) {
            continue;
        }
        $valor = $log[$col];
        try {
            if ($valor instanceof DateTimeInterface) {
                $dt = DateTimeImmutable::createFromInterface($valor)->setTimezone($tz);
            } else {
                $dt = new DateTimeImmutable((string) $valor, $tz);
            }
            return $dt->format('d/m/Y H:i');
        } catch (Exception) {
            continue;
        }
    }

    return '—';
}

function monitor_executar(int $empresaId, bool $force = false): string
{
    $root = defined('NFE_PROJECT_ROOT') ? NFE_PROJECT_ROOT : dirname(__DIR__);
    $autoload = $root . '/vendor/autoload.php';
    $monitor = $root . '/monitor.php';

    if (!is_file($monitor)) {
        return "ERRO: monitor.php não encontrado em {$root}";
    }

    $hintPerm = '';
    if (!is_readable($autoload)) {
        $hintPerm = "AVISO: o PHP do site (www-data) não consegue ler vendor/ em /root.\n"
            . "O Composer já instalou; ajuste permissões na VPS:\n"
            . "  chmod 711 /root\n"
            . "  chmod -R 755 /root/nfe-monitor\n"
            . "  bash /root/nfe-monitor/scripts/fix-permissoes-vps.sh\n\n";
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true) && in_array('exec', $disabled, true)) {
        return $hintPerm . 'ERRO: shell_exec/exec desabilitados no PHP-FPM.';
    }

    $empresa = monitor_empresa_por_id($empresaId);
    $bloqueio = empresa_pode_consultar($empresa);
    if (!$bloqueio['ok']) {
        return 'ERRO: ' . ($bloqueio['mensagem'] ?? 'Consulta bloqueada.');
    }

    if ($force) {
        $limiteManual = monitor_limite_sefaz_consultas($empresaId);
        if (!$limiteManual['ok']) {
            return 'ERRO: ' . ($limiteManual['mensagem'] ?? 'Consulta bloqueada pelo limite SEFAZ.');
        }
    }

    $php = monitor_php_cli();
    $forceArg = $force ? ' --force' : '';
    $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($php) . ' '
        . escapeshellarg($monitor) . ' --empresa=' . (int) $empresaId . $forceArg . ' 2>&1';

    $linhas = [];
    if (!in_array('exec', $disabled, true)) {
        exec($cmd, $linhas, $codigo);
        $saida = implode("\n", $linhas);
        if ($saida !== '') {
            if (str_contains($saida, 'vendor/autoload.php')) {
                return $hintPerm . $saida;
            }
            return $saida;
        }
    }

    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
        $saida = (string) shell_exec($cmd);
        if ($saida !== '') {
            if (str_contains($saida, 'vendor/autoload.php')) {
                return $hintPerm . $saida;
            }
            return $saida;
        }
    }

    if ($hintPerm !== '') {
        return $hintPerm . 'ERRO: não foi possível executar o robô pelo painel.';
    }

    if (!is_file($autoload)) {
        return "ERRO: vendor/autoload.php ausente. Rode: cd {$root} && composer install --no-dev";
    }

    return 'ERRO: execução sem saída. Teste: php ' . $monitor . ' --empresa=' . (int) $empresaId . ' --force';
}

/** @return array{ok:bool,nsu?:string,erro?:string} */
function monitor_definir_ultimo_nsu_manual(int $empresaId, string $nsu): array
{
    monitor_garantir_nsu_inicial($empresaId);

    $nsuLimpo = preg_replace('/\D/', '', $nsu);
    if ($nsuLimpo === '' || strlen($nsuLimpo) > 15) {
        return ['ok' => false, 'erro' => 'NSU inválido. Informe até 15 dígitos.'];
    }

    $nsuPad = monitor_nsu_pad($nsuLimpo);
    monitor_config_salvar($empresaId, 'ultimo_nsu', $nsuPad);
    monitor_registrar_log($empresaId, 'AJUSTE', "NSU ajustado manualmente para $nsuPad.", $nsuPad);

    return ['ok' => true, 'nsu' => $nsuPad];
}

/**
 * Consulta distNSU na SEFAZ para comparar NSU gravado com a fila (maxNSU).
 *
 * @return array{
 *   ok:bool,
 *   ultimo_gravado:string,
 *   cStat?:string,
 *   xMotivo?:string,
 *   ultNSU?:string,
 *   maxNSU?:string,
 *   distancia?:int,
 *   erro?:string
 * }
 */
function monitor_consultar_distancia_nsu(int $empresaId): array
{
    monitor_openssl_legacy_env();
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    monitor_garantir_nsu_inicial($empresaId);
    $ultimoGravado = monitor_nsu_pad(monitor_config_valor($empresaId, 'ultimo_nsu') ?: '0');

    $empresa = monitor_empresa_por_id($empresaId);
    $bloqueio = empresa_pode_consultar($empresa);
    if (!$bloqueio['ok']) {
        return [
            'ok' => false,
            'ultimo_gravado' => $ultimoGravado,
            'erro' => $bloqueio['mensagem'] ?? 'Consulta bloqueada.',
        ];
    }

    try {
        $config = monitor_config_fiscal($empresaId);
        $certificate = \NFePHP\Common\Certificate::readPfx(
            file_get_contents($config['pfx_path']),
            $config['pfx_password']
        );
        $tools = new \NFePHP\NFe\Tools(json_encode($config), $certificate);

        $xml = $tools->sefazDistDFe((int) $ultimoGravado);
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        $cStat = monitor_xml_tag($dom, 'cStat') ?? '';
        $xMotivo = monitor_xml_tag($dom, 'xMotivo') ?? '';
        $ultNSU = monitor_xml_tag($dom, 'ultNSU');
        $maxNSU = monitor_xml_tag($dom, 'maxNSU');

        $resultado = [
            'ok' => true,
            'ultimo_gravado' => $ultimoGravado,
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
        ];
        if ($ultNSU !== null && $ultNSU !== '') {
            $resultado['ultNSU'] = monitor_nsu_pad($ultNSU);
        }
        if ($maxNSU !== null && $maxNSU !== '') {
            $resultado['maxNSU'] = monitor_nsu_pad($maxNSU);
            $resultado['distancia'] = max(0, (int) $maxNSU - (int) $ultimoGravado);
        }

        return $resultado;
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'ultimo_gravado' => $ultimoGravado,
            'erro' => $e->getMessage(),
        ];
    }
}

/**
 * Posiciona o NSU no fim da fila SEFAZ (maxNSU) para novas empresas não reprocessarem anos de histórico.
 *
 * @return array{ok:bool,nsu?:string,cStat?:string,xMotivo?:string,mensagem?:string,erro?:string}
 */
function monitor_sincronizar_nsu_inicial(int $empresaId): array
{
    monitor_openssl_legacy_env();
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    monitor_garantir_nsu_inicial($empresaId);

    $empresaSync = monitor_empresa_por_id($empresaId);
    $bloqueioSync = empresa_pode_consultar($empresaSync);
    if (!$bloqueioSync['ok']) {
        return ['ok' => false, 'erro' => $bloqueioSync['mensagem'] ?? 'Consulta bloqueada.'];
    }

    try {
        $config = monitor_config_fiscal($empresaId);
        $certificate = \NFePHP\Common\Certificate::readPfx(
            file_get_contents($config['pfx_path']),
            $config['pfx_password']
        );
        $tools = new \NFePHP\NFe\Tools(json_encode($config), $certificate);

        $xml = $tools->sefazDistDFe(0);
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        $cStat = monitor_xml_tag($dom, 'cStat') ?? '';
        $xMotivo = monitor_xml_tag($dom, 'xMotivo') ?? '';

        if ($cStat === '656') {
            return ['ok' => false, 'erro' => "SEFAZ 656: $xMotivo"];
        }

        $maxNSU = monitor_xml_tag($dom, 'maxNSU');
        if ($maxNSU === null || $maxNSU === '' || (int) $maxNSU <= 0) {
            monitor_registrar_log(
                $empresaId,
                'SYNC',
                "NSU inicial: [$cStat] $xMotivo — fila vazia, permanece 0.",
                '0'
            );

            return [
                'ok' => true,
                'nsu' => '0',
                'cStat' => $cStat,
                'xMotivo' => $xMotivo,
                'mensagem' => 'Nenhum documento na fila SEFAZ; NSU permanece 0.',
            ];
        }

        $nsuPad = monitor_nsu_pad($maxNSU);
        monitor_config_salvar($empresaId, 'ultimo_nsu', $nsuPad);
        monitor_registrar_log(
            $empresaId,
            'SYNC',
            "NSU inicial sincronizado com maxNSU=$nsuPad.",
            $nsuPad
        );

        return [
            'ok' => true,
            'nsu' => $nsuPad,
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'mensagem' => "Próxima consulta automática usará NSU " . monitor_nsu_pad((int) $maxNSU + 1) . '.',
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}

/**
 * Baixa XML completo da NF-e na SEFAZ (manifestação + distDFe por chave).
 *
 * @return array{ok:bool,xml?:string,erro?:string,http_code?:int}
 */
function monitor_baixar_xml_sefaz(int $empresaId, string $chave): array
{
    monitor_openssl_legacy_env();
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    try {
        $config = monitor_config_fiscal($empresaId);
        $certificate = \NFePHP\Common\Certificate::readPfx(
            file_get_contents($config['pfx_path']),
            $config['pfx_password']
        );
        $tools = new \NFePHP\NFe\Tools(json_encode($config), $certificate);
        $tools->model(55);

        try {
            $tools->sefazManifesta($chave, '210210', 'Ciencia da Operacao', 1);
        } catch (Throwable) {
        }

        $xmlResponse = $tools->sefazDistDFe(0, 0, $chave);
        $dom = new DOMDocument();
        $dom->loadXML($xmlResponse);

        $docZipList = $dom->getElementsByTagName('docZip');
        if ($docZipList->length === 0) {
            $xMotivoNode = $dom->getElementsByTagName('xMotivo')->item(0);
            $xMotivo = $xMotivoNode ? (string) $xMotivoNode->nodeValue : 'Sem retorno da SEFAZ.';

            return [
                'ok' => false,
                'erro' => 'XML não disponível. Motivo: ' . $xMotivo,
                'http_code' => 404,
            ];
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
                $domInterno->getElementsByTagName('nfeProc')->length > 0
                || $domInterno->getElementsByTagName('procNFe')->length > 0
                || $domInterno->getElementsByTagName('NFe')->length > 0
            ) {
                $xmlCompleto = $xmlInterno;
                break;
            }
        }

        if ($xmlCompleto === null) {
            return [
                'ok' => false,
                'erro' => 'SEFAZ ainda não liberou o XML completo. Tente novamente em alguns minutos.',
                'http_code' => 409,
            ];
        }

        return ['ok' => true, 'xml' => $xmlCompleto];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'erro' => 'Erro ao baixar XML: ' . $e->getMessage(),
            'http_code' => 500,
        ];
    }
}

/**
 * Download de XML no painel web: usa CLI com OPENSSL_CONF (mesmo caminho do monitor automático).
 *
 * @return array{ok:bool,xml?:string,erro?:string,http_code?:int}
 */
function monitor_baixar_xml(int $empresaId, string $chave): array
{
    if (PHP_SAPI === 'cli') {
        return monitor_baixar_xml_sefaz($empresaId, $chave);
    }

    $root = dirname(__DIR__);
    $script = $root . '/scripts/baixar-xml-cli.php';
    if (!is_file($script)) {
        return [
            'ok' => false,
            'erro' => 'Script baixar-xml-cli.php não encontrado no servidor.',
            'http_code' => 500,
        ];
    }

    $php = monitor_php_cli();
    $cmd = 'cd ' . escapeshellarg($root) . ' && '
        . monitor_shell_openssl_prefix()
        . escapeshellarg($php) . ' '
        . escapeshellarg($script)
        . ' --empresa=' . (int) $empresaId
        . ' --chave=' . escapeshellarg($chave)
        . ' 2>&1';

    $linhas = [];
    $code = 1;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (function_exists('exec') && !in_array('exec', $disabled, true)) {
        exec($cmd, $linhas, $code);
    } elseif (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
        $saida = shell_exec($cmd);
        if ($saida !== null && $saida !== '') {
            $linhas = explode("\n", rtrim($saida, "\n"));
            $code = 0;
        }
    }

    $json = implode("\n", $linhas);
    if ($json === '') {
        return [
            'ok' => false,
            'erro' => 'Não foi possível executar o download (exec/shell_exec desabilitado ou PHP CLI ausente).',
            'http_code' => 500,
        ];
    }

    $resultado = json_decode($json, true);
    if (!is_array($resultado) || !isset($resultado['ok'])) {
        return [
            'ok' => false,
            'erro' => $json,
            'http_code' => 500,
        ];
    }

    if ($code !== 0 && ($resultado['ok'] ?? false) !== true) {
        $resultado['http_code'] = $resultado['http_code'] ?? 500;
    }

    return $resultado;
}
