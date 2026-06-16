#!/bin/bash
# Corrige 404 em /assets/img/ — rode na VPS como root:
#   bash /root/nfe-monitor/scripts/corrigir-nginx-imagens.sh

set -e
PROJECT="${NFE_PROJECT_ROOT:-/root/nfe-monitor}"
IMG="$PROJECT/Frontend/nfe/assets/img"
ASSETS="$PROJECT/Frontend/nfe/assets"

echo "=== 1. Permissoes ==="
chmod 711 /root
chmod -R a+rX "$PROJECT/Frontend/nfe/assets"
chmod 755 "$ASSETS"
find "$IMG" -type f -exec chmod 644 {} \;
ls -ld "$ASSETS" "$IMG"
ls -la "$IMG"

echo ""
echo "=== 2. Teste www-data ==="
for f in logo.png emblema.png; do
    sudo -u www-data test -r "$IMG/$f" && echo "OK $f" || echo "FALHA $f"
done

echo ""
echo "=== 3. Nginx — regras para assets/img ==="
nginx -T 2>/dev/null | grep -nE "server_name|assets/img|location.*img|alias.*img" | head -40 || true

echo ""
echo "=== 4. Teste local (curl) ==="
HOST="${NFE_NGINX_HOST:-nfe.seudominio.com.br}"
curl -sI -o /dev/null -w "logo.png -> HTTP %{http_code}\n" "http://127.0.0.1/assets/img/logo.png" -H "Host: ${HOST}" || true
curl -sI -o /dev/null -w "auth.css  -> HTTP %{http_code}\n" "http://127.0.0.1/assets/css/auth.css" -H "Host: ${HOST}" || true

echo ""
echo "Se logo.png ainda for 404, adicione no server {} do Nginx:"
echo ""
cat <<'NGINX'
    location ^~ /assets/img/ {
        alias /root/nfe-monitor/Frontend/nfe/assets/img/;
        access_log off;
        expires 7d;
    }
NGINX
echo ""
echo "Depois: nginx -t && systemctl reload nginx"
