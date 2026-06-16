-- Coluna de data/hora nos logs do robô (histórico no painel)
ALTER TABLE registros_robot
    ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP NOT NULL DEFAULT NOW();

UPDATE registros_robot SET criado_em = NOW() WHERE criado_em IS NULL;
