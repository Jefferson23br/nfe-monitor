<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

/** Raiz do projeto (pasta nfe-monitor) */
define('NFE_PROJECT_ROOT', dirname(__DIR__));

require_once NFE_PROJECT_ROOT . '/includes/database.php';
require_once NFE_PROJECT_ROOT . '/includes/web.php';
require_once NFE_PROJECT_ROOT . '/includes/auth.php';
require_once NFE_PROJECT_ROOT . '/includes/empresa.php';

$monitorHelpers = NFE_PROJECT_ROOT . '/includes/monitor_helpers.php';
if (is_file($monitorHelpers)) {
    require_once $monitorHelpers;
}

/** Fallback se monitor_helpers.php não foi enviado à VPS */
if (!function_exists('monitor_nsu_pad')) {
    function monitor_nsu_pad(int|string $nsu): string
    {
        return str_pad((string) (int) $nsu, 15, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('monitor_executar')) {
    function monitor_executar(int $empresaId, bool $force = false): string
    {
        $root = NFE_PROJECT_ROOT;
        $autoload = $root . '/vendor/autoload.php';
        $monitor = $root . '/monitor.php';

        if (!is_file($autoload)) {
            return "ERRO: dependências não instaladas.\nNa VPS: cd {$root} && composer install --no-dev\n";
        }
        if (!is_file($monitor)) {
            return "ERRO: monitor.php não encontrado.";
        }

        $php = function_exists('monitor_php_cli') ? monitor_php_cli() : '/usr/bin/php8.3';
        $forceArg = $force ? ' --force' : '';
        $opensslPrefix = function_exists('monitor_shell_openssl_prefix')
            ? monitor_shell_openssl_prefix()
            : '';
        $cmd = 'cd ' . escapeshellarg($root) . ' && ' . $opensslPrefix . escapeshellarg($php) . ' '
            . escapeshellarg($monitor) . ' --empresa=' . (int) $empresaId . $forceArg . ' 2>&1';

        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $linhas = [];
            exec($cmd, $linhas);
            if ($linhas !== []) {
                return implode("\n", $linhas);
            }
        }

        if (function_exists('shell_exec')) {
            $saida = shell_exec($cmd);
            if ($saida !== null && $saida !== '') {
                return $saida;
            }
        }

        return 'ERRO: não foi possível executar o robô. Na VPS: php ' . $monitor . ' --empresa=' . (int) $empresaId;
    }
}

if (!function_exists('monitor_logs_listar')) {
    function monitor_logs_listar(int $empresaId, int $limite = 100): array
    {
        $pdo = db();
        $limite = max(1, min(500, $limite));

        $pdo->exec('UPDATE registros_robot SET empresa_id = ' . (int) $empresaId
            . ' WHERE empresa_id IS NULL');

        $sql = 'SELECT * FROM registros_robot WHERE empresa_id = :e ORDER BY id DESC LIMIT ' . $limite;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['e' => $empresaId]);

        return $stmt->fetchAll() ?: [];
    }
}

if (!function_exists('monitor_log_data_hora')) {
    function monitor_log_data_hora(array $log): string
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        foreach (['criado_em', 'data_hora', 'created_at'] as $col) {
            if (!isset($log[$col]) || $log[$col] === '' || $log[$col] === null) {
                continue;
            }
            try {
                $v = $log[$col];
                $dt = $v instanceof DateTimeInterface
                    ? DateTimeImmutable::createFromInterface($v)->setTimezone($tz)
                    : new DateTimeImmutable((string) $v, $tz);
                return $dt->format('d/m/Y H:i');
            } catch (Exception) {
                continue;
            }
        }
        return '—';
    }
}
