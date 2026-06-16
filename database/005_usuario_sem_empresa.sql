-- Usuário pode existir sem empresa (cadastro público); vínculo feito depois pelo admin
ALTER TABLE usuarios ALTER COLUMN empresa_id DROP NOT NULL;
