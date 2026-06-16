<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_start();

$mensagem = '';
$linkDev = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = auth_request_password_reset($_POST['email'] ?? '');
    $mensagem = $result['mensagem'];
    if (auth_app_is_dev()) {
        $linkDev = $result['link_dev'] ?? '';
    }
}

ob_start();
?>
<div class="auth-card">
    <h2>Esqueceu a senha?</h2>
    <p class="auth-lead">Informe seu e-mail para receber o link de redefinição (válido por 2 horas).</p>
    <?php if ($mensagem): ?>
        <div class="auth-alert auth-alert--success"><?= htmlspecialchars($mensagem) ?></div>
        <?php if ($linkDev !== ''): ?>
            <div class="auth-alert auth-alert--info">
                <strong>Ambiente de desenvolvimento:</strong>
                <a href="<?= htmlspecialchars($linkDev) ?>">abrir link de redefinição</a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <form method="post">
            <div class="auth-field">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required placeholder="voce@empresa.com.br">
            </div>
            <button type="submit" class="auth-btn">Enviar link</button>
        </form>
    <?php endif; ?>
    <p class="auth-links"><a href="login.php">Voltar ao login</a></p>
</div>
<?php
$content = ob_get_clean();
auth_layout('Recuperar senha', $content);
