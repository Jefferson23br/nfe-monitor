-- Validade da licença por empresa (controle comercial / acesso ao monitor)
-- Rodar: sudo -u postgres psql -d nfe_monitor -f database/007_empresa_validade_licenca.sql

ALTER TABLE empresas
    ADD COLUMN IF NOT EXISTS validade_licenca DATE;

COMMENT ON COLUMN empresas.validade_licenca IS
    'Data limite da licença (inclusive). NULL = indeterminado (sem bloqueio por data).';

CREATE INDEX IF NOT EXISTS idx_empresas_validade_licenca
    ON empresas (validade_licenca)
    WHERE validade_licenca IS NOT NULL;

ALTER TABLE empresas
    ADD COLUMN IF NOT EXISTS limite_logs INTEGER;

COMMENT ON COLUMN empresas.limite_logs IS
    'Máximo de linhas em registros_robot. NULL = indeterminado (ilimitado).';
