# Fila noturna — Prompt 04: Anexos parte A — enviar IMAGENS — 2026-07-03

Status: **VERDE. 567/567 (baseline 562/562 do prompt 03).** Pipeline reativo e
envio de texto intocados (suíte antiga passou sem mudança de expectativa; smoke
pós-deploy: login 200, webhook token inválido 401, serviços ativos).

## O que mudou

**Contrato `ChannelProvider` (CH-1) ganhou `sendImage`** — transporte puro,
freios continuam no Sender, cada provider com a sua mecânica:
- **EvolutionProvider**: `POST /message/sendMedia/{instance}` com a mídia em
  BASE64 (`mediatype=image`, mimetype, caption, fileName) — contrato v2.3.7.
- **CloudApiProvider**: DUAS etapas da Meta — upload multipart pro
  `/{phone_number_id}/media` (→ `media_id`) e depois `POST /messages` com
  `type=image` referenciando o id (+caption). Erros mapeados sem vazar token;
  o `Log::warning` de falha vira evento no /logs (sinergia com o prompt 02), e
  a falha ASSÍNCRONA (statuses failed com code) já era persistida pelo 02.

**Sender**: parâmetro opcional `media ['path','mime']` no MESMO `send()` —
claim/freios/R2/janela/log idênticos (anexo NÃO fura teto). Com anexo, o
transporte troca `sendText` → `sendImage`; o texto (resolvido/redigido como
sempre) vira legenda. Janela de 24h do cloud vale pra mídia igual ao texto
(o check 3d existente cobre; provado por teste — fora da janela nem o upload
acontece).

**Schema (migration ADITIVA)**: `auto_reply_logs` += `media_path`,
`media_mime` (nullable).

**UI /conversas**: botão clipe → escolher imagem → validação NA HORA
(jpg/jpeg/png/webp, máx 5 MB — limite de imagem da Meta; recusa com mensagem
clara) → preview com Cancelar → o texto digitado vira legenda → Enviar (ou
Enter, do prompt 03). Bolha da conversa renderiza a imagem enviada (thumb
clicável) e persiste no histórico. GIF ficou de fora de propósito (a Meta trata
GIF como vídeo, não imagem — registrado como horizonte da parte B/C).

**Armazenamento e serving**: disco privado `local`
(`storage/app/private/media/{account_id}/{numero}/{uuid}.{ext}`) — path POR
CONTA, nome aleatório (nada sensível no nome). Rota autenticada
`GET /media/{logId}` resolve o log com a QUERY ESCOPADA por conta DENTRO da
closure (binding implícito rodaria antes do SetAccountContext — aprendizado
registrado): mídia de outra conta = 404, nunca vaza.

## Testes (5 novos — AnexoImagemTest, HTTP sempre mockado)
Evolution: sendMedia com base64/caption/mime + persistência + aparece na
thread; validação: PDF e >5 MB recusados com erro claro e anexo descartado
(nada enviado); Cloud: upload multipart → media_id → mensagem type=image com
caption (as DUAS etapas verificadas); Cloud fora da janela de 24h: bloqueado
`janela_24h` sem nenhum HTTP; isolamento: usuário da conta A recebe 404 na
mídia da B, dono da B recebe 200. **Suíte completa: 567/567 (2.073
assertions).** `TenantIsolationTest` intacto.

## Checklist de teste manual pro Fabio
1. /conversas → clipe → escolher foto → preview aparece → digitar legenda →
   Enviar (ou Enter) → imagem chega no WhatsApp com a legenda.
2. Imagem aparece na bolha da conversa e continua lá após F5.
3. Tentar anexar um PDF ou imagem >5 MB → mensagem de recusa clara.
4. No canal Cloud (número de teste), enviar imagem DENTRO da janela → chega;
   se a Meta recusar algo, o erro aparece em /logs (code legível).

## Deploy
Migration aditiva aplicada; `npm run build`; restart serve+worker; smoke ok.

Próximo da fila: **05 — Anexos parte B (PDF/documentos).**
