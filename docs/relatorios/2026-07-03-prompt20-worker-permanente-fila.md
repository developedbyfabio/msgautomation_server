# Prompt 20 — Worker permanente da fila (mídia recebida) via serviço de sistema — 2026-07-03

**Status: ENTREGUE (objetivo já atendido; endurecido).** O worker permanente **já existia** como
serviço systemd — a premissa "só roda com `queue:work` no terminal" estava desatualizada. Confirmei,
adicionei o `--timeout` explícito que faltava, revalidei ponta a ponta e documentei a operação.
Nenhum código do app foi tocado: **suíte 614 verdes**. Nextgest/nginx/tunnel intactos.

## Passo 1 — Achados

1. **Connection e filas:** `QUEUE_CONNECTION=redis` (predis, `127.0.0.1:6380`, `REDIS_PREFIX=msgauto_`).
   `REDIS_QUEUE` não setado → fila **`default`**. Nenhum job usa `onQueue`/connection custom →
   **todos** vão pra `default`. Jobs em fila (não é só mídia): `ProcessIncomingWhatsappMessage`
   (o inbound/reativo!), `SendAutoReply`, `ClassifyWithAi`, `SendProactiveMessage`, `ResolveGroupName`,
   `DownloadIncomingMedia` e o listener `UpdateKanbanFromEvent`. Ou seja, **o worker sustenta o
   pipeline reativo inteiro**, não apenas o download. `retry_after=90` (redis) → o `--timeout` do
   worker precisa ficar **abaixo de 90**.
2. **Path/usuário:** `/srv/www/msgautomation`, roda como `root` (mesmo dono dos outros serviços do
   projeto; lê o `.env` do projeto via `WorkingDirectory`).
3. **Já existia serviço?** SIM — `msgautomation-worker.service` (systemd), criado no deploy de
   02/07. Também existem `msgautomation-serve` e `msgautomation-scheduler`. O **Nextgest** usa
   **supervisor** (`nextgest-worker_00/01`) — projeto separado, **não tocado**.
4. **`queue:work` manual (tmux)?** NÃO — o único `queue:work` do msgautomation em execução era o
   **próprio serviço** systemd (PID gerenciado). Sem worker órfão pra encerrar; sem concorrência.

## Passo 2 — Ferramenta escolhida: **systemd**
Mantido systemd (nativo do Ubuntu, já em uso pelos 3 serviços do msgautomation). Não migrei pro
supervisor (que é do Nextgest) pra não misturar projetos nem duplicar. A unit já tinha
`Restart=always`, `RestartSec=2`, `StartLimitIntervalSec=0`, `enabled` (boot), user/path corretos,
`--max-time=3600` (recicla de hora em hora: memória + pega código novo), `--tries=3`.

**Única lacuna vs. o prompt:** `--timeout` não estava explícito (usava o default 60s). Adicionei
`--timeout=60` (cobre o pior caso do download — metadados 20s + binário 30s ≈ 50s — e fica **< 90**
do `retry_after`, evitando reprocessar job ainda em execução). Backup da unit anterior salvo em
`/root/msgautomation-worker.service.bak-*` (reversível).

### Conteúdo do serviço (sem segredos — lê o `.env` via WorkingDirectory)
Versionado como exemplo em `deploy/systemd/msgautomation-worker.service`:
```ini
[Unit]
Description=msgautomation queue worker (Laravel)
After=network-online.target docker.service
Wants=network-online.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=root
WorkingDirectory=/srv/www/msgautomation
ExecStart=/usr/bin/php /srv/www/msgautomation/artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=60 --max-time=3600
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

## Passo 3 — Subida e validação
- `systemctl daemon-reload` + `systemctl restart msgautomation-worker` → `enabled` + `active`; **um
  único** worker (sem duplicata) com `--timeout=60` no comando.
- **Processa com o terminal fechado (o objetivo):** enfileirei via tinker um `DownloadIncomingMedia`
  real (msg id 3850, `media_path` null) e **em ~2s** o serviço processou — `media_status=stored`,
  `media_path=media/incoming/1/554199020028/<uuid>.jpg`, arquivo no disco (`image/jpeg`). Nenhum
  `queue:work` de terminal envolvido (é systemd, sem tmux atachado).
- **Best-effort intacto:** só a unit systemd mudou; o código do `DownloadIncomingMedia` (try/catch →
  `media_status=failed` + `SystemEvent` em /logs, sem propagar) não foi tocado — coberto pela suíte
  (teste `test_falha_de_download_nao_derruba_e_loga_evento`), que segue verde.

## Fora de escopo — confirmado intacto
- **Nextgest:** `nextgest-worker_00/01` seguem `RUNNING` (supervisor, não tocado).
- **nginx:** `active`. **cloudflared** (tunnel): `active`. **Não** tocados.
- `msgautomation-serve` e `msgautomation-scheduler`: seguem `active` (só o worker foi reiniciado).

## Passo 4 — Comandos de operação
```bash
# status / saúde
systemctl status msgautomation-worker
systemctl is-active msgautomation-worker && systemctl is-enabled msgautomation-worker

# logs (journald)
journalctl -u msgautomation-worker -f              # ao vivo
journalctl -u msgautomation-worker --since "1 hour ago"

# parar / iniciar / reiniciar o serviço
systemctl stop msgautomation-worker
systemctl start msgautomation-worker
systemctl restart msgautomation-worker

# após editar a unit
systemctl daemon-reload && systemctl restart msgautomation-worker

# NO FLUXO DE DEPLOY (pra o worker pegar código novo):
php artisan queue:restart          # sinaliza o worker a reiniciar após o job atual
# (o --max-time=3600 também recicla sozinho de hora em hora, como rede de segurança)
```
Observação de deploy: `queue:restart` é a forma correta de aplicar código novo ao worker (o
`queue:work` mantém o app em memória; sem restart ele rodaria o código antigo até reciclar).

## Resultado
- Serviço systemd `msgautomation-worker`: **enabled + active**, restart automático, boot, fila
  `default` (reativo + mídia), `--timeout=60 --max-time=3600`, lê o `.env`, roda 24/7 sem terminal.
- **Suíte: 614 verdes** — código do app intocado (só infra systemd + relatório + exemplo versionado).
