#!/bin/bash
echo "=== Sockets PHP ==="
ls -la /run/php/ 2>/dev/null || echo "Pasta /run/php vazia"

echo ""
echo "=== Snippet Nginx (fastcgi_pass) ==="
grep -r fastcgi_pass /etc/nginx/snippets/fastcgi-php.conf 2>/dev/null

echo ""
echo "=== PHP-FPM ativo? ==="
systemctl is-active php8.3-fpm 2>/dev/null || true
systemctl is-active php8.2-fpm 2>/dev/null || true
systemctl is-active php8.1-fpm 2>/dev/null || true
systemctl is-active php-fpm 2>/dev/null || true

echo ""
echo "=== Ultimos erros Nginx ==="
tail -15 /var/log/nginx/error.log 2>/dev/null

echo ""
echo "=== PHP CLI (login.php) ==="
php /root/nfe-monitor/Frontend/nfe/login.php 2>&1 | head -20

echo ""
echo "=== PHP como www-data ==="
sudo -u www-data php /root/nfe-monitor/Frontend/nfe/login.php 2>&1 | head -20
