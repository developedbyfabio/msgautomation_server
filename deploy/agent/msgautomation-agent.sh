#!/bin/sh
# msgautomation — agente coletor de metricas (Servidores / Fatia 4).
#
# SEGURANCA (inegociavel — roda em producao real):
#  - READ-ONLY: so LE /proc, df, uptime, nproc. NAO escreve nada no host
#    (nem log proprio; a saida vai pro journald quando rodado por systemd).
#  - PUSH DE SAIDA: uma requisicao HTTPS de saida por execucao. NUNCA abre
#    porta de escuta. O servidor monitorado so FALA, nao OUVE.
#  - PRIVILEGIO MINIMO: roda como usuario comum. /proc, df, nproc nao exigem
#    root. (Se uma metrica exigir permissao, documentar — nao pedir root "por
#    via das duvidas".)
#  - TOKEN so no config local restrito (chmod 600), lido para variavel de
#    ambiente do proprio processo; NUNCA em argumento de linha de comando
#    (invisivel no `ps`), nunca hardcoded neste script versionado.
#  - BACKOFF: se o endpoint estiver fora, tenta poucas vezes com espera curta e
#    DESISTE (uma amostra perdida e tolerada; a avaliacao central tolera gap).
#    Nao acumula, nao trava o host.
#
# Uso:
#   msgautomation-agent.sh            # coleta e faz PUSH (modo normal)
#   msgautomation-agent.sh --dry-run  # imprime o payload JSON, NAO envia
#   msgautomation-agent.sh --disks-only  # imprime so o array de discos (teste)
#   msgautomation-agent.sh --version  # imprime a versao do agente e sai
#   sudo msgautomation-agent.sh --update  # rebaixa a versao nova do app e se
#                                          # substitui, PRESERVANDO o config
#
# Config (lido em ordem): variaveis de ambiente AGENT_URL/AGENT_TOKEN, senao
# /etc/msgautomation-agent/config (KEY=VALUE por linha).

set -eu

CONFIG_FILE="${AGENT_CONFIG:-/etc/msgautomation-agent/config}"
AGENT_VERSION="2"

# --- config: env tem precedencia; senao le o arquivo restrito ----------------
if [ -z "${AGENT_URL:-}" ] || [ -z "${AGENT_TOKEN:-}" ]; then
    if [ -r "$CONFIG_FILE" ]; then
        # shellcheck disable=SC1090
        . "$CONFIG_FILE"
    fi
fi

# --- df RESILIENTE: um mount de rede morto NAO pode travar nem quebrar --------
# Lição do API-ECOMMERCE (`df: /mnt/fotos: Host is down`, ~21s travado):
#  - timeout: um mount pendurado nao segura o coletor (cap de 8s).
#  - -x <tipos de rede>: o df nem STATA cifs/nfs/etc -> nao trava no mount morto
#    e nao lista compartilhamento de rede (que nao e disco local).
#  - 2>/dev/null: o "Host is down" vai pro stderr; nao pode contaminar o parsing.
#  - "|| true": saida nao-zero do df (mount morto) NUNCA mata o script (set -e).
# AGENT_DF_INPUT (seam de teste) tem precedencia e pula o df real.
run_df() {
    if [ -n "${AGENT_DF_INPUT:-}" ]; then
        printf '%s\n' "$AGENT_DF_INPUT"
        return 0
    fi
    # exclui por TIPO os FS de rede/penduraveis (os demais pseudo-FS caem na
    # allowlist do awk; aqui excluimos so os que podem TRAVAR o stat do df).
    set -- -PT -x cifs -x smbfs -x smb3 -x nfs -x nfs4 \
        -x fuse.sshfs -x fuse.gvfsd-fuse -x fuse.rclone -x fuse.s3fs
    if command -v timeout >/dev/null 2>&1; then
        timeout 8 df "$@" 2>/dev/null || true
    else
        df "$@" 2>/dev/null || true
    fi
}

