#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vincula usuário (cadastro sem empresa) a uma empresa.
 * Exemplo:
 *   php scripts/vincular_usuario_empresa.php --email=joao@email.com --empresa=1
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Execute apenas via CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/auth.php';

auth_garantir_schema();

$opts = getopt('', ['email:', 'empresa:']);
$email = mb_strtolower(trim($opts['email'] ?? ''));
$empresaId = (int) ($opts['empresa'] ?? 0);

if ($email === '' || $empresaId < 1) {
    fwrite(STDERR, "Uso: php scripts/vincular_usuario_empresa.php --email=... --empresa=ID\n");
    exit(1);
}

$pdo = db();
$emp = $pdo->prepare('SELECT id, razao_social FROM empresas WHERE id = :id AND ativo = TRUE');
$emp->execute(['id' => $empresaId]);
if (!$emp->fetch()) {
    fwrite(STDERR, "Empresa $empresaId não encontrada.\n");
    exit(1);
}

$stmt = $pdo->prepare('UPDATE usuarios SET empresa_id = :e WHERE email = :em RETURNING id, nome');
$stmt->execute(['e' => $empresaId, 'em' => $email]);
$row = $stmt->fetch();

if (!$row) {
    fwrite(STDERR, "Usuário não encontrado: $email\n");
    exit(1);
}

echo "OK: {$row['nome']} (id={$row['id']}) vinculado à empresa $empresaId\n";
