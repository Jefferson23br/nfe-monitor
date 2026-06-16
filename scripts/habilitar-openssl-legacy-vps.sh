#!/bin/bash
# OpenSSL 3 + PHP 8.3: certificados .pfx brasileiros antigos (RC2) precisam do provider legacy.
# Rodar na VPS como root: bash /root/nfe-monitor/scripts/habilitar-openssl-legacy-vps.sh
set -e

ROOT="/root/nfe-monitor"
CONF="$ROOT/deploy/openssl-legacy.cnf"

if [ ! -f "$CONF" ]; then
    echo "FALTA $CONF — envie deploy/openssl-legacy.cnf do PC."
    exit 1
fi

echo "=== Provider legacy OpenSSL ==="
echo "Arquivo: $CONF"

for POOL in /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/nfe.conf; do
    if [ ! -f "$POOL" ]; then
        continue
    fi
    if grep -q 'OPENSSL_CONF' "$POOL"; then
        sed -i "s|^env\[OPENSSL_CONF\].*|env[OPENSSL_CONF] = $CONF|" "$POOL"
        echo "Atualizado: $POOL"
    else
        printf '\n; Certificados .pfx com criptografia legada (OpenSSL 3)\nenv[OPENSSL_CONF] = %s\n' "$CONF" >> "$POOL"
        echo "Adicionado em: $POOL"
    fi
done

systemctl restart php8.3-fpm
echo "php8.3-fpm reiniciado."

if [ -f /etc/cron.d/nfe-monitor ] || crontab -l 2>/dev/null | grep -q monitor.php; then
    echo ""
    echo "Se o robô (cron) também falhar ao ler .pfx, adicione no crontab:"
    echo "  OPENSSL_CONF=$CONF"
fi

echo ""
echo "Concluído. Teste o cadastro de empresa novamente."