# --- filtro de disco: SO filesystems reais, cada device UMA vez ---------------
# Ignora pseudo-FS (tmpfs, devtmpfs, overlay, squashfs, cifs, nfs, ...) por
# ALLOWLIST de tipos reais (default-deny) e DEDUPLICA por device — os N overlays
# do Docker apontam para "overlay"/mesmo device e nao passam; CIFS/NFS saem por
# NAO estarem na allowlist (nunca por nome). Linha de erro/mount morto que por
# acaso chegue ao stdout tem "type" fora da allowlist -> ignorada sem quebrar.
# Saida: objetos JSON de disco (pode ser VAZIO — e valido; o endpoint tolera).
collect_disks_json() {
    run_df | awk '
        NR == 1 { next }                              # cabecalho
        NF < 7 { next }                               # linha curta/lixo: ignora
        {
            fs = $1; type = $2; blocks = $3; used = $4; cap = $6;
            mount = $7; for (i = 8; i <= NF; i++) mount = mount " " $i;
            # allowlist de tipos REAIS (qualquer outro = pseudo-FS/rede, fora):
            if (type !~ /^(ext2|ext3|ext4|xfs|btrfs|zfs|jfs|reiserfs|f2fs|vfat|exfat|ntfs|ufs)$/) next;
            if (cap !~ /^[0-9]+%?$/) next;             # capacidade nao-numerica: lixo
            if (seen[fs]) next;                       # dedup por device
            seen[fs] = 1;
            gsub(/%/, "", cap);
            tgb = blocks / 1024 / 1024;               # 1K-blocks -> GB
            ugb = used / 1024 / 1024;
            if (out != "") out = out ",";
            out = out sprintf("{\"mount\":\"%s\",\"pct\":%s,\"total_gb\":%.1f,\"used_gb\":%.1f}", mount, cap, tgb, ugb);
        }
        END { printf "%s", out }
    '
}

# --- CPU %: dois snapshots de /proc/stat com 1s de intervalo ------------------
cpu_pct() {
    [ -r /proc/stat ] || { echo 0; return; }
    set -- $(awk '/^cpu /{for(i=2;i<=NF;i++)s+=$i; print s, ($5+$6)}' /proc/stat)
    t1="$1"; i1="$2"
    sleep 1
    set -- $(awk '/^cpu /{for(i=2;i<=NF;i++)s+=$i; print s, ($5+$6)}' /proc/stat)
    t2="$1"; i2="$2"
    awk -v t1="$t1" -v i1="$i1" -v t2="$t2" -v i2="$i2" 'BEGIN{
        dt=t2-t1; di=i2-i1; if(dt<=0){print 0; exit}
        p=100*(dt-di)/dt; if(p<0)p=0; if(p>100)p=100; printf "%.1f", p
    }'
}

mem_pct() {   # (MemTotal - MemAvailable) / MemTotal
    awk '/^MemTotal:/{t=$2} /^MemAvailable:/{a=$2} END{if(t>0)printf "%.1f", 100*(t-a)/t; else print 0}' /proc/meminfo
}
mem_total_mb() { awk '/^MemTotal:/{printf "%.0f", $2/1024}' /proc/meminfo; }
mem_used_mb()  { awk '/^MemTotal:/{t=$2} /^MemAvailable:/{a=$2} END{printf "%.0f", (t-a)/1024}' /proc/meminfo; }

swap_pct() {  # (SwapTotal - SwapFree) / SwapTotal ; 0 se nao ha swap
    awk '/^SwapTotal:/{t=$2} /^SwapFree:/{f=$2} END{if(t>0)printf "%.1f", 100*(t-f)/t; else print 0}' /proc/meminfo
}

load_json() { awk '{printf "[%s,%s,%s]", $1, $2, $3}' /proc/loadavg; }
cpu_count() { nproc 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1; }

