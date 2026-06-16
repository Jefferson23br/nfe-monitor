#!/bin/bash
# Instala logo e emblema no document root do Nginx.
# Rodar na VPS como root:
#   bash /root/nfe-monitor/scripts/instalar-imagens-vps.sh

set -e
PROJECT="${NFE_PROJECT_ROOT:-/root/nfe-monitor}"
DEST="$PROJECT/Frontend/nfe/assets/img"

echo "=== Instalando imagens em $DEST ==="
mkdir -p "$DEST"

copiou=0

if [ -f "$PROJECT/images/logo-removebg-preview.png" ]; then
    cp -f "$PROJECT/images/logo-removebg-preview.png" "$DEST/logo-removebg.png"
    cp -f "$PROJECT/images/logo-removebg-preview.png" "$DEST/logo.png"
    echo "OK: images/logo-removebg-preview.png -> assets/img/"
    copiou=1
fi

if [ -f "$PROJECT/images/logo.png" ]; then
    cp -f "$PROJECT/images/logo.png" "$DEST/logo.png"
    echo "OK: images/logo.png -> assets/img/logo.png"
    copiou=1
fi

if [ -f "$PROJECT/Frontend/nfe/assets/img/logo.png" ]; then
    cp -f "$PROJECT/Frontend/nfe/assets/img/logo.png" "$DEST/logo.png"
    echo "OK: Frontend/nfe/assets/img/logo.png"
    copiou=1
fi

for src in \
    "$PROJECT/images/emblema-removebg-preview.png" \
    "$PROJECT/Frontend/nfe/assets/img/emblema.png"; do
    if [ -f "$src" ]; then
        cp -f "$src" "$DEST/emblema.png"
        echo "OK: $(basename "$src") -> emblema.png"
        copiou=1
        break
    fi
done

if [ -f "$PROJECT/images/emblema-removebg-preview.svg" ]; then
    cp -f "$PROJECT/images/emblema-removebg-preview.svg" "$DEST/emblema.svg"
    echo "OK: emblema.svg"
    copiou=1
elif [ -f "$PROJECT/Frontend/nfe/assets/img/emblema.svg" ]; then
    cp -f "$PROJECT/Frontend/nfe/assets/img/emblema.svg" "$DEST/emblema.svg"
    echo "OK: Frontend emblema.svg"
    copiou=1
fi

chmod -R a+rX "$PROJECT/Frontend/nfe/assets"
find "$DEST" -type f -exec chmod 644 {} \;
# Pasta assets precisa ser 755 para o Nginx (www-data) entrar em img/
chmod 755 "$PROJECT/Frontend/nfe/assets" 2>/dev/null || true

echo ""
echo "=== Permissoes assets (www-data precisa do x) ==="
ls -ld "$PROJECT/Frontend/nfe/assets" "$DEST" 2>/dev/null || true

echo ""
echo "=== Teste leitura como www-data ==="
if id www-data &>/dev/null; then
    for f in logo.png emblema.png; do
        if sudo -u www-data test -r "$DEST/$f"; then
            echo "OK www-data le: $f"
        else
            echo "FALHA www-data NAO le: $f — rode: bash $PROJECT/scripts/fix-permissoes-vps.sh"
        fi
    done
fi

echo ""
echo "=== Nginx: regra bloqueando /assets/img/ ? ==="
if command -v nginx &>/dev/null; then
    nginx -T 2>/dev/null | grep -nE "assets/img|location.*img|deny.*png" | head -20 || echo "(nenhuma regra obvia — verifique sites-enabled)"
fi

echo ""
echo "=== Arquivos em $DEST ==="
ls -la "$DEST" || true

echo ""
echo "=== Teste HTTP local (nginx root) ==="
for f in logo.png emblema.png; do
    if [ -f "$DEST/$f" ]; then
        echo "OK arquivo: $DEST/$f ($(wc -c < "$DEST/$f") bytes)"
    else
        echo "FALTA: $DEST/$f"
    fi
done

if [ "$copiou" -eq 0 ]; then
    echo ""
    echo "Nenhuma imagem encontrada no projeto."
    echo "Envie do Windows com scp:"
    echo "  scp C:\\...\\nfe-monitor\\Frontend\\nfe\\assets\\img\\logo.png root@SERVIDOR:/root/nfe-monitor/Frontend/nfe/assets/img/"
    echo "  scp C:\\...\\nfe-monitor\\Frontend\\nfe\\assets\\img\\emblema.png root@SERVIDOR:/root/nfe-monitor/Frontend/nfe/assets/img/"
    exit 1
fi

echo ""
echo "Pronto. Teste no navegador:"
echo "  https://SEU_DOMINIO/assets/img/logo.png"
echo "  https://SEU_DOMINIO/login.php (Ctrl+F5)"
