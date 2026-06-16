#!/bin/bash
HOST="${NFE_SERVER_NAME:-nfe.seudominio.com.br}"
echo "=== server_name $HOST ==="
nginx -T 2>/dev/null | grep -A40 "server_name ${HOST}" | head -60 || echo "Host nao encontrado"
curl -skI -H "Host: ${HOST}" "https://127.0.0.1/login.php" | head -3 || true
