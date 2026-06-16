#!/bin/bash
# Corrige erro 500 do robô (vendor + teste)
set -e
cd /root/nfe-monitor

echo "=== Composer ==="
if [ ! -f vendor/autoload.php ]; then
    command -v composer >/dev/null || apt-get install -y composer unzip
    composer install --no-dev --optimize-autoloader
fi
test -f vendor/autoload.php && echo "OK vendor"

echo "=== PHP-FPM (shell_exec) ==="
php -r "echo 'disable_functions=' . ini_get('disable_functions') . PHP_EOL;"

echo "=== Teste robô empresa 1 ==="
php monitor.php --empresa=1 || true

echo "=== NSU no banco ==="
sudo -u postgres psql -d nfe_monitor -c "SELECT empresa_id, campo, valor FROM config_monitor;"
