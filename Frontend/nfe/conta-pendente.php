<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_require();

if (auth_tem_empresa()) {
    header('Location: ' . auth_dashboard_url());
    exit;
}

header('Location: ' . url_path('cadastrar-empresa.php'), true, 302);
exit;
