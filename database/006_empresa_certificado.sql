-- Certificado e senha por empresa (multi-tenant)
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS certificado_senha TEXT;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS criado_por_usuario_id INTEGER REFERENCES usuarios(id);

CREATE INDEX IF NOT EXISTS idx_empresas_cnpj ON empresas (cnpj);
