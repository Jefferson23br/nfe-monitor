#!/bin/bash
# Instala dependências PHP (NFePHP) na VPS
# Uso: cd /root/nfe-monitor && bash scripts/instalar-dependencias.sh

set -e
cd "$(dirname "$0")/.."

if ! command -v composer >/dev/null 2>&1; then
    echo "Instalando Composer..."
    apt-get update -qq
    apt-get install -y composer unzip
fi

echo "Instalando vendor/ (nfephp)..."
composer install --no-dev --optimize-autoloader

if [ -f vendor/autoload.php ]; then
    echo "OK vendor/autoload.php criado."
    php -r "require 'vendor/autoload.php'; echo 'Autoload OK\n';"
else
    echo "ERRO: vendor/autoload.php não foi criado."
    exit 1
fi
