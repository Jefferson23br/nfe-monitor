<?php

/**
 * Copie este arquivo para config.local.php e preencha com seus dados.
 * O arquivo config.local.php não deve ser enviado ao Git.
 */
return [
    'atualizacao' => date('Y-m-d H:i:s'),
    'tpAmb' => 2, // 1 = Produção | 2 = Homologação
    'razaosocial' => 'SUA EMPRESA LTDA',
    'cnpj' => '00000000000000',
    'siglaUF' => 'SP',
    'schemes' => 'PL_009_V4',
    'versao' => '4.00',
    'pfx_path' => __DIR__ . '/certs/seu_certificado.pfx',
    'pfx_password' => getenv('NFE_PFX_PASSWORD') ?: 'SENHA_DO_CERTIFICADO',
];
