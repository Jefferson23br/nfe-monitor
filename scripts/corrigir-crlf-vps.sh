#!/bin/bash
# Corrige finais de linha Windows (CRLF) nos scripts após upload via SCP do Windows.
# Uso na VPS: bash /root/nfe-monitor/scripts/corrigir-crlf-vps.sh

set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if command -v dos2unix >/dev/null 2>&1; then
    dos2unix "$ROOT"/scripts/*.sh 2>/dev/null || true
else
    for f in "$ROOT"/scripts/*.sh; do
        [ -f "$f" ] || continue
        sed -i 's/\r$//' "$f"
    done
fi

chmod +x "$ROOT"/scripts/*.sh
echo "Scripts corrigidos (LF Unix)."
