#!/bin/bash
# Instala cron do robô NSU (3h + watchdog 10 min)
set -e

CRON_MAIN="0 */3 * * * /root/nfe-monitor/scripts/cron-monitor.sh >> /var/log/nfe-monitor-cron.log 2>&1"
CRON_WATCH="*/10 * * * * /root/nfe-monitor/scripts/cron-monitor-watchdog.sh >> /var/log/nfe-monitor-watchdog.log 2>&1"
ROOT="/root/nfe-monitor"

chmod +x "${ROOT}/scripts/cron-monitor.sh"
chmod +x "${ROOT}/scripts/cron-monitor-watchdog.sh"
chmod +x "${ROOT}/scripts/cron-lancar-robo.sh"

touch /var/log/nfe-monitor-cron.log
touch /var/log/nfe-monitor-watchdog.log
chmod 644 /var/log/nfe-monitor-cron.log
chmod 644 /var/log/nfe-monitor-watchdog.log

mkdir -p "${ROOT}/var/status"
chmod 775 "${ROOT}/var/status"

(
    crontab -l 2>/dev/null | grep -v 'cron-monitor.sh' | grep -v 'cron-monitor-watchdog.sh'
    echo "$CRON_MAIN"
    echo "$CRON_WATCH"
) | crontab -

echo "Cron instalado:"
crontab -l | grep -E 'cron-monitor'
echo ""
echo "Logs:"
echo "  tail -f /var/log/nfe-monitor-cron.log"
echo "  tail -f /var/log/nfe-monitor-watchdog.log"
echo "  tail -f /var/log/nfe-monitor-empresa-1.log  # por empresa"
