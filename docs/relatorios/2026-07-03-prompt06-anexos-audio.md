# Fila noturna — Prompt 06: Anexos parte C — enviar ÁUDIO — 2026-07-03

Status: **VERDE. 577/577 (baseline 572/572 do prompt 05).** Texto, imagens,
PDF e pipeline reativo intocados; smoke pós-deploy ok.

## O que mudou

**Contrato `ChannelProvider` ganhou `sendAudio`** — com a FONTE ABSTRATA que o
prompt pediu (ver "ponto de extensão" abaixo):
- **Evolution**: endpoint PRÓPRIO de áudio da v2.3.7
  (`POST /message/sendWhatsAppAudio/{instance}`, base64) — chega como mensagem
  de voz no WhatsApp.
- **Cloud API**: upload → media_id → `POST /messages` com `type=audio` (id
  puro — a Meta NÃO aceita caption nem filename em áudio). Reusa os helpers
  `uploadMedia`/`sendMediaMessage` da parte B. Mesma visibilidade de erro.

**Sender**: `media.kind = 'audio'` despacha pro `sendAudio`; freios/janela/log
idênticos (janela de 24h no cloud vale pra áudio, provado por teste).

**UI /conversas**: o mesmo clipe aceita áudio (mp3/m4a/aac/ogg/amr). Preview
com PLAYER antes de enviar (formatos que o Livewire não pré-visualiza, ex.
ogg, caem num card com ícone + nome — sem quebrar) + Cancelar. Na conversa, o
áudio enviado aparece como bolha com player (`<audio controls>` apontando pra
rota autenticada/escopada). **Áudio não leva legenda** (limitação do WhatsApp
nos dois canais): o hint explica e o texto digitado FICA no composer pra ser
enviado como mensagem separada — nada é descartado em silêncio.

**Validação de formato (o cuidado do prompt — nunca enviar o que o canal
rejeita)**: aceita só a interseção segura com os formatos da Meta — aac,
mp3/mpeg, m4a/mp4, amr, ogg(-opus) — máx 10 MB (teto oficial da Meta pra áudio
é 16 MB; 10 fica sob o limite de upload do Livewire sem mexer em mais infra).
Formato fora disso (ex.: flac, wav) é RECUSADO com mensagem clara. Conversão
de formato não foi implementada (exigiria ffmpeg no servidor) — registrado
como melhoria futura junto do áudio-robô.

## Ponto de extensão pro ÁUDIO-ROBÔ futuro (preparado, não implementado)
`sendAudio(Channel, to, filePath, mime)` recebe um caminho de arquivo local
QUALQUER — nada assume que veio da UI. O caminho completo do robô de amanhã:
um serviço TTS grava o arquivo em `storage/app/private/media/{conta}/...` e
chama `Sender::send($mode, $channel, $jid, '', media: ['kind' => 'audio',
'path' => $rel, 'mime' => ...])` — ganhando DE GRAÇA freios, R2, janela de
24h, log em `auto_reply_logs` (com player na conversa) e isolamento por conta.
Nenhuma porta fechada.

**Gravação pelo navegador (MediaRecorder)**: NÃO entrou (o plus opcional) —
gravaria em webm/opus, que a Meta não aceita como áudio; faria par com a
conversão via ffmpeg. Documentado como fatia futura ("gravar áudio" =
MediaRecorder + conversão server-side pra ogg-opus).

## Testes (5 novos — AnexoAudioTest)
Evolution: endpoint sendWhatsAppAudio com base64 real + persistência + player
na thread + texto digitado PERMANECE no composer (áudio sem legenda);
flac recusado antes de qualquer HTTP; Cloud: upload → media_id → type=audio
SEM caption (duas etapas verificadas); fora da janela 24h bloqueado; conta A
recebe 404 no áudio da B. **Suíte completa: 577/577 (2.125 assertions).**
`TenantIsolationTest` intacto.

Aprendizado de teste registrado: `UploadedFile::fake()->create()` (arquivo
esparso) chega com conteúdo VAZIO no fluxo de upload de teste do Livewire —
áudio fake agora usa `createWithContent` com bytes reais, deixando o assert de
base64 significativo.

## Checklist de teste manual pro Fabio
1. /conversas → clipe → escolher um mp3 → player de preview → Enviar → chega
   como mensagem de voz no WhatsApp.
2. Bolha com player na conversa; persiste após F5.
3. Anexar .wav ou .flac → recusa clara com a lista de formatos aceitos.
4. Regressão: texto, imagem e PDF continuam ok.

## Deploy
Sem migration nova. `npm run build`; restart serve+worker; smoke ok.

**Fila 01–06 COMPLETA — resumo da noite em
`2026-07-03-resumo-noite-prompts01-06.md`.**
