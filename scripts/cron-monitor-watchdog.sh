#!/bin/bash
# Retoma o robô se parou antes de [589] (a cada 10 min).
# Crontab: */10 * * * * /root/nfe-monitor/scripts/cron-monitor-watchdog.sh >> /var/log/nfe-monitor-watchdog.log 2>&1

set -e
cd "$(dirname "$0")/.." || exit 1

LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')]"
LAUNCH="$(dirname "$0")/cron-lancar-robo.sh"
chmod +x "$LAUNCH" 2>/dev/null || true

mapfile -t EMPRESA_IDS < <(sudo -u postgres psql -d nfe_monitor -t -A -c "SELECT id FROM empresas WHERE ativo = TRUE ORDER BY id")

if [ "${#EMPRESA_IDS[@]}" -eq 0 ]; then
    exit 0
fi

for id in "${EMPRESA_IDS[@]}"; do
    if [ -z "$id" ]; then
        continue
    fi
    echo "$LOG_PREFIX Watchdog empresa $id"
    "$LAUNCH" "$id" || true
done
