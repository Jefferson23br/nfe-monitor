<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/web.php';

function auth_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_user(): ?array
{
    auth_start();
    return $_SESSION['usuario'] ?? null;
}

function auth_garantir_schema(): void
{
    static $ok = false;
    if ($ok) {
        return;
    }
    try {
        db()->exec('ALTER TABLE usuarios ALTER COLUMN empresa_id DROP NOT NULL');
    } catch (PDOException) {
        // Coluna já nullable ou sem permissão ALTER — migração manual se necessário
    }
    $ok = true;
}

function auth_empresa_id(): ?int
{
    $user = auth_user();
    if (!$user || !isset($user['empresa_id']) || $user['empresa_id'] === null || $user['empresa_id'] === '') {
        return null;
    }

    return (int) $user['empresa_id'];
}

function auth_tem_empresa(): bool
{
    return auth_empresa_id() !== null;
}

function auth_require(): void
{
    if (!auth_user()) {
        header('Location: ' . auth_login_url());
        exit;
    }
}

/** Painel e dados fiscais exigem empresa vinculada. */
function auth_require_empresa(): void
{
    auth_require();
    if (!auth_tem_empresa()) {
        header('Location: ' . url_path('cadastrar-empresa.php'));
        exit;
    }
}

function auth_redirect_apos_login(): void
{
    $destino = auth_tem_empresa() ? auth_dashboard_url() : url_path('cadastrar-empresa.php');
    header('Location: ' . $destino);
    exit;
}

/** Recarrega sessão após vincular empresa (ex.: cadastro de empresa). */
function auth_recarregar_sessao(): void
{
    $user = auth_user();
    if (!$user) {
        return;
    }
    $stmt = db()->prepare(
        'SELECT u.*, e.razao_social, e.nome_fantasia, e.cnpj AS empresa_cnpj
         FROM usuarios u
         LEFT JOIN empresas e ON e.id = u.empresa_id AND e.ativo = TRUE
         WHERE u.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $user['id']]);
    $row = $stmt->fetch();
    if ($row) {
        auth_preencher_sessao($row);
    }
}

function auth_preencher_sessao(array $user): void
{
    auth_start();
    unset($user['senha_hash']);
    $empresaId = $user['empresa_id'] ?? null;

    $_SESSION['usuario'] = [
        'id' => (int) $user['id'],
        'empresa_id' => $empresaId !== null && $empresaId !== '' ? (int) $empresaId : null,
        'nome' => $user['nome'],
        'email' => $user['email'],
        'perfil' => $user['perfil'],
        'empresa_nome' => (string) ($user['nome_fantasia'] ?: $user['razao_social'] ?? ''),
        'empresa_cnpj' => (string) ($user['empresa_cnpj'] ?? ''),
    ];
}

