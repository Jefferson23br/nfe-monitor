#!/bin/bash
# Aplica migração + verifica arquivos de multi-empresa na VPS
set -e
cd /root/nfe-monitor

echo "=== SQL 006 ==="
sudo -u postgres psql -d nfe_monitor <<'SQL'
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS certificado_senha TEXT;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS criado_por_usuario_id INTEGER REFERENCES usuarios(id);
CREATE INDEX IF NOT EXISTS idx_empresas_cnpj ON empresas (cnpj);
SQL

echo ""
echo "=== Arquivos ==="
for f in includes/empresa.php includes/bootstrap.php includes/monitor_helpers.php monitor.php Frontend/nfe/cadastrar-empresa.php; do
    test -f "$f" && echo "OK $f" || echo "FALTA $f — envie do PC"
done

echo ""
echo "=== Teste robô empresa 1 ==="
/usr/bin/php8.3 monitor.php --empresa=1 --force 2>&1 | head -6
