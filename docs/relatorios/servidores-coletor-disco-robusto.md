# Servidores — coletor de disco robusto (mount morto + CIFS + nunca zerar) + update — 2026-07-07

Git no início: HEAD `5d5b301`, branch `master`, remote `origin` (`msgautomation_server`).
**`APP_ENV=local`** (DEV `192.168.11.210`, painel/webhook na `:9100`). **Sem push.** Produção
`187.127.24.165` / Nextgest / nginx / Tunnel: **NÃO tocados**.

Suíte: **1087 → 1096 verdes** (4266 assertions; +9, zero regressão). Sem job tocado (o endpoint
roda no request/fpm, não na fila) — em vez de `queue:restart`, recarreguei o **php8.5-fpm**
(opcache) pra a validação nova valer já. **Commit: `48392e0`.**

---

## ⚠️ No topo — o que você precisa fazer nos servidores já instalados

A correção está no coletor que o **próprio painel serve**. Os agentes já instalados continuam
rodando a versão antiga até você atualizá-los. Em cada servidor (laravel-dev, YELLL, e o
**API-ECOMMERCE** que revelou o bug), rode **uma vez** (não pede token, preserva tudo):

```
curl -fsSL http://192.168.11.210:9100/servidores/agente/coletor.sh -o /tmp/msgautomation-agent.new && chmod 755 /tmp/msgautomation-agent.new && sudo mv /tmp/msgautomation-agent.new /usr/local/bin/msgautomation-agent
```

A partir daí cada servidor passa a ter **`sudo msgautomation-agent-update`** para updates futuros
(um comando, preserva token e timer). O **API-ECOMMERCE volta a reportar** assim que atualizar — e
o `/` a 97% passa a disparar o critical que hoje não dispara (o coletor morria antes).

> O comando de update **está documentado na aba Servidores** (junto do instalar/desinstalar), com
> a versão de uma linha acima para quem ainda não tem o `msgautomation-agent-update`.

*(Observação da fatia anterior, não é deste bug: o agente **local** do laravel-dev usa um token
órfão que não resolve → 401. Atualizar o coletor não muda isso; re-emita o token dele na tela
Servidores ou desligue `msgautomation-agent.timer`. Os 2 servidores remotos reportam normal.)*

---

## O bug (revelado pelo API-ECOMMERCE)

`df` real da máquina: `/` ext4 **97%**, `/boot`/`/srv` ext4, **2 CIFS** (um a **100%**), overlays
Docker, squashfs de snap, e **`df: /mnt/fotos: Host is down`** (share de rede caído, ~21s
travado). O coletor mandou **`"disks":[]`** → endpoint **422** → coletor **morreu (exit 1)** →
servidor "sem dados" → **watchdog falso** ("sem reportar", com a máquina viva). O `/` a 97% nunca
alertava porque o coletor caía antes de reportar.

## As 3 correções no coletor (`deploy/agent/msgautomation-agent.sh`, `AGENT_VERSION` 1→2)

### 1. `df` resiliente — mount morto não trava nem quebra
Nova `run_df()`:
- **`timeout 8 df ...`** (quando há `timeout`): um mount pendurado não segura o coletor.
- **`df -PT -x cifs -x smbfs -x smb3 -x nfs -x nfs4 -x fuse.sshfs -x fuse.gvfsd-fuse -x fuse.rclone
  -x fuse.s3fs`**: o `df` **nem stata** FS de rede → não trava no mount morto e não lista share.
- **`2>/dev/null`**: o `Host is down` (stderr) não contamina o parsing.
- **`|| true`**: saída não-zero do `df` (mount morto) **nunca** mata o script (era o `set -e`).

