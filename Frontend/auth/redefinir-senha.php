<?php

declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
$destino = '../nfe/redefinir-senha.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $destino, true, 302);
exit;
