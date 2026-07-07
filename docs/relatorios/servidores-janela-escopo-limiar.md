# Servidores — janela por destinatário + servidores por destinatário + limiar por servidor — 2026-07-07

Git no início: HEAD `157f7b3`, branch `master`, remote `origin` (`msgautomation_server`).
**`APP_ENV=local`** (DEV `192.168.11.210`, painel `:9100`). **Sem push.** Produção
`187.127.24.165` / Nextgest / nginx / Tunnel: **NÃO tocados**. Fuso das janelas:
**America/Sao_Paulo** (servidor roda UTC; o offset é tratado na avaliação).

Suíte: **1096 → 1112 verdes** (4298 assertions; +16, zero regressão). Toquei o job
`SendServerAlert` → **`queue:restart`** feito (worker pid 581323); componente/model servidos
por fpm → **php8.5-fpm recarregado**. **Commit feature: `53ec866`.** Migration aditiva aplicada.

---

## ⚠️ No topo — lembretes de fatias anteriores (ação sua nos servidores)

1. **Atualizar o coletor** nos servidores já instalados (laravel-dev, YELLL, **API-ECOMMERCE**)
   pra pegar a correção de disco (mount morto/CIFS/nunca-zerar). Uma linha, preserva token/timer:
   ```
   curl -fsSL http://192.168.11.210:9100/servidores/agente/coletor.sh -o /tmp/msgautomation-agent.new && chmod 755 /tmp/msgautomation-agent.new && sudo mv /tmp/msgautomation-agent.new /usr/local/bin/msgautomation-agent
   ```
   Depois disso, updates futuros = `sudo msgautomation-agent-update`.
2. **Agente local do laravel-dev usa token órfão (401)** — não resolve pra nenhum servidor.
   Regenere o token dele na tela **Servidores**, ou desligue `systemctl disable --now
   msgautomation-agent.timer` neste box. (Os servidores remotos reportam normal.)

---

## Feature 1 — Janela de horário por destinatário

Cada destinatário define **quando** recebe (fuso **America/Sao_Paulo**):
- **`window_mode`**: `24h` (padrão, sempre) ou `custom` (`window_start`–`window_end`, `HH:MM`).
- **`weekends`**: recebe sábado/domingo? — checado **independentemente** da janela de horário.
- `AlertContact::withinWindow($now)`: converte o instante (UTC) para BRT, corta fim de semana se
  desligado, e compara `HH:MM` (trata janela que **cruza a meia-noite**, ex.: 22:00–06:00).

**Semântica de descarte-com-registro (decisão do dono):** fora da janela, o
`SendServerAlert` **pula aquele contato** (`continue`) — o WhatsApp dele é **suprimido, sem
acúmulo** (não recebe "depois"). Mas o **incidente** (aba Incidentes) e a mensagem na conversa
**"Alertas de Infraestrutura"** são gravados **upstream** pelo `AlertNotifier`/`IncidentManager`,
independentemente do envio. Ou seja: **o fato nunca se perde** — só a entrega no WhatsApp daquele
contato fora de hora é descartada. Supressão **por destinatário** (A na janela recebe, B fora não,
para o mesmo alerta).

## Feature 2 — Quais servidores cada destinatário recebe

- **`server_ids`** (JSON) com a seleção; **NULL/vazio = todos** (padrão — preserva os
  destinatários atuais). Precede o alvo legado (`server_id`/`grupo`), que segue válido para linhas
  antigas não editadas. `AlertContact::coversServer()` resolve: seleção › legado › todos.
- UI: seletor **"Todos os servidores" ↔ "Escolher servidores"** com multi-seleção (checkboxes) do
  inventário. Ao salvar em modo seleção, o alvo legado é limpo (a UI passa a usar `server_ids`).

**Regra combinada de roteamento (explícita e testada):** um alerta vai a um destinatário **só se**
(a) o escopo dele **cobre o servidor** E (b) o nível **≥ `min_level`** E (c) está **dentro da
janela/fim de semana**. Falhando qualquer uma, o contato não recebe — mas o incidente segue
registrado.

## Feature 3 — Limiar por servidor (já no motor da S2; exposto na UI)

O motor já existia (regra com `server_id` vence a global; `enabled=false` silencia a métrica no
servidor) e a **UI já expõe** (tela Alertas, seletor de servidor): `Sobrescrever` por métrica,
edição de limiares/histerese, indicação **`padrão global` vs `sobrescrita`**, e **"remover
sobrescrita"** (volta ao padrão). Coerente com a seleção de **partição por servidor** (disco por
partição, já existente). Esta fatia **cobriu com testes** o caso de uso real (servidor que vive no
limite): sobrescrever o disco dele para 95% não alerta a 92%, enquanto os outros seguem o global.
Nenhuma mudança no padrão global ao sobrescrever (isolado por servidor).

## Migration (aditiva)

`2026_07_07_000010_add_window_and_scope_to_alert_contacts`: em `server_alert_contacts` adiciona
`window_mode` (default `24h`), `window_start`, `window_end`, `weekends` (default `true`),
`server_ids` (JSON null). **Defaults = comportamento atual** (24h + fim de semana + todos) — os
destinatários existentes não mudam.

## Testes (`ServersJanelaEscopoLimiarTest` +14, `ServersAlertasUiTest` +2/1 atualizado)

- **F1:** janela 08–18 recebe às 10h; **às 3h não envia, mas o incidente é registrado e a mensagem
  aparece na conversa "Alertas de Infraestrutura"**; fim de semana desligado não recebe no sábado
  (ligado recebe); **fuso** (20h UTC = 17h BRT cai dentro de 08–18); dois contatos, um na janela
  outro fora → só o da janela recebe.
- **F2:** escopo "só servidor X" recebe de X, não de Y; "todos" recebe de qualquer; **combinada**
  (escopo E nível E janela) em 4 casos.
- **F3:** sem sobrescrita usa o global; disco sobrescrito 95% não alerta a 92% enquanto o global
  (90%) alertaria em outro servidor; "voltar ao padrão" remove a sobrescrita (volta a herdar).
- **UI:** escopo selecionado grava `server_ids` e limpa o alvo legado; seleção vazia é rejeitada;
  janela custom persiste início/fim + fim de semana; operador forjando → 403 (inalterado).

## Confirmações
- **Não** perde o registro do incidente ao suprimir fora da janela (incidente + conversa
  preservados); **não** quebra destinatários atuais (default 24h + todos); **não** mexe no padrão
  global ao sobrescrever (isolado por servidor).
- Preservados sem regressão: disco por partição, watchdog + precedência, histerese/debounce, ciclo
  firing→resolved, re-aviso por cadência, rotação de mensagens/`{ip}`/`{grupo}`, separador, valores
  pt-BR, **transporte direto**, conversa "Alertas de Infraestrutura". Pipeline de atendimento/
  matching/billing sem diff.
- `APP_ENV=local`; migration aditiva; sem destrutivo de git/schema; sem push. `queue:restart`
  (worker 581323); php8.5-fpm recarregado.
