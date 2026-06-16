<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

auth_require_empresa();

$empresaId = auth_empresa_id();
if ($empresaId === null) {
    http_response_code(403);
    exit('Acesso negado.');
}

$chave = isset($_GET['chave']) ? preg_replace('/\D/', '', (string) $_GET['chave']) : '';
if (strlen($chave) !== 44) {
    http_response_code(400);
    exit('Chave inválida.');
}

$stmt = db()->prepare(
    'SELECT 1 FROM notas_fiscais WHERE empresa_id = :e AND chnfe = :c LIMIT 1'
);
$stmt->execute(['e' => $empresaId, 'c' => $chave]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Nota não encontrada para sua empresa.');
}

$resultado = monitor_baixar_xml($empresaId, $chave);

if (!($resultado['ok'] ?? false)) {
    http_response_code((int) ($resultado['http_code'] ?? 500));
    exit((string) ($resultado['erro'] ?? 'Erro ao baixar XML.'));
}

$xmlCompleto = (string) ($resultado['xml'] ?? '');
$arquivo = 'NFe-' . $chave . '.xml';
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Content-Length: ' . strlen($xmlCompleto));
echo $xmlCompleto;
