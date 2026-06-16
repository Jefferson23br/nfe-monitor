-- Garante registro de NSU para cada empresa (não apaga dados existentes)
INSERT INTO config_monitor (empresa_id, campo, valor)
SELECT id, 'ultimo_nsu', COALESCE(
    (SELECT valor FROM config_monitor cm WHERE cm.empresa_id = empresas.id AND cm.campo = 'ultimo_nsu'),
    '0'
)
FROM empresas
ON CONFLICT (empresa_id, campo) DO NOTHING;

-- Nova empresa no futuro: ao inserir em empresas, rodar:
-- INSERT INTO config_monitor (empresa_id, campo, valor) VALUES (NOVO_ID, 'ultimo_nsu', '0');
