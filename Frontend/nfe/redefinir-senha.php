<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_start();

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$erro = '';
$sucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token) {
    $senha = $_POST['senha'] ?? '';
    $senha2 = $_POST['senha2'] ?? '';
    if ($senha !== $senha2) {
        $erro = 'As senhas não coincidem.';
    } else {
        $result = auth_reset_password($token, $senha);
        $sucesso = $result['ok'];
        $erro = $sucesso ? '' : $result['erro'];
    }
}

ob_start();
?>
<div class="auth-card">
    <h2>Nova senha</h2>
    <p class="auth-lead">Defina uma nova senha para sua conta.</p>
    <?php if ($sucesso): ?>
        <div class="auth-alert auth-alert--success">
            Senha alterada. <a href="login.php" class="auth-inline-link">Fazer login</a>
        </div>
    <?php elseif (!$token): ?>
        <div class="auth-alert auth-alert--error">
            Link inválido. <a href="esqueci-senha.php" class="auth-inline-link">Solicitar novo</a>
        </div>
    <?php else: ?>
        <?php if ($erro): ?>
            <div class="auth-alert auth-alert--error"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        <form method="post" data-confirm-password>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="auth-field">
                <label for="senha">Nova senha</label>
                <div class="auth-password-wrap">
                    <input type="password" id="senha" name="senha" required minlength="8">
                    <button type="button" class="auth-password-toggle" data-toggle-password="senha">Mostrar</button>
                </div>
            </div>
            <div class="auth-field">
                <label for="senha2">Confirmar</label>
                <div class="auth-password-wrap">
                    <input type="password" id="senha2" name="senha2" required minlength="8">
                    <button type="button" class="auth-password-toggle" data-toggle-password="senha2">Mostrar</button>
                </div>
            </div>
            <button type="submit" class="auth-btn">Salvar</button>
        </form>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
auth_layout('Nova senha', $content, ['brand' => false]);
