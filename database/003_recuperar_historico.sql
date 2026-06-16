-- Recupera histórico de consultas NSU vinculado à empresa padrão (id = 1)
-- Rodar: sudo -u postgres psql -d nfe_monitor -f /root/nfe-monitor/database/003_recuperar_historico.sql

BEGIN;

UPDATE registros_robot SET empresa_id = 1 WHERE empresa_id IS NULL;

UPDATE config_monitor SET empresa_id = 1 WHERE empresa_id IS NULL;

UPDATE notas_fiscais SET empresa_id = 1 WHERE empresa_id IS NULL;

INSERT INTO config_monitor (empresa_id, campo, valor)
SELECT 1, 'ultimo_nsu', COALESCE(
    (SELECT valor FROM config_monitor WHERE empresa_id = 1 AND campo = 'ultimo_nsu' LIMIT 1),
    (SELECT valor FROM config_monitor WHERE campo = 'ultimo_nsu' AND empresa_id IS NULL LIMIT 1),
    '0'
)
ON CONFLICT (empresa_id, campo) DO NOTHING;

COMMIT;

-- Conferência:
-- SELECT count(*) FROM registros_robot WHERE empresa_id = 1;
-- SELECT empresa_id, campo, valor FROM config_monitor;
