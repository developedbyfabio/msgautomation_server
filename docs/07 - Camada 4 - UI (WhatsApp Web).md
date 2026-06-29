# Camada 4 — UI (tipo WhatsApp Web)

Livewire 4 + Flux (free) + Tailwind v4. Tempo real por **polling** (`wire:poll`). Sem IA, sem
expor nada. O kill switch aparece na UI mas **comeca/continua OFF** — ligar e gate do Fabio.

## Acesso (Refino 2: LOGIN obrigatorio)
A UI roda via `php artisan serve --host=0.0.0.0 --port=8080`, acessivel na LAN
(`192.168.11.210:8080`). **Toda rota da UI esta atras de login** (S2): guard `web`, usuario
unico. Email padrao no `.env` (`AUTH_EMAIL`); a **senha** e definida pelo Fabio via
`php artisan msg:auth:senha` (input oculto, **hash no banco** — sem senha em texto). O seeder
(`db:seed --class=SingleUserSeeder`) so garante a existencia do usuario. Sair: header (`POST /logout`).
Se preferir voltar ao tunel SSH, fechar a 8080 pra LAN e manter o login e opcional.

## Como servir (com os assets buildados)
Porta canonica **8080**, via **systemd** (serve + worker persistentes — ver doc 03):
```
sudo systemctl status msgautomation-serve msgautomation-worker   # active (running)
npm run build                                                     # assets (NUNCA npm run dev)
```
O webhook da Evolution aponta pra `host.docker.internal:8080`. Trocou a porta -> rode
`php artisan evolution:setup` pra re-sincronizar, senao a ingestao para.

## Paginas
- **/conversas** — lista (agrupada por `remote_jid`, nome/ultima msg/hora, ponto verde = contato
  `on`) + thread (recebida / enviada-celular / enviada-manual / enviada-robo com cores distintas) +
  **envio manual** (caminho R1: envia de verdade, respeita tetos, ignora kill switch) + botoes
  **Aprovar/Silenciar** o contato. `wire:poll` 5s.
- **/contatos** — agenda auto-populada; por contato toggle **default/on/off**, editar nome/notas, busca.
- **/regras** — CRUD de regras; ativar/desativar; reordenar (priority); tipos exact/contains/starts_with.
- **/configuracoes** — **kill switch** proeminente (flip instantaneo), politica (allowlist/all), janela,
  tetos (intervalo/min/dia), rate por contato, delays, pular grupos, aquecimento.

## Notas
- Stack Flux: free traz so button/dropdown/icon/separator/tooltip; o resto da UI e Tailwind puro.
- Componentes Livewire class-based em `app/Livewire/` + views em `resources/views/livewire/`.

## Refino (modais, dropdowns, icones, status)
- **Zero dialogo nativo**: nenhum `confirm()`/`alert()`/`prompt()`/`wire:confirm`. Confirmacoes em
  **modal** (`<x-modal>` Alpine+Tailwind — Flux modal e Pro, evitado); feedback em **toast** global
  (Alpine ouve evento `toast` despachado pelos componentes).
- **Modais de confirmacao**: excluir regra; silenciar contato; e **LIGAR o kill switch** (com aviso).
  **Desligar o kill switch e INSTANTANEO** (sem modal) — freio de emergencia.
- **Modais de formulario**: criar/editar regra; editar contato (nome/notas).
- **Dropdowns** (`flux:dropdown`/`flux:menu`, free): kebab de acoes por item em conversas/contatos/
  regras (Editar/Ativar-Desativar/Excluir/Aprovar/Silenciar). `match_type`/`auto_reply_mode` seguem
  como `<select>` nativo (dropdown de form; `flux:select` e Pro).
- **Icones**: `flux:icon` (Heroicons, free) consistentes em nav, acoes e status.
- **UX**: empty states com icone, loading no envio (`wire:loading`), toasts, badges, avatar inicial.
- **Status vivo da conexao** (`StatusConexao`): poll leve do estado real (open/connecting/desconectado),
  sincroniza `channels.status` e oferece **Reconectar** -> modal com **QR** quando a sessao cai.
- Flux **free** apenas; modal/toast/badge/input/select feitos com Alpine+Tailwind.

## Refino 2 (S1–S7)
- **S1 Fuso:** storage segue em **UTC** (`config('app.timezone')`); a exibicao converte pra
  `America/Sao_Paulo` via `config('app.display_timezone')` (= `APP_TIMEZONE`) + macro
  `Carbon::paraExibicao()`. Corrige o "+3h" sem reinterpretar as linhas ja gravadas em UTC.
- **S2 Login:** ver "Acesso" acima.
- **S3 Conexao:** `StatusConexao` ganhou **Desconectar** (modal -> `DELETE /instance/logout/{inst}`,
  v2.3.7) e nao rebaixa o status em estado desconhecido. Pagina **/conexao** mostra o **QR**
  (`GET /instance/connect`), faz polling e segue pras conversas ao conectar; botao gerar novo QR.
  Middleware `whatsapp.connected` joga as paginas principais pro /conexao quando o canal esta
  `disconnected`. Tudo testado com **HTTP mockado** (sessao real nunca desconectada).
- **S4 Painel de contato:** clicar no nome abre drawer com avatar/nome/numero, toggle de
  auto-resposta, salvar **nome/notas** (flag `contacts.saved`) e **lista** de midias recentes
  (tipo+hora). Render real da midia = **fatia futura** (download/LGPD).
- **S5 Aprovar/Silenciar:** tooltips explicitos (Aprovar=on, Silenciar=off), badge do estado
  atual, vocabulario unificado com `/contatos`.
