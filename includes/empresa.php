<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function empresa_garantir_schema(): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    $pdo = db();
    $pdo->exec('ALTER TABLE empresas ADD COLUMN IF NOT EXISTS certificado_senha TEXT');
    $pdo->exec('ALTER TABLE empresas ADD COLUMN IF NOT EXISTS criado_por_usuario_id INTEGER REFERENCES usuarios(id)');
    $pdo->exec('ALTER TABLE empresas ADD COLUMN IF NOT EXISTS validade_licenca DATE');
    $pdo->exec('ALTER TABLE empresas ADD COLUMN IF NOT EXISTS limite_logs INTEGER');
    $ok = true;
}

function empresa_tz(): DateTimeZone
{
    return new DateTimeZone('America/Sao_Paulo');
}

/** NULL ou vazio = validade indeterminada (sem bloqueio por data). */
function empresa_licenca_indeterminada(array $empresa): bool
{
    $raw = $empresa['validade_licenca'] ?? null;

    return $raw === null || $raw === '';
}

/** NULL ou vazio = limite de logs indeterminado (ilimitado). */
function empresa_limite_logs_indeterminado(array $empresa): bool
{
    $raw = $empresa['limite_logs'] ?? null;

    return $raw === null || $raw === '';
}

/** Licença válida na data informada (ou hoje). NULL em validade_licenca = sem bloqueio por vencimento. */
function empresa_licenca_valida(array $empresa, ?DateTimeInterface $referencia = null): bool
{
    if (empresa_licenca_indeterminada($empresa)) {
        return true;
    }

    $ref = $referencia ?? new DateTimeImmutable('today', empresa_tz());
    $limite = empresa_parse_data((string) $empresa['validade_licenca']);
    if ($limite === null) {
        return true;
    }

    return $ref->format('Y-m-d') <= $limite->format('Y-m-d');
}

function empresa_licenca_vencida(array $empresa, ?DateTimeInterface $referencia = null): bool
{
    return !empresa_licenca_valida($empresa, $referencia);
}

function empresa_parse_data(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($raw, empresa_tz()))->setTime(0, 0);
    } catch (Exception) {
        return null;
    }
}

/** @return array{texto:string,estado:string,data?:string} */
function empresa_format_validade_licenca(array $empresa): array
{
    if (empresa_licenca_indeterminada($empresa)) {
        return ['texto' => 'Indeterminado', 'estado' => 'indeterminado'];
    }

    $dt = empresa_parse_data((string) $empresa['validade_licenca']);
    if ($dt === null) {
        return ['texto' => 'Indeterminado', 'estado' => 'indeterminado'];
    }

    $texto = $dt->format('d/m/Y');
    $estado = empresa_licenca_vencida($empresa) ? 'vencida' : 'valida';

    return ['texto' => $texto, 'estado' => $estado, 'data' => $dt->format('Y-m-d')];
}

