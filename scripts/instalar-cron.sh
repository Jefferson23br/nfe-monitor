#!/bin/bash
# Instala cron de consulta NSU a cada 3 horas
set -e

CRON_LINE="0 */3 * * * /root/nfe-monitor/scripts/cron-monitor.sh >> /var/log/nfe-monitor-cron.log 2>&1"
SCRIPT="/root/nfe-monitor/scripts/cron-monitor.sh"

chmod +x "$SCRIPT"

touch /var/log/nfe-monitor-cron.log
chmod 644 /var/log/nfe-monitor-cron.log

(crontab -l 2>/dev/null | grep -v 'cron-monitor.sh'; echo "$CRON_LINE") | crontab -

echo "Cron instalado:"
crontab -l | grep cron-monitor
echo ""
echo "Log: tail -f /var/log/nfe-monitor-cron.log"
