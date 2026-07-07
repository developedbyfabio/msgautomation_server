# Servidores — separador editável de avisos agrupados + valores em pt-BR — 2026-07-07

Git no início: HEAD `aa3a92b`, branch `master`, remote `msgautomation_server`. **`APP_ENV=local`**
(DEV `192.168.11.210`, painel `:9100`). **Sem push.** Produção `187.127.24.165` / Nextgest /
nginx / Tunnel: **NÃO tocados**.

Testes: **1079 → 1087 verdes** (4207 → 4233 assertions; +8, zero regressão). **Commit: `ada83f7`.**
`queue:restart`: 2026-07-07 17:16:01 (tocou o job de alerta e o resolver). Worker/scheduler ativos.

## 1. Separador editável entre avisos agrupados

Quando o job agrupa vários avisos numa mesma mensagem de WhatsApp (anti-tempestade), o texto que
separa um do outro agora é **configurável por conta**.

- **Armazenamento (aditivo):** tabela nova `server_alert_settings` (`account_id` unique +
  `group_separator`), model `ServerAlertSetting`. `separatorFor($accountId)` devolve o separador
  ou o **default `"\n"`** (uma quebra de linha) quando não configurado/vazio.
- **Onde aplica:** `SendServerAlert::montarMensagem` — a junção passou de `implode("\n")` para
  `implode($separador)`. Como `implode` só insere o separador **entre** itens, **com um único
  aviso o separador não aparece** (comportamento pedido).
- **UI (tela Alertas, card "Separador dos avisos agrupados", owner-only):** botões de **preset**
  (Quebra de linha `\n` · Linha em branco `\n\n` · Traços `\n----------\n` · Asteriscos
  `\n**********\n`) que preenchem um **textarea livre** (o dono personaliza como quiser; Enter =
  quebra de linha), mais uma **prévia ao vivo** de 2 avisos agrupados. Botão "Salvar separador".
  Escolhi textarea + presets (em vez de só um select) porque cobre os presets pedidos **e** o
  "personalizado" sem ambiguidade, e a prévia mostra o efeito real (inclusive linhas em branco).

## 2. Valores das variáveis em português

Os modelos já eram pt-BR; o que vazava em inglês eram os **valores substituídos**. O
`AlertMessageResolver` agora traduz na renderização (mapas dedicados, distintos dos rótulos de
regra da tela) — aplicado **tanto no WhatsApp quanto na conversa "Alertas de Infraestrutura"**
(ambos passam pelo mesmo resolver):

- **`{metrica}`** (`METRIC_PT`): `cpu`→**CPU**, `ram`/`mem`→**memória**, `swap`→**swap**,
  `disk`→**disco**, `load`→**carga**, `watchdog`→**sem reportar**.
- **`{nivel}`** (`LEVEL_PT`): `warning`→**aviso**, `critical`→**crítico** (par escolhido por ser
  o mais claro e curto; registrado).
- `{servidor}` `{ip}` `{grupo}` `{valor}` `{particao}` seguem valores neutros.
- Legenda de variáveis na tela atualizada (`{metrica}` = "CPU / memória / swap / disco / carga /
  sem reportar"; `{nivel}` = "aviso / crítico"). Os rótulos das **regras** na tela (CPU, RAM,
  Swap, Disco, Load por núcleo, Sem reportar) foram mantidos — a tradução é dos **valores nas
  mensagens**, não dos rótulos de configuração.

Ajuste deliberado: o banner da tela Alertas estava desatualizado ("modo silencioso / o canal
liga na próxima fatia"). Como o canal já está ON, troquei por um banner **fiel ao flag**
(`SERVERS_NOTIFICATIONS_ENABLED`): ligado → "o alerta sai e re-avisa até normalizar"; desligado →
"modo silencioso".

## Testes (`ServersSeparadorEIdiomaTest`, 8)
- Separador **linha em branco** entre 2 avisos (`\n\n` presente); **traços** aparece **entre** os
  avisos (regex); **um aviso só → sem separador**; **default = `\n`** (não `\n\n`).
- `{metrica}`/`{nivel}` em pt-BR no **WhatsApp** ("disco", "crítico"; sem "critical"); tradução
  também na **conversa do Atendimento** ("memória", "aviso"; sem "warning"); `load`→"carga" e
  `watchdog`→"sem reportar" no resolver.
- **UI salva o separador** (owner; operador forjando → 403; preset "traços" seta o valor).
- Ajustadas 4 asserções de testes existentes que fixavam o texto antigo ("Disco"→"disco",
  "critical"→"crítico") — consequência legítima da tradução; os caminhos seguem verdes.

## Confirmação
- Produção/Nextgest/nginx/Tunnel intocados; 100% no DEV.
- **Não** mexi na cadência de re-aviso; transporte direto intacto; disco-por-partição, watchdog,
  rotação de mensagens, `{ip}`/`{grupo}`, ciclo firing→resolved — **sem regressão** (131 testes
  de servidores verdes).
- Migration aditiva aplicada e confirmada. `queue:restart` (17:16:01). Commit `ada83f7`, sem push.