### 2. Allowlist por TIPO (mantida, reforçada como rede de segurança)
O filtro por tipo (default-deny) já existia e é a segunda camada: só
`ext2/3/4, xfs, btrfs, zfs, jfs, reiserfs, f2fs, vfat, exfat, ntfs, ufs` passam. CIFS/NFS/overlay/
squashfs/tmpfs saem **por tipo, nunca por nome**. Adicionei guardas extras no `awk` (`NF < 7` e
capacidade não-numérica → ignora) pra uma linha de erro que vaze ao stdout **não** virar disco.

### 3. Nunca payload inválido / nunca morrer
- Sobrou disco local → manda. `df` falhou parcial mas há locais → manda os que conseguiu.
- **Nenhum disco** (só pseudo-FS / tudo de rede) → manda `"disks":[]` e **o endpoint tolera**
  (abaixo) — CPU/RAM/swap/load seguem úteis e o watchdog **não** dispara falso.
- Problema no bloco de disco **degrada** (manda sem disco), **não** `exit 1`.

## Decisão do endpoint — tolera ausência de discos

`ServerIngestController`: `disks` passou de `required|array|min:1|max:20` para
**`nullable|array|max:20`** (+ `$data['disks'] ?? []` na gravação). **Auth (401/403), tamanho
(413), rate limit (429) e o resto da validação (cpu/mem seguem `required`) ficaram intactos** — só
a exigência de ≥1 disco caiu. Escolha registrada: uma amostra sem disco é melhor que servidor
"morto" + watchdog falso; as demais métricas continuam validadas normalmente.

## Comando de atualização (sem reinstalar os ~30)

- O agente ganhou **`--update`**: rebaixa a versão nova do **próprio app**
  (`/servidores/agente/coletor.sh`, derivado do `AGENT_URL`), valida (é `#!/bin/sh` + contém
  `msgautomation` — recusa HTML de erro/login), e **se substitui atomicamente** (`mv` no mesmo
  diretório → o processo em execução segue no inode antigo), **preservando `config` e timer**.
- O instalador passou a criar o wrapper **`/usr/local/bin/msgautomation-agent-update`**
  (`exec msgautomation-agent --update`); o desinstalador o remove. Também há `--version`.
- **Documentado na aba Servidores** (bloco de instruções + modal por servidor).

## Testes (o bug virou teste)

Rodam o **script bash real** (seam `AGENT_DF_INPUT`), não uma reimplementação:
- **Amostra do API-ECOMMERCE** (LVM ext4 `/`,`/boot`,`/srv` + 2 overlays + squashfs + 2 CIFS +
  linha de mount morto) → resultado **exatamente `/`, `/boot`, `/srv`** (97/24/76), nunca vazio.
- **CIFS a 100% ignorado** (nem `notas_fiscais` nem `backup`; nenhum disco monitorado a 100%).
- **Mount morto não trava/zera** — os locais seguem reportados.
- **Só pseudo-FS → lista vazia, exit 0** (degrada, não morre).
- **Endpoint aceita payload sem discos / com `disks:[]` (200)** e **atualiza `last_seen`** (o
  watchdog não cega) — `ServersIngestTest`.
- **`--update` troca o binário e preserva o config** (token/URL intactos, via `file://` + seam
  `AGENT_BIN`); **recusa download inválido** (HTML) e **mantém** o binário antigo.
- `--version` → `2`. Regressão: os 140 testes de Servidores verdes (watchdog, partição,
  mensagens/{ip}/{grupo}, ciclo firing→resolved, transporte direto — intactos).

## Confirmações
- Produção/Nextgest/nginx/Tunnel intocados; 100% no DEV; `APP_ENV=local`.
- **Não** monitora CIFS/NFS/rede (só disco local por tipo); coletor **não** trava/morre por mount
  morto ou lista vazia; auth/validação do endpoint **não** enfraquecidas; transporte direto e
  partição/watchdog/rotação/{ip}/{grupo} **intactos**.
- Aditivo; sem destrutivo de git/schema; sem push. Endpoint servido já é a v2; php8.5-fpm recarregado.
