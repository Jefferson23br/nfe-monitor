#!/bin/bash
# Corrige 404 — rodar na VPS como root: bash /root/nfe-monitor/scripts/restaurar-site-vps.sh
set -e

ROOT="${NFE_PROJECT_ROOT:-/root/nfe-monitor}"
WEB="$ROOT/Frontend/nfe"
NGINX_SITE="${NFE_NGINX_SITE:-nfe-monitor}"
NGINX_AVAIL="/etc/nginx/sites-available/${NGINX_SITE}"
NGINX_ENABLED="/etc/nginx/sites-enabled/${NGINX_SITE}"
PUBLIC_URL="${NFE_PUBLIC_URL:-https://nfe.seudominio.com.br}"

echo "========== 1. Pastas =========="
mkdir -p "$WEB" "$ROOT/includes" "$ROOT/certs" "$ROOT/database" /var/lib/nfe-monitor/certs /var/lib/nfe-monitor/status
chown www-data:www-data /var/lib/nfe-monitor/certs /var/lib/nfe-monitor/status 2>/dev/null || true
chmod 750 /var/lib/nfe-monitor/certs 2>/dev/null || true
chmod 775 /var/lib/nfe-monitor/status 2>/dev/null || true
chmod 711 /root
chmod -R a+rX "$ROOT"
find "$ROOT" -type d -exec chmod a+rx {} \;
find "$ROOT" -type f -exec chmod a+r {} \;

echo ""
echo "========== 2. Arquivos do site =========="
FALTANDO=0
for f in index.php login.php cadastro.php cadastrar-empresa.php painel.php ping.php; do
    if [ -f "$WEB/$f" ]; then echo "  OK  $f"; else echo "  FALTA  $f"; FALTANDO=$((FALTANDO + 1)); fi
done
for f in bootstrap.php auth.php database.php empresa.php monitor_helpers.php; do
    if [ -f "$ROOT/includes/$f" ]; then echo "  OK  includes/$f"; else echo "  FALTA  includes/$f"; FALTANDO=$((FALTANDO + 1)); fi
done
[ "$FALTANDO" -gt 0 ] && echo ">>> Envie do PC: .\\scripts\\enviar-para-vps.ps1"

echo ""
echo "========== 3. Nginx =========="
if [ -f "$ROOT/deploy/nginx-nfe-monitor.example.conf" ]; then
    cp "$ROOT/deploy/nginx-nfe-monitor.example.conf" "$NGINX_AVAIL"
    ln -sf "$NGINX_AVAIL" "$NGINX_ENABLED"
    echo "Config exemplo em $NGINX_AVAIL — edite server_name e SSL."
else
    echo "AVISO: deploy/nginx-nfe-monitor.example.conf não encontrado"
fi
nginx -t && systemctl reload nginx

echo ""
echo "========== 4. Testes HTTP ($PUBLIC_URL) =========="
[ -f "$WEB/ping.php" ] && curl -sk -o /tmp/nfe-ping.txt -w "ping.php %{http_code}\n" "${PUBLIC_URL}/ping.php" || true
curl -sk -o /dev/null -w "login.php %{http_code}\n" "${PUBLIC_URL}/login.php" 2>/dev/null || true

echo ""
sudo -u www-data test -r "$WEB/index.php" && echo "www-data le index: SIM" || echo "www-data le index: NAO"
