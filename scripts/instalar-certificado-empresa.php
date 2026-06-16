<?php

declare(strict_types=1);

/**
 * Instala certificado .pfx de uma empresa via CLI (quando o upload pelo painel falhou).
 *
 * Uso na VPS:
 *   php8.3 scripts/instalar-certificado-empresa.php \
 *     --empresa=3 \
 *     --arquivo=/tmp/exemplo_certificado.pfx \
 *     --senha="SENHA_DO_CERTIFICADO"
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Execute apenas via linha de comando.\n");
    exit(1);
}

require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/empresa.php';

empresa_openssl_legacy_env();

$opts = getopt('', ['empresa:', 'arquivo:', 'senha:']);
$empresaId = (int) ($opts['empresa'] ?? 0);
$arquivo = $opts['arquivo'] ?? '';
$senha = $opts['senha'] ?? '';

if ($empresaId <= 0 || $arquivo === '' || $senha === '') {
    fwrite(STDERR, "Uso: php scripts/instalar-certificado-empresa.php --empresa=ID --arquivo=/caminho/arquivo.pfx --senha=\"...\"\n");
    exit(1);
}

if (!is_file($arquivo)) {
    fwrite(STDERR, "Arquivo não encontrado: $arquivo\n");
    exit(1);
}

$stmt = db()->prepare('SELECT * FROM empresas WHERE id = :id AND ativo = TRUE');
$stmt->execute(['id' => $empresaId]);
$empresa = $stmt->fetch();
if (!$empresa) {
    fwrite(STDERR, "Empresa id=$empresaId não encontrada.\n");
    exit(1);
}

$erroKey = empresa_exigir_app_key();
if ($erroKey !== null) {
    fwrite(STDERR, $erroKey . "\n");
    exit(1);
}

$erroCert = empresa_validar_certificado_pfx($arquivo, $senha);
if ($erroCert !== null) {
    fwrite(STDERR, $erroCert . "\n");
    exit(1);
}

$cnpj = empresa_normalizar_cnpj((string) $empresa['cnpj']);
$destino = empresa_certs_dir() . '/' . $cnpj . '.pfx';

if (!copy($arquivo, $destino)) {
    fwrite(STDERR, "Falha ao copiar para $destino\n");
    exit(1);
}
chmod($destino, 0644);

$senhaEnc = empresa_criptografar_senha_cert($senha);
db()->prepare(
    'UPDATE empresas SET certificado_path = :p, certificado_senha = :s WHERE id = :id'
)->execute(['p' => $destino, 's' => $senhaEnc, 'id' => $empresaId]);

echo "OK Certificado instalado para empresa id=$empresaId (CNPJ $cnpj)\n";
echo "Caminho: $destino\n";
