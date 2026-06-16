#!/bin/bash
# Rode na VPS: bash /root/nfe-monitor/scripts/verificar-deploy.sh

ROOT="/root/nfe-monitor"
NFE="$ROOT/Frontend/nfe"

echo "=== Nginx root (nfe3) ==="
grep -E "root |server_name" /etc/nginx/sites-enabled/*nfe3* 2>/dev/null || grep -E "root |server_name" /etc/nginx/sites-available/nfe3* 2>/dev/null

echo ""
echo "=== Arquivos obrigatórios ==="
for f in login.php index.php assets/css/auth.css includes/bootstrap.php app.config.php; do
  if [ -f "$ROOT/$f" ] || [ -f "$NFE/$f" ]; then
    echo "OK  $f"
  else
    echo "FALTA  $f"
  fi
done

echo ""
echo "=== Pasta nfe ==="
ls -la "$NFE/" 2>/dev/null | head -20

echo ""
echo "=== PHP login (primeiras linhas) ==="
head -8 "$NFE/login.php" 2>/dev/null || echo "login.php NAO EXISTE"

echo ""
echo "=== Usuarios no banco ==="
sudo -u postgres psql -d nfe_monitor -t -c "SELECT count(*) FROM usuarios;" 2>/dev/null || echo "Erro ao consultar usuarios"
