#!/bin/bash
# Verifica deploy do cron e testa consulta em TODAS as empresas ativas.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OK=0
FAIL=0

check_grep() {
    if grep -Fq "$2" "$1" 2>/dev/null; then
        echo "OK  $1 → $2"
        OK=$((OK + 1))
    else
        echo "FALHA  $1 — esperado: $2"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== Verificação deploy cron (v2) ==="
check_grep "$ROOT/monitor.php" "monitor-cron-v2"
check_grep "$ROOT/monitor.php" "monitor_invocado_pelo_cron"
check_grep "$ROOT/includes/monitor_helpers.php" "monitor_slot_consulta"
check_grep "$ROOT/scripts/cron-monitor.sh" "NFE_MONITOR_CRON=1"
check_grep "$ROOT/scripts/cron-monitor.sh" "--cron"

if file "$ROOT/scripts/cron-monitor.sh" | grep -q CRLF; then
    echo "FALHA  cron-monitor.sh tem CRLF — rode: sed -i 's/\r$//' $ROOT/scripts/cron-monitor.sh"
    FAIL=$((FAIL + 1))
else
    echo "OK  cron-monitor.sh sem CRLF"
    OK=$((OK + 1))
fi

[ -x "$ROOT/scripts/cron-monitor.sh" ] && echo "OK  cron-monitor.sh executável" && OK=$((OK + 1)) \
    || { echo "FALHA  chmod +x $ROOT/scripts/cron-monitor.sh"; FAIL=$((FAIL + 1)); }

echo ""
echo "=== Empresas ativas no banco ==="
sudo -u postgres psql -d nfe_monitor -c \
    "SELECT e.id, e.nome_fantasia, e.ativo,
            (SELECT valor FROM config_monitor c WHERE c.empresa_id = e.id AND c.campo = 'ultimo_nsu') AS ultimo_nsu,
            (SELECT valor FROM config_monitor c WHERE c.empresa_id = e.id AND c.campo = 'ultima_consulta_at') AS ultima_consulta
     FROM empresas e WHERE e.ativo = TRUE ORDER BY e.id;"

mapfile -t IDS < <(sudo -u postgres psql -d nfe_monitor -t -A -c "SELECT id FROM empresas WHERE ativo = TRUE ORDER BY id")

if [ "${#IDS[@]}" -eq 0 ]; then
    echo "FALHA  nenhuma empresa ativa"
    FAIL=$((FAIL + 1))
else
    echo ""
    echo "=== Teste --cron em cada empresa (${#IDS[@]}) ==="
    export NFE_MONITOR_CRON=1
    cd "$ROOT"
    for id in "${IDS[@]}"; do
        OUT=$(php8.3 -d opcache.enable_cli=0 monitor.php --empresa="$id" --cron 2>&1)
        NOME=$(echo "$OUT" | grep -m1 '🏢' | sed 's/^🏢 //')
        if echo "$OUT" | grep -q "Intervalo de 3h"; then
            echo "FALHA  empresa $id — bloqueada pelo throttle"
            echo "$OUT" | head -2
            FAIL=$((FAIL + 1))
        elif echo "$OUT" | grep -q '🏢'; then
            echo "OK  empresa $id — $NOME"
            OK=$((OK + 1))
        else
            echo "FALHA  empresa $id — saída inesperada:"
            echo "$OUT" | head -5
            FAIL=$((FAIL + 1))
        fi
    done
fi

echo ""
echo "=== Últimas consultas no log do robô (por empresa) ==="
sudo -u postgres psql -d nfe_monitor -c \
    "SELECT empresa_id, to_char(criado_em, 'DD/MM/YYYY HH24:MI') AS quando, tipo, left(descricao, 60) AS descricao
     FROM registros_robot
     WHERE tipo = 'CONSULTA'
     AND criado_em > NOW() - INTERVAL '24 hours'
     ORDER BY empresa_id, criado_em DESC;"

echo ""
echo "Resultado: $OK ok, $FAIL falha(s)"
[ "$FAIL" -eq 0 ]