- **S6 Cara de WhatsApp:** bolhas (recebida clara/esq, enviada verde/dir), separadores de data
  (Hoje/Ontem/data), agrupamento, avatar colorido, hora relativa, tag de origem sutil, busca.
- **S7 Regras avancadas:** `rule_triggers` + `rule_responses` (ver doc 02). Multiplos gatilhos,
  multiplas respostas (sorteio **no envio**, anti-ban), placeholders (`{nome}`, `{saudacao}`,
  `{data}`, `{hora}`) e `regex` (validado + backtrack_limit reduzido). Modal de regra rico.
- **Login (C1):** a senha do usuario unico e definida pelo Fabio via `php artisan msg:auth:senha`
  (input oculto, hash no banco). Sem senha em texto no `.env`/repo. Seeder so garante a existencia.
- **Janela dos freios (C2):** `AntiBanGuard::withinWindow` avalia o "agora" em
  `America/Sao_Paulo` (so o fuso mudou; valores/tetos/rate intactos). Ex.: janela 08-20 -> 19:30 SP
  DENTRO, 21:00 SP FORA.
## Regras v2 (controle fino — ver schema na doc 02)
- **Modal corrigido (S1):** altura maxima 85vh, corpo rolavel, **Cancelar/Salvar fixos no rodape**
  (Salvar via `form="rule-form"`) — alcancavel mesmo com 15+ gatilhos. `x-modal` ganhou
  `maxWidth` + slot `footer`.
- **Frequencia por regra (S2):** por contato — `Padrao (rate global)` | `Sempre` | `1x por dia`
  (reset meia-noite SP) | `A cada N min`. **Composicao:** a frequencia da regra **substitui** o
  rate-por-contato global daquela regra; os **tetos de volume** (intervalo minimo, por minuto, por
  dia) **continuam valendo** como piso. Rastreio em `auto_reply_logs`.
- **Escopo por contato (S3):** `global` (todos aprovados) ou `contatos` (lista). Filtrado no matcher
  antes do match; prioridade decide entre as elegiveis.
- **Testador / dry-run (S4):** botao **Testar** em /regras — digita mensagem (+ contato opcional) e
  ve qual regra/gatilho casaria, a resposta resolvida e se algum freio bloquearia. **Nao envia** nem
  mexe em contadores.
- **Precisao por gatilho (S5):** `exato` (default) ou `tolerante a erros` (baixa/media/alta).
  Levenshtein por token whole-word; guarda-corpos: token < 4 chars = exato, folga escala com o
  tamanho (teto baixo). Calibravel no Testador. Default seguro = exato.
- Pendente: paginacao, websockets no lugar do polling; render real de midia; variantes Pro do Flux
  nao usadas.

## Controle e transparencia dos freios
- **Tooltips "i"** (`<x-info-tip>`, Flux tooltip acessivel) em cada campo de `/configuracoes`.
- **Toggle liga/desliga por freio** (`<x-freio-toggle>`): janela, intervalo minimo, teto/minuto,
  teto/dia, intervalo por contato. Desligado = nao bloqueia (estado visivel). `skip_groups`/aquecimento
  seguem como checkbox. **Guardas estruturais** (fromMe, idempotencia) sao **sempre ativos, nao
  desligaveis, mas VISIVEIS** com explicacao (transparencia).
- **Testador (dry-run)** lista **todos os freios** com passa/bloqueia/desligado
  (`AntiBanGuard::breakdown`).
- **Rotulos pt-BR** dos tipos de match (`RuleMatcher::typeLabel`): Contem / Mensagem exata / Comeca com
  / Regex (avancado). "Rate por contato" renomeado para **Intervalo por contato** e passou a ler o
  valor atual (sem cache stale).
- Selecao de contatos do escopo por **checkbox buscavel** (nao mais `<select multiple>`).

## Conversas — completude (display)
- **Ir para a ultima mensagem (S1):** botao flutuante (chevron-down) aparece so quando rolado pra
  cima; auto-scroll ao chegar msg nova SO se ja estava no fim (Alpine + MutationObserver).
- **Nome do grupo (S4):** lista e cabecalho mostram o subject (cache em `groups`, resolvido em
  background) em vez do JID numerico.
- **Icones por tipo (S5):** imagem/video/audio/documento(+nome)/figurinha/localizacao/contato/
  enquete com Heroicon + label; reacao = emoji real; texto normal; desconhecido = `[tipo]`.
- **Captura 100% (S6):** normalizador catch-all; todos os tipos chegam e aparecem (a captura ja
  funcionava — o gap era exibicao crua, agora resolvida).
- **Freios (S2/S3):** toggles cabem no card; testador separa "Intervalo por contato (global)" de
  "Frequencia desta regra" com texto claro.

## Cofre de senhas (/senhas)
- Aba **Senhas** (ao lado de Contatos): CRUD do cofre. Valor **cifrado em repouso** (chave dedicada
  `SECRETS_KEY`), **mascarado** na UI; **revelar** exige re-digitar a senha de **login** (1 por vez,
  transiente). Ver schema/seguranca na doc 02.
- Uso na regra: picker **inserir senha** (lista nomes, nunca valores) -> `{senha:nome}` na resposta.
  **Resolucao no envio** (em memoria); o log grava `[senha: nome]`, nunca o valor.
- **Guarda de escopo:** regra com `{senha:...}` exige **Contatos Especificos** + match estrito
  (bloqueia "Todos os Aprovados" e gatilho tolerante).
- **Testador** mascara a senha por padrao (revelar deliberado, nao persiste).
- **Transporte (recomendacao):** a rede e HTTP — acessar o cofre por **tunel SSH** ou pôr **HTTPS**
  (login e valor revelado cruzam a LAN sem cifra). A confirmar como fatia propria.