build_payload() {
    printf '{"agent_version":"%s","ts":%s,"cpu_pct":%s,"cpu_count":%s,"load":%s,"mem":{"pct":%s,"total_mb":%s,"used_mb":%s},"swap":{"pct":%s},"disks":[%s]}' \
        "$AGENT_VERSION" "$(date +%s)" "$(cpu_pct)" "$(cpu_count)" "$(load_json)" \
        "$(mem_pct)" "$(mem_total_mb)" "$(mem_used_mb)" "$(swap_pct)" "$(collect_disks_json)"
}

# --- self-update: rebaixa a versao nova do app e se substitui -----------------
# Roda como root (escreve em /usr/local/bin). PRESERVA o config (token+URL) e o
# timer — nada mais e tocado. A URL do coletor deriva do AGENT_URL de ingestao
# (mesmo host/app) ou vem de AGENT_COLLECTOR_URL. Seam de teste: AGENT_BIN.
do_update() {
    _bin="${AGENT_BIN:-/usr/local/bin/msgautomation-agent}"
    if [ -n "${AGENT_COLLECTOR_URL:-}" ]; then
        _url="$AGENT_COLLECTOR_URL"
    else
        : "${AGENT_URL:?AGENT_URL ausente (config nao encontrado) — reinstale o agente}"
        _url="${AGENT_URL%/webhook/servers/ingest}/servidores/agente/coletor.sh"
    fi
    echo "==> Baixando agente novo de: $_url"
    _tmp="$(dirname "$_bin")/.msgautomation-agent.new.$$"
    trap 'rm -f "$_tmp"' EXIT INT TERM
    if ! curl -fsSL --max-time 30 "$_url" -o "$_tmp" 2>/dev/null; then
        echo "!! download falhou — agente atual mantido intacto" >&2; exit 1
    fi
    # sanidade: precisa ser o script do agente (evita gravar HTML de erro/login).
    if ! head -n 1 "$_tmp" | grep -q '^#!/bin/sh' || ! grep -q 'msgautomation' "$_tmp"; then
        echo "!! download nao parece o agente msgautomation — mantido intacto" >&2; exit 1
    fi
    chmod 755 "$_tmp"
    # mv no MESMO diretorio = rename atomico; o processo em execucao segue lendo
    # o inode antigo (self-replace seguro). Config e timer NAO sao tocados.
    mv -f "$_tmp" "$_bin"
    trap - EXIT INT TERM
    _nv="$(AGENT_URL=x AGENT_TOKEN=x sh "$_bin" --version 2>/dev/null || echo '?')"
    echo "==> Atualizado para a versao $_nv. Config e timer preservados."
}

# --- modos de teste/inspecao/manutencao (nao enviam metrica) ------------------
case "${1:-}" in
    --version)    printf '%s\n' "$AGENT_VERSION"; exit 0 ;;
    --disks-only) collect_disks_json; echo; exit 0 ;;
    --dry-run)    build_payload; echo; exit 0 ;;
    --update)     do_update; exit 0 ;;
esac

# --- envio com BACKOFF (3 tentativas, espera curta, depois desiste) ----------
: "${AGENT_URL:?AGENT_URL nao definido (config ausente)}"
: "${AGENT_TOKEN:?AGENT_TOKEN nao definido (config ausente)}"

PAYLOAD="$(build_payload)"
attempt=1
while [ "$attempt" -le 3 ]; do
    code=$(curl -sS -o /dev/null -w '%{http_code}' \
        --max-time 10 \
        -X POST "$AGENT_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Agent-Token: $AGENT_TOKEN" \
        --data "$PAYLOAD" 2>/dev/null || echo 000)
    case "$code" in
        2*) exit 0 ;;                       # entregue
        401|403|413|422) exit 1 ;;          # erro definitivo: nao readianta insistir
    esac
    # 000/5xx/429: transitorio -> backoff curto e tenta de novo
    sleep $((attempt * 3))
    attempt=$((attempt + 1))
done
exit 1   # desiste: uma amostra perdida e tolerada (nao acumula)
