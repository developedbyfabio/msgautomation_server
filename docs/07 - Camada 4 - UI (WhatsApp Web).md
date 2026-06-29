# Camada 4 — UI (tipo WhatsApp Web)

Livewire 4 + Flux (free) + Tailwind v4. Tempo real por **polling** (`wire:poll`). Sem IA, sem
expor nada. O kill switch aparece na UI mas **comeca/continua OFF** — ligar e gate do Fabio.

## Acesso (Refino 2: LOGIN obrigatorio)
A UI roda via `php artisan serve --host=0.0.0.0 --port=8080`, acessivel na LAN
(`192.168.11.210:8080`). **Toda rota da UI esta atras de login** (S2): guard `web`, usuario
unico com credenciais **no `.env`** (`AUTH_EMAIL`/`AUTH_PASSWORD`), nunca em repo. Criar/atualizar
o usuario: `php artisan db:seed --class=SingleUserSeeder`. Sair: botao no header (`POST /logout`).
Se preferir voltar ao tunel SSH, fechar a 8080 pra LAN e manter o login e opcional.

## Como servir (com os assets buildados)
```
php artisan serve --host=172.17.0.1 --port=8190   # ja roda em background no dev
npm run build                                       # assets (NUNCA npm run dev aqui)
```

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
  Obs.: a **janela** dos freios (AntiBanGuard) ainda compara em UTC — fora do escopo deste refino
  (nao mexer em freios); a confirmar com o Fabio se a janela deve passar pra SP.
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
- Pendente: paginacao, websockets no lugar do polling; render real de midia; janela dos freios em
  SP (a confirmar); variantes Pro do Flux nao usadas de proposito. **Kill switch real = OFF.**
