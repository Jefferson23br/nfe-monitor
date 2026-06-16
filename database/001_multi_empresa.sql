-- Migração: multi-empresa + usuários
-- Executar na VPS: sudo -u postgres psql -d nfe_monitor -f /root/nfe-monitor/database/001_multi_empresa.sql
-- Faça backup antes: pg_dump -d nfe_monitor > backup_antes_migracao.sql

BEGIN;

-- ---------------------------------------------------------------------------
-- Empresa titular do certificado (destinatário DistDFe)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS empresas (
    id              SERIAL PRIMARY KEY,
    razao_social    VARCHAR(255) NOT NULL,
    nome_fantasia   VARCHAR(255),
    cnpj            VARCHAR(14) NOT NULL UNIQUE,
    uf              CHAR(2),
    certificado_path TEXT,
    ativo           BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em       TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Seed opcional com CNPJ fictício (apenas dev/demo). Em produção, use o cadastro no painel.
INSERT INTO empresas (razao_social, nome_fantasia, cnpj, uf, certificado_path)
VALUES (
    'EMPRESA DEMONSTRACAO LTDA',
    'Demonstração',
    '11222333000181',
    'SP',
    '/var/lib/nfe-monitor/certs/exemplo.pfx'
)
ON CONFLICT (cnpj) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Usuários e recuperação de senha
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id              SERIAL PRIMARY KEY,
    empresa_id      INTEGER NOT NULL REFERENCES empresas(id) ON DELETE RESTRICT,
    nome            VARCHAR(150) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    senha_hash      VARCHAR(255) NOT NULL,
    perfil          VARCHAR(30) NOT NULL DEFAULT 'usuario',
    ativo           BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em       TIMESTAMP NOT NULL DEFAULT NOW(),
    ultimo_login    TIMESTAMP,
    CONSTRAINT usuarios_perfil_check CHECK (perfil IN ('admin', 'usuario'))
);

CREATE TABLE IF NOT EXISTS password_resets (
    id              SERIAL PRIMARY KEY,
    usuario_id      INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    token           VARCHAR(64) NOT NULL UNIQUE,
    expira_em       TIMESTAMP NOT NULL,
    usado_em        TIMESTAMP,
    criado_em       TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token);
CREATE INDEX IF NOT EXISTS idx_usuarios_empresa ON usuarios(empresa_id);

-- ---------------------------------------------------------------------------
-- Vincular dados existentes à primeira empresa (id = 1)
-- ---------------------------------------------------------------------------
ALTER TABLE notas_fiscais
    ADD COLUMN IF NOT EXISTS empresa_id INTEGER REFERENCES empresas(id);

UPDATE notas_fiscais SET empresa_id = 1 WHERE empresa_id IS NULL;

ALTER TABLE notas_fiscais ALTER COLUMN empresa_id SET NOT NULL;

ALTER TABLE notas_fiscais DROP CONSTRAINT IF EXISTS notas_fiscais_chnfe_key;
DROP INDEX IF EXISTS notas_fiscais_empresa_chave;
CREATE UNIQUE INDEX notas_fiscais_empresa_chave ON notas_fiscais (empresa_id, chnfe);

CREATE INDEX IF NOT EXISTS idx_notas_empresa ON notas_fiscais (empresa_id);

-- ---------------------------------------------------------------------------
ALTER TABLE registros_robot
    ADD COLUMN IF NOT EXISTS empresa_id INTEGER REFERENCES empresas(id);

UPDATE registros_robot SET empresa_id = 1 WHERE empresa_id IS NULL;

ALTER TABLE registros_robot ALTER COLUMN empresa_id SET NOT NULL;

CREATE INDEX IF NOT EXISTS idx_registros_empresa ON registros_robot (empresa_id);

-- ---------------------------------------------------------------------------
ALTER TABLE config_monitor
    ADD COLUMN IF NOT EXISTS empresa_id INTEGER REFERENCES empresas(id);

UPDATE config_monitor SET empresa_id = 1 WHERE empresa_id IS NULL;

ALTER TABLE config_monitor ALTER COLUMN empresa_id SET NOT NULL;

ALTER TABLE config_monitor DROP CONSTRAINT IF EXISTS config_monitor_pkey;
ALTER TABLE config_monitor ADD PRIMARY KEY (empresa_id, campo);

COMMIT;

-- Conferência (rodar após COMMIT)
-- SELECT 'empresas', count(*) FROM empresas;
-- SELECT 'notas sem empresa', count(*) FROM notas_fiscais WHERE empresa_id IS NULL;
-- SELECT empresa_id, campo, valor FROM config_monitor;
