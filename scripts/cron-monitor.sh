#!/bin/bash
# Consulta NSU automática a cada 3 horas — inicia robô contínuo em background
# Crontab: 0 */3 * * * /root/nfe-monitor/scripts/cron-monitor.sh >> /var/log/nfe-monitor-cron.log 2>&1

set -e
cd "$(dirname "$0")/.." || exit 1

export NFE_MONITOR_CRON=1
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')]"
LAUNCH="$(dirname "$0")/cron-lancar-robo.sh"
OPENSSL_LEGACY="$(dirname "$0")/../deploy/openssl-legacy.cnf"
if [ -f "$OPENSSL_LEGACY" ]; then
    export OPENSSL_CONF="$OPENSSL_LEGACY"
fi

chmod +x "$LAUNCH" 2>/dev/null || true

mapfile -t EMPRESA_IDS < <(sudo -u postgres psql -d nfe_monitor -t -A -c "SELECT id FROM empresas WHERE ativo = TRUE ORDER BY id")

if [ "${#EMPRESA_IDS[@]}" -eq 0 ]; then
    echo "$LOG_PREFIX Nenhuma empresa ativa no banco."
    exit 0
fi

SLOT_H=$(( $(date +%H) / 3 * 3 ))
echo "$LOG_PREFIX Início cron NSU — ${#EMPRESA_IDS[@]} empresa(s): ${EMPRESA_IDS[*]} (slot $(date +%Y-%m-%d)-$(printf '%02d' "$SLOT_H"))"

for id in "${EMPRESA_IDS[@]}"; do
    if [ -z "$id" ]; then
        continue
    fi
    echo "$LOG_PREFIX --- Empresa $id ---"
    "$LAUNCH" "$id" --forcar || true
done

echo "$LOG_PREFIX Fim — ${#EMPRESA_IDS[@]} empresa(s) enfileirada(s)"
