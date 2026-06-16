<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_require();

if (auth_tem_empresa()) {
    header('Location: ' . auth_dashboard_url());
    exit;
}

$user = auth_user();
$erro = '';
$sucesso = false;
$empresaIdCriada = null;

$ufs = [
    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
    'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = empresa_cadastrar_para_usuario((int) $user['id'], [
        'razao_social' => $_POST['razao_social'] ?? '',
        'nome_fantasia' => $_POST['nome_fantasia'] ?? '',
        'cnpj' => $_POST['cnpj'] ?? '',
        'uf' => $_POST['uf'] ?? '',
        'senha_cert' => $_POST['senha_cert'] ?? '',
    ], $_FILES['certificado'] ?? []);

    if ($result['ok']) {
        auth_recarregar_sessao();
        $sucesso = true;
        $empresaIdCriada = $result['empresa_id'];
        if ($empresaIdCriada !== null) {
            $sync = monitor_sincronizar_nsu_inicial((int) $empresaIdCriada);
        }
    } else {
        $erro = $result['erro'] ?? 'Erro ao cadastrar.';
    }
}

ob_start();
?>
<div class="auth-card auth-card--wide">
    <h2>Cadastrar empresa</h2>
    <p class="auth-lead">
        Cada empresa usa seu próprio <strong>certificado digital</strong> e consulta NSU isolada.
        Nenhum dado é compartilhado entre empresas.
    </p>

    <?php if ($sucesso): ?>
        <div class="auth-alert auth-alert--success">
            <strong>Empresa cadastrada com sucesso.</strong><br>
            ID no sistema: <strong><?= (int) $empresaIdCriada ?></strong><br>
            O certificado foi salvo e as consultas SEFAZ usarão este CNPJ.
            <?php if (isset($sync) && ($sync['ok'] ?? false) && !empty($sync['nsu'])): ?>
            <br>NSU inicial sincronizado: <strong><?= htmlspecialchars((string) $sync['nsu']) ?></strong>
            <?php elseif (isset($sync) && !($sync['ok'] ?? true)): ?>
            <br><span class="text-warning">Aviso NSU: <?= htmlspecialchars((string) ($sync['erro'] ?? 'falha na sincronização')) ?></span>
            <?php endif; ?>
        </div>
        <a href="<?= htmlspecialchars(auth_dashboard_url()) ?>" class="auth-btn">Ir para o painel</a>
    <?php else: ?>
        <?php if ($erro): ?>
            <div class="auth-alert auth-alert--error" role="alert"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="auth-form-grid">
            <div class="auth-field auth-field--full">
                <label for="razao_social">Nome / Razão social</label>
                <input type="text" id="razao_social" name="razao_social" required
                       value="<?= htmlspecialchars($_POST['razao_social'] ?? '') ?>">
            </div>
            <div class="auth-field auth-field--full">
                <label for="nome_fantasia">Nome fantasia <span class="text-muted">(opcional)</span></label>
                <input type="text" id="nome_fantasia" name="nome_fantasia"
                       value="<?= htmlspecialchars($_POST['nome_fantasia'] ?? '') ?>">
            </div>
            <div class="auth-field">
                <label for="cnpj">CNPJ</label>
                <input type="text" id="cnpj" name="cnpj" required maxlength="18" placeholder="00.000.000/0000-00"
                       value="<?= htmlspecialchars($_POST['cnpj'] ?? '') ?>">
            </div>
            <div class="auth-field">
                <label for="uf">UF</label>
                <select id="uf" name="uf" required>
                    <option value="">Selecione</option>
                    <?php foreach ($ufs as $uf): ?>
                    <option value="<?= $uf ?>" <?= ($_POST['uf'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="auth-field auth-field--full">
                <label for="certificado">Certificado digital (.pfx)</label>
                <input type="file" id="certificado" name="certificado" accept=".pfx,.p12" required
                       class="auth-file-input">
            </div>
            <div class="auth-field auth-field--full">
                <label for="senha_cert">Senha do certificado</label>
                <div class="auth-password-wrap">
                    <input type="password" id="senha_cert" name="senha_cert" required autocomplete="off">
                    <button type="button" class="auth-password-toggle" data-toggle-password="senha_cert">Mostrar</button>
                </div>
            </div>
            <div class="auth-field auth-field--full">
                <button type="submit" class="auth-btn">Cadastrar empresa e liberar painel</button>
            </div>
        </form>
    <?php endif; ?>

    <p class="auth-links">
        <a href="<?= htmlspecialchars(auth_logout_url()) ?>">Sair</a>
    </p>
</div>
<?php
$content = ob_get_clean();
auth_layout('Cadastrar empresa', $content, ['subtitle' => 'Certificado e dados isolados por empresa']);
