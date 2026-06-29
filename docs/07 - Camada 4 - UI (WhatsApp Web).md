# Camada 4 — UI (tipo WhatsApp Web)

Livewire 4 + Flux (free) + Tailwind v4. Tempo real por **polling** (`wire:poll`). Sem IA, sem
expor nada. O kill switch aparece na UI mas **comeca/continua OFF** — ligar e gate do Fabio.

## Acesso (dev, por tunel SSH)
A 8190 fica em `172.17.0.1` (fechada pra LAN). Acesse por tunel:
```
ssh -L 9000:172.17.0.1:8190 usuario@192.168.11.210
```
Depois abra `http://localhost:9000`. Assets e endpoints do Livewire sao **relativos** (funcionam
sob o host do tunel). **Nao** reabrir pra LAN/internet. **Sem login** (dev).

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
- Pendente: busca na lista de conversas, paginacao, websockets no lugar do polling, autenticacao se
  sair do tunel; variantes Pro do Flux (modal/toast/table) nao usadas de proposito.
