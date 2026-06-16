#!/bin/bash
# Permite www-data (PHP-FPM) ler /root/nfe-monitor — OBRIGATÓRIO para o botão "Consultar NSU"
# Rodar como root: bash /root/nfe-monitor/scripts/fix-permissoes-vps.sh

set -e
PROJECT="/root/nfe-monitor"
PHP="/usr/bin/php8.3"

echo "=== Antes ==="
namei -l "$PROJECT/vendor/autoload.php" 2>/dev/null || ls -la /root /root/nfe-monitor/vendor/autoload.php 2>/dev/null || true

echo ""
echo "=== Ajustando permissões ==="
# www-data precisa TRAVERSE /root (sem listar o diretório)
chmod 711 /root

# Projeto legível por todos (somente leitura + exec em pastas)
chmod -R a+rX "$PROJECT"
find "$PROJECT" -type f -exec chmod a+r {} \;
find "$PROJECT" -type d -exec chmod a+rx {} \;

# Certificados .pfx: www-data precisa ler para baixar XML e consultar SEFAZ pelo painel
if [ -d "$PROJECT/certs" ]; then
    chmod 755 "$PROJECT/certs"
    find "$PROJECT/certs" -type f \( -name '*.pfx' -o -name '*.p12' \) -exec chmod 644 {} \;
fi

# Status do robô (cron root + painel www-data)
mkdir -p "$PROJECT/var/status"
chown root:www-data "$PROJECT/var" "$PROJECT/var/status" 2>/dev/null || true
chmod 775 "$PROJECT/var" "$PROJECT/var/status"
for f in "$PROJECT"/status_robot_empresa_*.json; do
    [ -f "$f" ] || continue
    id="${f##*status_robot_empresa_}"
    id="${id%.json}"
    mv "$f" "$PROJECT/var/status/empresa_${id}.json"
done
chmod 664 "$PROJECT/var/status/"*.json 2>/dev/null || true
chown root:www-data "$PROJECT/var/status/"*.json 2>/dev/null || true

echo ""
echo "=== Depois ==="
namei -l "$PROJECT/vendor/autoload.php" 2>/dev/null || true

echo ""
echo "=== Teste www-data ==="
if sudo -u www-data test -r "$PROJECT/vendor/autoload.php"; then
    echo "OK: vendor/autoload.php legível"
else
    echo "FALHOU: ainda sem permissão em vendor/autoload.php"
    exit 1
fi

echo ""
sudo -u www-data "$PHP" "$PROJECT/monitor.php" --empresa=1 --force 2>&1 | head -8
echo ""
echo "Se aparecer saída da SEFAZ acima, o painel web também funcionará."
