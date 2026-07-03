# Prompt 14 — Auto-download como opção na tela + imagem em modal (lightbox)

**Status: ENTREGUE.** Baseline 594 verdes → final **599 verdes** (2248 assertions),
`TenantIsolationTest` incluso. Migration aditiva aplicada em produção. Não mexe em reações,
reativo, envio, nem no download/rota da Fatia 2.

## Parte A — Auto-download de mídia como opção na tela

**Config POR CONTA** (o resto das settings já é por conta). Coluna nova
`auto_reply_settings.media_autodownload` — **nullable** de propósito:
- `null` = "nunca tocado na tela" → cai no **default do .env** (`INCOMING_MEDIA_DOWNLOAD`);
- `true`/`false` = escolha da tela, que **manda**.

**Precedência: tela > .env.** Documentado no helper `AutoReplySetting::mediaAutodownloadEnabled()`
(`media_autodownload ?? config('services.incoming_media.download', true)`). Em produção hoje:
`.env=true`, coluna `null` → efetivo **LIGADO** (comportamento da Fatia 2 preservado; nada muda
até o Fabio mexer no toggle).

**Onde:** um toggle "Baixar mídia recebida automaticamente" em **Configurações** (comportamento
do sistema, não do usuário), card próprio ao lado do kill switch, mesmo padrão de switch das
outras opções. É **instantâneo** (sem botão salvar, como o kill switch): `toggleMediaAutodownload`
grava a escolha explícita por conta na hora + toast.

**Gate:** o dispatch do `DownloadIncomingMedia` no inbound agora consulta
`settingsDe($account)->mediaAutodownloadEnabled()` (antes era só `config(...)`). Desligado → o job
**não** é disparado (fica o rótulo/thumbnail); ligado → dispara como na Fatia 2. Multi-tenant:
cada conta tem a sua; não vaza (teste de isolamento).

## Parte B — Imagem em modal/lightbox (não nova aba)

`flux:modal` é **Pro** (o próprio `x-modal` do app documenta "Flux modal e Pro, evitado") — segui
a convenção do time: **lightbox em Alpine + Tailwind puro**, sem round-trip Livewire (a URL já
está no DOM). Clicar na imagem recebida agora:
- **abre um overlay** (`fixed inset-0 bg-black/80`) por cima da conversa com a imagem grande
  centrada — **não** abre nova aba (troquei o `<a target="_blank">` por `<button @click>`);
- **fecha** no **X**, no **clique fora** (`@click.self` no backdrop) e no **ESC**
  (`@keydown.escape.window`);
- **carrega a imagem cheia só ao abrir**: o `<img :src="lightboxSrc">` do overlay só recebe a
  URL no clique (lazy — não baixa a cheia de todas as imagens da conversa de uma vez); a URL é a
  da rota escopada da Fatia 2 (`media.incoming` sem `?thumb`, quando já baixada; senão a
  miniatura ampliada);
- **desktop e mobile**: `max-h-[90vh] max-w-full object-contain` — cabe na tela sem rolagem
  horizontal; overlay não mexe no scroll interno nem no layout de altura fixa (prompt 09).
- Estado por conversa (`x-data="{ lightboxSrc: null }"` na `<section>`).

Bônus: adicionei a regra canônica `[x-cloak]{display:none!important}` no `app.css` — não existia,
então o overlay (e o popup de emoji) poderiam **piscar** no carregamento antes do Alpine iniciar.
Navegar entre imagens (setas prev/next) **não** foi feito (abre a imagem clicada) — anotado como
melhoria futura simples.

## Testes (599 verdes, +5)

- `MidiaAutodownloadToggleTest` (5): toggle persiste a escolha por conta; **desligado não
  dispara** o download / **ligado dispara** (via `Bus::fake([DownloadIncomingMedia::class])` +
  inbound real); precedência null→default do .env (config true dispara / false não); config por
  conta não vaza.
- `MidiaRecebidaThumbTest` (atualizado): imagem cheia baixada **abre no lightbox**
  (`lightboxSrc = '<rota cheia>'`, sem `target="_blank"`), miniatura por URL segue ok.
- Suite inteira verde; Fatia 2 (download/áudio/thumbnail) e reativo intactos.
- Build do Vite em foreground OK (classes do lightbox + `x-cloak` compiladas).

## Checklist de teste manual (Fabio)

Parte A:
a. Configurações → card "Baixar mídia recebida automaticamente": toggle reflete o estado atual
   (hoje LIGADO pelo default do .env); ligar/desligar é instantâneo (toast).
b. Desligar → mídia recebida nova **não baixa** (fica rótulo + miniatura da imagem); ligar de
   volta → volta a baixar.
c. A escolha persiste (recarregar a página mantém).

Parte B:
d. Clicar numa imagem recebida → abre **modal** com a imagem grande (não nova aba).
e. Fecha no **X**, no **clique fora** e no **ESC**.
f. A imagem cheia só carrega ao abrir (Network mostra o request só no clique).
g. Desktop e mobile: modal cabe, sem rolagem horizontal; conversa por baixo intacta.
h. Dark mode consistente.

## Precedência e escopo (resumo)
- **Config por conta** (`auto_reply_settings.media_autodownload`), não global.
- **Tela > .env**: coluna setada manda; `null` cai no `INCOMING_MEDIA_DOWNLOAD` (hoje `true`).
- Desligar via .env global ainda funciona como default pra contas que nunca tocaram o toggle.
