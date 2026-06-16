<?php

declare(strict_types=1);

function app_web_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $local = dirname(__DIR__) . '/app.config.php';
    if (is_file($local)) {
        $config = require $local;
        return $config;
    }

    $config = ['base_path' => ''];
    return $config;
}

/** Caminho absoluto no site, ex.: /auth/login.php */
function url_path(string $path): string
{
    $base = rtrim((string) (app_web_config()['base_path'] ?? ''), '/');
    $path = '/' . ltrim($path, '/');
    return $base === '' ? $path : $base . $path;
}

/** URL de imagem servida por asset.php (funciona mesmo se Nginx não servir /assets/img/). */
function asset_url(string $file): string
{
    return url_path('asset.php?f=' . rawurlencode($file));
}

function danfe_visualizador_url(): string
{
    return (string) (app_web_config()['danfe_visualizador_url']
        ?? 'https://www.nfe.fazenda.gov.br/portal/consultaRecaptcha.aspx');
}
