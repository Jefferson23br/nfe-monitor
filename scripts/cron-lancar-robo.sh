#!/bin/bash
# Inicia o robô contínuo em background para uma empresa (se aplicável).
# Uso: cron-lancar-robo.sh EMPRESA_ID [--forcar]

set -e

EMPRESA_ID="${1:-}"
FORCAR_FLAG="${2:-}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP=/usr/bin/php8.3
LOG="/var/log/nfe-monitor-empresa-${EMPRESA_ID}.log"
OPENSSL_LEGACY="${ROOT}/deploy/openssl-legacy.cnf"

if [ -z "$EMPRESA_ID" ]; then
    echo "Uso: $0 EMPRESA_ID [--forcar]" >&2
    exit 1
fi

if [ -f "$OPENSSL_LEGACY" ]; then
    export OPENSSL_CONF="$OPENSSL_LEGACY"
fi

export NFE_MONITOR_CRON=1

DEVE="$("$PHP" -d opcache.enable_cli=0 "${ROOT}/scripts/monitor-deve-iniciar.php" --empresa="$EMPRESA_ID" $FORCAR_FLAG 2>/dev/null | tr -d '\r')"
if [ "$DEVE" != "SIM" ]; then
    echo "Empresa $EMPRESA_ID: robô não iniciado ($DEVE)."
    exit 0
fi

touch "$LOG"
chmod 644 "$LOG"

nohup "$PHP" -d opcache.enable_cli=0 "${ROOT}/monitor.php" --empresa="$EMPRESA_ID" --cron >> "$LOG" 2>&1 &
PID=$!
echo "Empresa $EMPRESA_ID: robô iniciado em background (PID $PID). Log: $LOG"