function auth_login(string $email, string $senha): array
{
    auth_garantir_schema();

    $stmt = db()->prepare(
        'SELECT u.*, e.razao_social, e.nome_fantasia, e.cnpj AS empresa_cnpj
         FROM usuarios u
         LEFT JOIN empresas e ON e.id = u.empresa_id AND e.ativo = TRUE
         WHERE u.email = :email AND u.ativo = TRUE
         LIMIT 1'
    );
    $stmt->execute(['email' => mb_strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($senha, $user['senha_hash'])) {
        return ['ok' => false, 'erro' => 'E-mail ou senha inválidos.'];
    }

    if ($user['empresa_id'] !== null && $user['empresa_cnpj'] === null) {
        return ['ok' => false, 'erro' => 'Sua empresa está inativa ou não encontrada. Contate o suporte.'];
    }

    db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id')
        ->execute(['id' => $user['id']]);

    auth_preencher_sessao($user);

    return ['ok' => true];
}

function auth_logout(): void
{
    auth_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Cadastro público: sem empresa (NULL). Vínculo posterior pelo administrador.
 * Para criar usuário já vinculado, use scripts/criar_usuario.php --empresa=N
 */
function auth_register(string $nome, string $email, string $senha, ?int $empresaId = null): array
{
    auth_garantir_schema();

    $email = mb_strtolower(trim($email));
    $nome = trim($nome);

    if (strlen($senha) < 8) {
        return ['ok' => false, 'erro' => 'A senha deve ter no mínimo 8 caracteres.'];
    }

    if ($empresaId !== null) {
        $empresa = db()->prepare('SELECT id FROM empresas WHERE id = :id AND ativo = TRUE');
        $empresa->execute(['id' => $empresaId]);
        if (!$empresa->fetch()) {
            return ['ok' => false, 'erro' => 'Empresa não encontrada ou inativa.'];
        }
    }

    $exists = db()->prepare('SELECT 1 FROM usuarios WHERE email = :email');
    $exists->execute(['email' => $email]);
    if ($exists->fetch()) {
        return ['ok' => false, 'erro' => 'Este e-mail já está cadastrado.'];
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    db()->prepare(
        'INSERT INTO usuarios (empresa_id, nome, email, senha_hash, perfil) VALUES (:empresa_id, :nome, :email, :hash, :perfil)'
    )->execute([
        'empresa_id' => $empresaId,
        'nome' => $nome,
        'email' => $email,
        'hash' => $hash,
        'perfil' => 'usuario',
    ]);

    return ['ok' => true];
}

function auth_app_is_dev(): bool
{
    $env = getenv('NFE_DEV_MODE');
    if ($env === '1' || strtolower((string) $env) === 'true') {
        return true;
    }

    return !empty(app_web_config()['dev_mode']);
}

function auth_enviar_email_recuperacao(string $destino, string $nome, string $link): bool
{
    $cfg = app_web_config();
    $from = (string) (($cfg['mail_from'] ?? getenv('NFE_MAIL_FROM')) ?: 'noreply@localhost');
    $assunto = 'Redefinição de senha — NFe Monitor';
    $corpo = "Olá, {$nome}\n\n"
        . "Recebemos um pedido para redefinir sua senha no NFe Monitor.\n\n"
        . "Acesse o link abaixo (válido por 2 horas):\n{$link}\n\n"
        . "Se você não solicitou, ignore este e-mail.\n";

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
    ]);

    return @mail($destino, $assunto, $corpo, $headers);
}

function auth_request_password_reset(string $email): array
{
    $email = mb_strtolower(trim($email));
    $stmt = db()->prepare('SELECT id, nome, email FROM usuarios WHERE email = :email AND ativo = TRUE');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    $mensagemPadrao = 'Se o e-mail existir, você receberá instruções para redefinir a senha.';

    if (!$user) {
        return ['ok' => true, 'mensagem' => $mensagemPadrao];
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO password_resets (usuario_id, token, expira_em) VALUES (:uid, :token, NOW() + INTERVAL \'2 hours\')'
    )->execute(['uid' => $user['id'], 'token' => $token]);

    $reset = app_web_config()['redefinir_senha_path'] ?? 'redefinir-senha.php';
    $link = auth_base_url() . url_path($reset . '?token=' . urlencode($token));

    $enviou = auth_enviar_email_recuperacao((string) $user['email'], (string) $user['nome'], $link);

    $result = ['ok' => true, 'mensagem' => $mensagemPadrao];

    if (!$enviou) {
        error_log('NFe Monitor: falha ao enviar e-mail de recuperação para ' . $email);
        if (auth_app_is_dev()) {
            $result['link_dev'] = $link;
            $result['mensagem'] = $mensagemPadrao
                . ' (dev: e-mail não enviado — link disponível apenas neste ambiente.)';
        }
    }

    return $result;
}

function auth_reset_password(string $token, string $senha): array
{
    if (strlen($senha) < 8) {
        return ['ok' => false, 'erro' => 'A senha deve ter no mínimo 8 caracteres.'];
    }

    $stmt = db()->prepare(
        'SELECT pr.id, pr.usuario_id
         FROM password_resets pr
         WHERE pr.token = :token AND pr.usado_em IS NULL AND pr.expira_em > NOW()
         LIMIT 1'
    );
    $stmt->execute(['token' => $token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        return ['ok' => false, 'erro' => 'Link inválido ou expirado. Solicite um novo.'];
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    db()->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id = :id')
        ->execute(['hash' => $hash, 'id' => $reset['usuario_id']]);
    db()->prepare('UPDATE password_resets SET usado_em = NOW() WHERE id = :id')
        ->execute(['id' => $reset['id']]);

    return ['ok' => true];
}

function auth_login_url(): string
{
    return url_path(app_web_config()['login_path'] ?? 'login.php');
}

function auth_cadastro_url(): string
{
    return url_path(app_web_config()['cadastro_path'] ?? 'cadastro.php');
}

/** Link WhatsApp para suporte da plataforma (configure em app.config.php na VPS). */
function auth_suporte_url(): string
{
    $cfg = app_web_config();
    if (!empty($cfg['suporte_whatsapp_url'])) {
        return (string) $cfg['suporte_whatsapp_url'];
    }
    $env = getenv('NFE_SUPORTE_WHATSAPP_URL');
    if ($env !== false && $env !== '') {
        return $env;
    }

    return 'https://wa.me/5500000000000?text=' . rawurlencode('Olá, preciso de ajuda na Plataforma NFe Monitor');
}

function auth_logout_url(): string
{
    return url_path(app_web_config()['logout_path'] ?? 'logout.php');
}

function auth_dashboard_url(): string
{
    $home = app_web_config()['dashboard_path'] ?? 'nfe/index.php';
    return url_path($home);
}

function auth_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function auth_layout(string $title, string $content, array $opts = []): void
{
    $subtitle = $opts['subtitle'] ?? 'Acesso seguro ao monitor de NF-e';
    $showBrand = $opts['brand'] ?? true;
    ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · NFe Monitor</title>
    <link rel="icon" href="<?= htmlspecialchars(url_path('assets/img/emblema.png')) ?>" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(url_path('assets/css/auth.css') . '?v=3') ?>">
</head>
<body class="auth-page" data-auth-page>
    <div class="auth-bg" aria-hidden="true"></div>
    <div class="auth-shell">
        <?php if ($showBrand): ?>
        <aside class="auth-brand">
            <div class="auth-brand-glow" aria-hidden="true"></div>
            <div class="auth-logo-wrap">
                <img class="auth-logo"
                     src="<?= htmlspecialchars(url_path('assets/img/logo-removebg.png') . '?v=3') ?>"
                     alt="NF Monitor — Monitoramento Inteligente de NF-e">
            </div>
            <p class="auth-brand-lead"><?= htmlspecialchars($subtitle) ?></p>
            <ul class="auth-features">
                <li>Consulta automática na SEFAZ a cada 3 horas</li>
                <li>Isolamento total de dados por empresa</li>
                <li>Download de XML e painel em tempo real</li>
            </ul>
        </aside>
        <?php endif; ?>
        <main class="auth-main<?= $showBrand ? '' : ' auth-main--solo' ?>">
            <?= $content ?>
        </main>
    </div>
    <script src="<?= htmlspecialchars(url_path('assets/js/auth.js')) ?>" defer></script>
</body>
</html>
    <?php
}