function empresa_contar_logs(int $empresaId): int
{
    $stmt = db()->prepare('SELECT count(*) FROM registros_robot WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);

    return (int) $stmt->fetchColumn();
}

function empresa_logs_esgotados(array $empresa, ?int $totalLogs = null): bool
{
    if (empresa_limite_logs_indeterminado($empresa)) {
        return false;
    }

    $limite = (int) $empresa['limite_logs'];
    if ($limite < 1) {
        return false;
    }

    $total = $totalLogs ?? empresa_contar_logs((int) $empresa['id']);

    return $total >= $limite;
}

/**
 * @return array{usado:int,limite:?int,texto:string,estado:string}
 */
function empresa_quota_logs(array $empresa, ?int $totalLogs = null): array
{
    $usado = $totalLogs ?? empresa_contar_logs((int) $empresa['id']);
    $fmt = static fn (int $n): string => number_format($n, 0, ',', '.');

    if (empresa_limite_logs_indeterminado($empresa)) {
        return [
            'usado' => $usado,
            'limite' => null,
            'texto' => $fmt($usado),
            'estado' => 'indeterminado',
        ];
    }

    $limite = (int) $empresa['limite_logs'];
    if ($limite < 1) {
        return [
            'usado' => $usado,
            'limite' => null,
            'texto' => $fmt($usado),
            'estado' => 'indeterminado',
        ];
    }

    $estado = $usado >= $limite ? 'esgotado' : 'ok';

    return [
        'usado' => $usado,
        'limite' => $limite,
        'texto' => $fmt($usado) . ' / ' . $fmt($limite),
        'estado' => $estado,
    ];
}

/**
 * Valida se a empresa pode consultar SEFAZ (robô ou manual). Não bloqueia login.
 *
 * @return array{ok:bool,codigo?:string,mensagem?:string}
 */
function empresa_pode_consultar(array $empresa, ?int $totalLogs = null): array
{
    if (!($empresa['ativo'] ?? true)) {
        return [
            'ok' => false,
            'codigo' => 'inativa',
            'mensagem' => 'Empresa inativa. Contate o suporte.',
        ];
    }

    if (empresa_licenca_vencida($empresa)) {
        $lic = empresa_format_validade_licenca($empresa);

        return [
            'ok' => false,
            'codigo' => 'licenca_vencida',
            'mensagem' => 'Licença expirada em ' . $lic['texto'] . '.',
        ];
    }

    if (empresa_logs_esgotados($empresa, $totalLogs)) {
        $quota = empresa_quota_logs($empresa, $totalLogs);

        return [
            'ok' => false,
            'codigo' => 'logs_esgotados',
            'mensagem' => 'Cota de consultas atingida (' . $quota['limite'] . ').',
        ];
    }

    return ['ok' => true];
}

/** OpenSSL 3: certificados .pfx brasileiros antigos (RC2) exigem provider legacy. */
function empresa_openssl_legacy_env(): void
{
    $conf = (defined('NFE_PROJECT_ROOT') ? NFE_PROJECT_ROOT : dirname(__DIR__)) . '/deploy/openssl-legacy.cnf';
    if (!is_file($conf)) {
        return;
    }
    $opensslConf = getenv('OPENSSL_CONF');
    if ($opensslConf === false || $opensslConf === '') {
        putenv('OPENSSL_CONF=' . $conf);
    }
}

function empresa_certs_dir(): string
{
    $dir = (defined('NFE_PROJECT_ROOT') ? NFE_PROJECT_ROOT : dirname(__DIR__)) . '/certs';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    return $dir;
}

/**
 * Localiza o .pfx da empresa (caminho no banco, empresa_ID.pfx ou CNPJ.pfx).
 * Corrige automaticamente certificado_path no banco quando encontra em local alternativo.
 */
function empresa_resolver_certificado_path(array $empresa): ?string
{
    $certsDir = empresa_certs_dir();
    $empresaId = (int) ($empresa['id'] ?? 0);
    $cnpj = empresa_normalizar_cnpj((string) ($empresa['cnpj'] ?? ''));
    $root = defined('NFE_PROJECT_ROOT') ? NFE_PROJECT_ROOT : dirname(__DIR__);

    $candidatos = [];
    if (!empty($empresa['certificado_path'])) {
        $gravado = (string) $empresa['certificado_path'];
        $candidatos[] = $gravado;
        if (!str_starts_with($gravado, '/') && !preg_match('#^[A-Za-z]:[/\\\\]#', $gravado)) {
            $candidatos[] = $root . '/' . ltrim($gravado, '/');
        }
        $candidatos[] = $certsDir . '/' . basename($gravado);
    }
    if ($empresaId > 0) {
        $candidatos[] = $certsDir . '/empresa_' . $empresaId . '.pfx';
    }
    if ($cnpj !== '') {
        $candidatos[] = $certsDir . '/' . $cnpj . '.pfx';
    }

    $encontrado = null;
    foreach (array_unique($candidatos) as $path) {
        if ($path !== '' && is_file($path)) {
            $encontrado = $path;
            break;
        }
    }

    if ($encontrado === null) {
        return null;
    }

    $gravadoAtual = (string) ($empresa['certificado_path'] ?? '');
    if ($gravadoAtual !== $encontrado && $empresaId > 0) {
        $precisaAtualizar = $gravadoAtual === ''
            || !is_file($gravadoAtual)
            || realpath($gravadoAtual) !== realpath($encontrado);
        if ($precisaAtualizar) {
            db()->prepare('UPDATE empresas SET certificado_path = :p WHERE id = :id')
                ->execute(['p' => $encontrado, 'id' => $empresaId]);
        }
    }

    return $encontrado;
}

function empresa_app_key(): string
{
    $local = dirname(__DIR__) . '/database.local.php';
    if (is_file($local)) {
        $c = require $local;
        if (!empty($c['app_key'])) {
            return (string) $c['app_key'];
        }
    }
    $env = getenv('NFE_APP_KEY');

    return $env !== false && $env !== '' ? $env : 'defina-app_key-em-database.local.php';
}

/** Chaves fracas ou de exemplo não podem criptografar certificados em produção. */
function empresa_app_key_fraca(string $key): bool
{
    $key = trim($key);
    if ($key === '' || strlen($key) < 32) {
        return true;
    }

    $fracas = [
        'defina-app_key-em-database.local.php',
        'gere-uma-chave-longa-aleatoria-aqui',
        'changeme',
        'change-me',
        'secret',
        'app_key',
    ];

    return in_array(strtolower($key), array_map('strtolower', $fracas), true);
}

function empresa_app_key_valida(): bool
{
    return !empresa_app_key_fraca(empresa_app_key());
}

/** Mensagem de erro se app_key não estiver pronta para cadastro de empresas. */
function empresa_exigir_app_key(): ?string
{
    if (empresa_app_key_valida()) {
        return null;
    }

    return 'Configure uma app_key forte em database.local.php ou NFE_APP_KEY '
        . '(mínimo 32 caracteres aleatórios) antes de cadastrar empresas.';
}

function empresa_criptografar_senha_cert(string $senha): string
{
    $key = hash('sha256', empresa_app_key(), true);
    $iv = random_bytes(16);
    $enc = openssl_encrypt($senha, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return base64_encode($iv . $enc);
}

function empresa_descriptografar_senha_cert(string $armazenado): string
{
    $raw = base64_decode($armazenado, true);
    if ($raw === false || strlen($raw) < 17) {
        return $armazenado;
    }
    $key = hash('sha256', empresa_app_key(), true);
    $iv = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return $dec !== false ? $dec : $armazenado;
}

function empresa_normalizar_cnpj(string $cnpj): string
{
    return preg_replace('/\D/', '', $cnpj);
}

function empresa_validar_cnpj(string $cnpj): bool
{
    $cnpj = empresa_normalizar_cnpj($cnpj);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }

    $calc = static function (string $base, int $pesoInicial): int {
        $soma = 0;
        $peso = $pesoInicial;
        for ($i = strlen($base) - 1; $i >= 0; $i--) {
            $soma += (int) $base[$i] * $peso--;
            if ($peso < 2) {
                $peso = 9;
            }
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    };

    $base = substr($cnpj, 0, 12);

    return $calc($base, 5) === (int) $cnpj[12] && $calc($base . $cnpj[12], 6) === (int) $cnpj[13];
}

function empresa_validar_certificado_pfx(string $caminho, string $senha): ?string
{
    if (!is_file($caminho)) {
        return 'Arquivo de certificado não encontrado.';
    }

    $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pfx', 'p12'], true)) {
        return 'Envie um certificado .pfx ou .p12.';
    }

    if (!is_readable(dirname(__DIR__) . '/vendor/autoload.php')) {
        return null;
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    empresa_openssl_legacy_env();

    try {
        \NFePHP\Common\Certificate::readPfx(file_get_contents($caminho), $senha);
    } catch (Throwable $e) {
        return 'Certificado inválido ou senha incorreta: ' . $e->getMessage();
    }

    return null;
}

/**
 * Usuário sem empresa cadastra a própria empresa + certificado (isolamento total por empresa_id).
 *
 * @param array{razao_social:string,nome_fantasia?:string,cnpj:string,uf:string,senha_cert:string} $dados
 * @return array{ok:bool,erro?:string,empresa_id?:int}
 */
function empresa_cadastrar_para_usuario(int $usuarioId, array $dados, array $arquivoUpload): array
{
    empresa_garantir_schema();

    $erroKey = empresa_exigir_app_key();
    if ($erroKey !== null) {
        return ['ok' => false, 'erro' => $erroKey];
    }

    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, empresa_id, email FROM usuarios WHERE id = :id AND ativo = TRUE');
    $stmt->execute(['id' => $usuarioId]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        return ['ok' => false, 'erro' => 'Usuário não encontrado.'];
    }
    if ($usuario['empresa_id'] !== null && $usuario['empresa_id'] !== '') {
        return ['ok' => false, 'erro' => 'Sua conta já possui uma empresa vinculada.'];
    }

    $razao = trim($dados['razao_social'] ?? '');
    $fantasia = trim($dados['nome_fantasia'] ?? '') ?: $razao;
    $cnpj = empresa_normalizar_cnpj($dados['cnpj'] ?? '');
    $uf = strtoupper(trim($dados['uf'] ?? ''));
    $senhaCert = $dados['senha_cert'] ?? '';

    if ($razao === '' || strlen($razao) < 3) {
        return ['ok' => false, 'erro' => 'Informe o nome da empresa.'];
    }
    if (!empresa_validar_cnpj($cnpj)) {
        return ['ok' => false, 'erro' => 'CNPJ inválido.'];
    }
    if (strlen($uf) !== 2) {
        return ['ok' => false, 'erro' => 'Selecione a UF.'];
    }
    if ($senhaCert === '') {
        return ['ok' => false, 'erro' => 'Informe a senha do certificado digital.'];
    }
    if (($arquivoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Envie o arquivo do certificado digital (.pfx).'];
    }

    $dup = $pdo->prepare('SELECT id FROM empresas WHERE cnpj = :c');
    $dup->execute(['c' => $cnpj]);
    if ($dup->fetch()) {
        return ['ok' => false, 'erro' => 'Este CNPJ já está cadastrado no sistema.'];
    }

    $tmp = $arquivoUpload['tmp_name'];
    $erroCert = empresa_validar_certificado_pfx($tmp, $senhaCert);
    if ($erroCert !== null) {
        return ['ok' => false, 'erro' => $erroCert];
    }

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare(
            'INSERT INTO empresas (razao_social, nome_fantasia, cnpj, uf, criado_por_usuario_id, ativo)
             VALUES (:r, :f, :c, :u, :uid, TRUE) RETURNING id'
        );
        $ins->execute([
            'r' => $razao,
            'f' => $fantasia,
            'c' => $cnpj,
            'u' => $uf,
            'uid' => $usuarioId,
        ]);
        $empresaId = (int) $ins->fetchColumn();

        $destino = empresa_certs_dir() . '/' . $cnpj . '.pfx';
        if (!move_uploaded_file($tmp, $destino)) {
            throw new RuntimeException('Não foi possível salvar o certificado no servidor.');
        }
        // 0644: www-data (PHP-FPM) precisa ler para baixar XML e consultar SEFAZ pelo painel
        chmod($destino, 0644);

        $senhaEnc = empresa_criptografar_senha_cert($senhaCert);
        $pdo->prepare(
            'UPDATE empresas SET certificado_path = :p, certificado_senha = :s WHERE id = :id'
        )->execute(['p' => $destino, 's' => $senhaEnc, 'id' => $empresaId]);

        $pdo->prepare(
            "INSERT INTO config_monitor (empresa_id, campo, valor) VALUES (:e, 'ultimo_nsu', '0')
             ON CONFLICT (empresa_id, campo) DO NOTHING"
        )->execute(['e' => $empresaId]);

        $pdo->prepare(
            'UPDATE usuarios SET empresa_id = :e, perfil = :p WHERE id = :u'
        )->execute(['e' => $empresaId, 'p' => 'admin', 'u' => $usuarioId]);

        $pdo->commit();

        return ['ok' => true, 'empresa_id' => $empresaId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'erro' => 'Erro ao cadastrar empresa: ' . $e->getMessage()];
    }
}

/** @return array{ok:bool,path:?string,esperado:string,mensagem:string} */
function empresa_certificado_status(array $empresa): array
{
    $cnpj = empresa_normalizar_cnpj((string) ($empresa['cnpj'] ?? ''));
    $esperado = $cnpj !== '' ? empresa_certs_dir() . '/' . $cnpj . '.pfx' : '';
    $path = empresa_resolver_certificado_path($empresa);

    if ($path === null) {
        return [
            'ok' => false,
            'path' => null,
            'esperado' => $esperado,
            'mensagem' => $esperado !== ''
                ? 'Arquivo não encontrado no servidor. Envie o .pfx pelo painel ou via SCP.'
                : 'Certificado não configurado.',
        ];
    }
    if (!is_readable($path)) {
        return [
            'ok' => false,
            'path' => $path,
            'esperado' => $esperado,
            'mensagem' => 'Arquivo existe mas o PHP não consegue ler. Ajuste permissões (chmod 644).',
        ];
    }

    return [
        'ok' => true,
        'path' => $path,
        'esperado' => $esperado,
        'mensagem' => 'Certificado digital instalado e pronto para uso.',
    ];
}

/**
 * Reenvia/atualiza certificado .pfx de empresa já cadastrada.
 *
 * @return array{ok:bool,erro?:string,path?:string}
 */
function empresa_atualizar_certificado(int $empresaId, string $senhaCert, array $arquivoUpload): array
{
    empresa_garantir_schema();

    $erroKey = empresa_exigir_app_key();
    if ($erroKey !== null) {
        return ['ok' => false, 'erro' => $erroKey];
    }

    $stmt = db()->prepare('SELECT * FROM empresas WHERE id = :id AND ativo = TRUE');
    $stmt->execute(['id' => $empresaId]);
    $empresa = $stmt->fetch();
    if (!$empresa) {
        return ['ok' => false, 'erro' => 'Empresa não encontrada.'];
    }

    if ($senhaCert === '') {
        return ['ok' => false, 'erro' => 'Informe a senha do certificado digital.'];
    }
    if (($arquivoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Envie o arquivo do certificado digital (.pfx).'];
    }

    $cnpj = empresa_normalizar_cnpj((string) $empresa['cnpj']);
    $tmp = $arquivoUpload['tmp_name'];
    $erroCert = empresa_validar_certificado_pfx($tmp, $senhaCert);
    if ($erroCert !== null) {
        return ['ok' => false, 'erro' => $erroCert];
    }

    $destino = empresa_certs_dir() . '/' . $cnpj . '.pfx';
    if (!move_uploaded_file($tmp, $destino)) {
        return ['ok' => false, 'erro' => 'Não foi possível salvar o certificado no servidor.'];
    }
    chmod($destino, 0644);

    $senhaEnc = empresa_criptografar_senha_cert($senhaCert);
    db()->prepare(
        'UPDATE empresas SET certificado_path = :p, certificado_senha = :s WHERE id = :id'
    )->execute(['p' => $destino, 's' => $senhaEnc, 'id' => $empresaId]);

    return ['ok' => true, 'path' => $destino];
}

function empresa_senha_certificado(array $empresa): string
{
    if (!empty($empresa['certificado_senha'])) {
        return empresa_descriptografar_senha_cert((string) $empresa['certificado_senha']);
    }

    $config = require dirname(__DIR__) . '/config.php';

    return (string) ($config['pfx_password'] ?? '');
}
