<?php

declare(strict_types=1);

function db_config(): array
{
    $local = dirname(__DIR__) . '/database.local.php';
    if (is_file($local)) {
        return require $local;
    }

    $pass = getenv('DB_PASS');
    if ($pass === false || $pass === '') {
        throw new RuntimeException(
            'Configure o banco: copie database.local.php.example para database.local.php ou defina DB_PASS no ambiente.'
        );
    }

    return [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'dbname' => getenv('DB_NAME') ?: 'nfe_monitor',
        'user' => getenv('DB_USER') ?: 'postgres',
        'pass' => $pass,
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = db_config();
    $pdo = new PDO(
        sprintf('pgsql:host=%s;dbname=%s', $c['host'], $c['dbname']),
        $c['user'],
        $c['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");

    return $pdo;
}

function empresa_por_cnpj(string $cnpj): ?array
{
    $stmt = db()->prepare('SELECT * FROM empresas WHERE cnpj = :cnpj AND ativo = TRUE LIMIT 1');
    $stmt->execute(['cnpj' => preg_replace('/\D/', '', $cnpj)]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function empresa_id_do_config(): int
{
    $config = require dirname(__DIR__) . '/config.php';
    $empresa = empresa_por_cnpj((string) $config['cnpj']);
    if (!$empresa) {
        throw new RuntimeException('Empresa do config.php não cadastrada. Execute database/001_multi_empresa_corrigido.sql');
    }

    return (int) $empresa['id'];
}
