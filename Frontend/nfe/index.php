<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_start();

if (auth_user()) {
    auth_redirect_apos_login();
} else {
    header('Location: ' . auth_login_url());
}
exit;
