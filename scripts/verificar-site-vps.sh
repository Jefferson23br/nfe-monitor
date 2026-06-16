#!/bin/bash
set -e
ROOT="${NFE_PROJECT_ROOT:-/root/nfe-monitor}"
WEB="$ROOT/Frontend/nfe"
PUBLIC_URL="${NFE_PUBLIC_URL:-https://nfe.seudominio.com.br}"

echo "=== Arquivos ==="
for f in index.php login.php cadastro.php painel.php ping.php; do
    [ -f "$WEB/$f" ] && echo "OK $f" || echo "FALTA $f"
done

echo ""
echo "=== HTTP $PUBLIC_URL ==="
curl -sk -o /dev/null -w "ping: %{http_code}\n" "${PUBLIC_URL}/ping.php"
curl -sk -o /dev/null -w "login: %{http_code}\n" "${PUBLIC_URL}/login.php"
