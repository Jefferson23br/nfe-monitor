<?php

declare(strict_types=1);

/**
 * Cria usuário via CLI na VPS (após rodar a migração SQL).
 *
 * Exemplo:
 * php scripts/criar_usuario.php --email=admin@empresa.com.br --nome="Administrador" --senha="SuaSenhaForte123" --perfil=admin
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Execute apenas via linha de comando.\n");
    exit(1);
}

require dirname(__DIR__) . '/includes/database.php';

$opts = getopt('', ['email:', 'nome:', 'senha:', 'perfil::', 'empresa::']);

$email = $opts['email'] ?? '';
$nome = $opts['nome'] ?? '';
$senha = $opts['senha'] ?? '';
$perfil = $opts['perfil'] ?? 'admin';
$empresaId = (int) ($opts['empresa'] ?? 1);

if ($email === '' || $nome === '' || $senha === '') {
    fwrite(STDERR, "Uso: php scripts/criar_usuario.php --email=... --nome=\"...\" --senha=\"...\" [--perfil=admin|usuario] [--empresa=1]\n");
    exit(1);
}

if (!in_array($perfil, ['admin', 'usuario'], true)) {
    fwrite(STDERR, "Perfil inválido. Use admin ou usuario.\n");
    exit(1);
}

if (strlen($senha) < 8) {
    fwrite(STDERR, "Senha deve ter no mínimo 8 caracteres.\n");
    exit(1);
}

try {
    $pdo = db();
    $empresa = $pdo->prepare('SELECT id, razao_social FROM empresas WHERE id = :id');
    $empresa->execute(['id' => $empresaId]);
    $emp = $empresa->fetch();
    if (!$emp) {
        fwrite(STDERR, "Empresa id=$empresaId não encontrada. Rode database/001_multi_empresa.sql primeiro.\n");
        exit(1);
    }

    $email = mb_strtolower(trim($email));
    $exists = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = :email');
    $exists->execute(['email' => $email]);
    if ($exists->fetch()) {
        fwrite(STDERR, "E-mail já cadastrado.\n");
        exit(1);
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO usuarios (empresa_id, nome, email, senha_hash, perfil) VALUES (:e, :n, :em, :h, :p)'
    )->execute([
        'e' => $empresaId,
        'n' => trim($nome),
        'em' => $email,
        'h' => $hash,
        'p' => $perfil,
    ]);

    echo "OK Usuário criado: $email ({$emp['razao_social']}, perfil=$perfil)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
