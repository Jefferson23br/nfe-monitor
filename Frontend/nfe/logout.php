<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_logout();
header('Location: ' . auth_login_url());
exit;
