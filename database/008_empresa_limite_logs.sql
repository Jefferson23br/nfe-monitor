-- Limite total de registros em registros_robot por empresa (consultas NSU)
-- Rodar: sudo -u postgres psql -d nfe_monitor -f database/008_empresa_limite_logs.sql

ALTER TABLE empresas
    ADD COLUMN IF NOT EXISTS limite_logs INTEGER;

COMMENT ON COLUMN empresas.limite_logs IS
    'Máximo de linhas em registros_robot. NULL = ilimitado (indeterminado).';
