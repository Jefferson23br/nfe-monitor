#!/bin/bash
# Alternativa segura se /root continuar bloqueando o Nginx
# sudo bash /root/nfe-monitor/deploy/mover-para-var-www.sh

set -e
DEST="/var/www/nfe-monitor"
SRC="/root/nfe-monitor"

mkdir -p "$DEST"
rsync -a --delete "$SRC/Frontend/" "$DEST/Frontend/"
rsync -a "$SRC/includes/" "$DEST/includes/"
rsync -a "$SRC/vendor/" "$DEST/vendor/" 2>/dev/null || true
cp "$SRC/app.config.php" "$SRC/config.php" "$DEST/" 2>/dev/null || true
cp -r "$SRC/scripts" "$DEST/"

chown -R www-data:www-data "$DEST/Frontend/nfe"
chmod -R 755 "$DEST/Frontend/nfe"
find "$DEST/Frontend/nfe" -name "*.php" -exec chmod 644 {} \;

echo "Ajuste o Nginx:"
echo "  root $DEST/Frontend/nfe;"
echo "Depois: nginx -t && systemctl reload nginx"
