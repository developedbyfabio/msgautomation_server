#!/bin/sh
# msgautomation — instalador do agente coletor (Servidores / Fatia 4).
# Servido pelo PROPRIO app. NAO contem token: AGENT_URL e AGENT_TOKEN vem por
# variavel de ambiente na hora de instalar (uma vez, como root) e param no
# config restrito (chmod 600). Idempotente: reinstalar so atualiza.
#
#   curl -fsSL <APP>/servidores/agente/instalar.sh \
#     | sudo AGENT_URL=<endpoint> AGENT_TOKEN=<token> sh
#
# Desinstalar:
#   sudo msgautomation-agent-uninstall   (criado por este instalador)
set -eu

: "${AGENT_URL:?defina AGENT_URL=<endpoint de ingestao>}"
: "${AGENT_TOKEN:?defina AGENT_TOKEN=<token do servidor>}"
INTERVAL="${AGENT_INTERVAL:-30}"     # segundos entre coletas

BIN=/usr/local/bin/msgautomation-agent
CONFIG_DIR=/etc/msgautomation-agent
CONFIG="$CONFIG_DIR/config"

echo "==> Instalando o agente msgautomation..."

# 1. agente (embutido verbatim pelo app; read-only, PUSH de saida)
cat > "$BIN" <<'MSGAUTO_AGENT_EOF'
@@AGENT_SCRIPT@@
MSGAUTO_AGENT_EOF
chmod 755 "$BIN"

# 2. config restrito com o token (nunca no ps, nunca versionado)
mkdir -p "$CONFIG_DIR"
umask 077
cat > "$CONFIG" <<CFG
AGENT_URL=$AGENT_URL
AGENT_TOKEN=$AGENT_TOKEN
CFG
chmod 600 "$CONFIG"

# 3. desinstalador (remocao trivial, sem residuo)
cat > /usr/local/bin/msgautomation-agent-uninstall <<'UNINSTALL_EOF'
#!/bin/sh
set -eu
echo "==> Removendo o agente msgautomation..."
if command -v systemctl >/dev/null 2>&1; then
    systemctl disable --now msgautomation-agent.timer 2>/dev/null || true
    rm -f /etc/systemd/system/msgautomation-agent.service /etc/systemd/system/msgautomation-agent.timer
    systemctl daemon-reload 2>/dev/null || true
fi
crontab -l 2>/dev/null | grep -v msgautomation-agent | crontab - 2>/dev/null || true
rm -f /usr/local/bin/msgautomation-agent /etc/msgautomation-agent/config
rmdir /etc/msgautomation-agent 2>/dev/null || true
rm -f /usr/local/bin/msgautomation-agent-uninstall /usr/local/bin/msgautomation-agent-update
echo "==> Removido. Nenhum residuo."
UNINSTALL_EOF
chmod 755 /usr/local/bin/msgautomation-agent-uninstall

# 3b. atualizador: rebaixa a versao nova do agente do proprio app e troca o
#     binario, PRESERVANDO o config (token+URL) e o timer. Um comando, sem
#     reinstalar nem reconfigurar. (o agente ja sabe se auto-atualizar via
#     --update; este wrapper e so pra descoberta: `sudo msgautomation-agent-update`)
cat > /usr/local/bin/msgautomation-agent-update <<'UPDATE_EOF'
#!/bin/sh
exec /usr/local/bin/msgautomation-agent --update "$@"
UPDATE_EOF
chmod 755 /usr/local/bin/msgautomation-agent-update

# 4. agendamento: systemd timer (preferido) com fallback cron
if command -v systemctl >/dev/null 2>&1; then
    cat > /etc/systemd/system/msgautomation-agent.service <<SVC
[Unit]
Description=msgautomation metrics agent (push once)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
# Usuario comum: coleta nao exige root (leitura de /proc, df, nproc).
User=nobody
ExecStart=$BIN
SVC

    cat > /etc/systemd/system/msgautomation-agent.timer <<TMR
[Unit]
Description=msgautomation metrics agent (a cada ${INTERVAL}s)

[Timer]
OnBootSec=15
OnUnitActiveSec=${INTERVAL}
AccuracySec=1s

[Install]
WantedBy=timers.target
TMR

    # config legivel pelo usuario 'nobody' que roda a coleta.
    chgrp nogroup "$CONFIG" 2>/dev/null || true
    chmod 640 "$CONFIG"
    systemctl daemon-reload
    systemctl enable --now msgautomation-agent.timer
    echo "==> Timer systemd ativo (a cada ${INTERVAL}s)."
else
    # fallback: cron de 1 min (granularidade minima do cron).
    LINE="* * * * * $BIN >/dev/null 2>&1"
    ( crontab -l 2>/dev/null | grep -v msgautomation-agent; echo "$LINE" ) | crontab -
    echo "==> Cron instalado (1 min; sem systemd para sub-minuto)."
fi

echo "==> Pronto. O servidor deve aparecer como 'Recebendo dados' em ~${INTERVAL}s."
echo "    Atualizar:   sudo msgautomation-agent-update   (preserva token e timer)"
echo "    Desinstalar: sudo msgautomation-agent-uninstall"
