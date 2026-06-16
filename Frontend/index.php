<?php

declare(strict_types=1);

/** Legado: document root de produção é Frontend/nfe (ver deploy/nginx-*.conf). */
header('Location: nfe/login.php', true, 302);
exit;
