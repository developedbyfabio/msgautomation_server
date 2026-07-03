# Prompt 13 — Mídia recebida, Fatia 2: baixar/armazenar/servir imagem cheia e áudio + thumbnail por URL

**Status: ENTREGUE (completo, os dois canais).** Baseline 585 verdes → final **594 verdes**
(2236 assertions), `TenantIsolationTest` incluso. Migration aditiva aplicada em produção.

O ponto de maior risco (endpoint de mídia da Evolution) foi **validado ao vivo** — funciona.
O pipeline reativo não foi tocado no caminho crítico: o download roda em **job separado,
best-effort**.

## Arquitetura (como não arrisca o reativo)

O `ProcessIncomingWhatsappMessage` (inbound) continua igual no caminho de resposta. Depois de
persistir a mensagem, se ela for imagem/áudio **e** a config estiver ligada, ele apenas
**despacha um job** `DownloadIncomingMedia` e segue — o reativo (casar regra, responder) **nunca
espera nem depende** do download. O job:
- roda na fila (redis, worker já existente), fora do request;
- é **best-effort**: qualquer falha (HTTP, base64 inválido, tamanho) é capturada, marca
  `media_status='failed'`, loga um `SystemEvent` (`midia_download_falhou`, visível em /logs, no
  espírito do prompt 02) e **nunca propaga** — não derruba mensagem nem resposta;
- é idempotente (se `media_path` já existe, não rebaixa);
- contexto de conta explícito (ids escalares, sem serializar model); path escopado por conta.

Gate por config `services.incoming_media.download` (default **true** em produção; **false** nos
testes via phpunit.xml, pra não disparar HTTP no inbound da suite).

## Frente 1 — baixar/armazenar/servir

**Cloud (Meta):** `CloudApiProvider::fetchIncomingMedia` lê o `media_id` do payload
(`...messages.0.{type}.id`), faz GET Graph `/{version}/{media_id}` (token no header) → recebe
`{url, mime_type, file_size}`, checa o teto de tamanho, e baixa o binário dessa URL (token de
novo no header). Token **nunca** na URL nem em log. Testado com mock (Graph + lookaside).
*(Não há mídia Cloud recebida no ambiente — número de teste sem histórico — então o Cloud foi
validado só por mock; o contrato segue a doc da Meta.)*

**Evolution:** usei o **endpoint próprio da instância** `POST /chat/getBase64FromMediaMessage/{instance}`
(passando a `key` da mensagem), que devolve a mídia **já decodificada em base64** — evitei
reimplementar a descriptografia `.enc`/`mediaKey` do WhatsApp (menos risco, era a dúvida do
prompt). **VALIDADO AO VIVO** contra o servidor do Fabio via tinker:
- imagem real: **141.411 bytes, image/jpeg** (assinatura FFD8FF ✓);
- áudio real: **20.738 bytes, audio/ogg** ✓ (mime normalizado, tira `; codecs=opus`).

**Armazenar:** disco privado `disk('local')`, path `media/incoming/{conta}/{numero}/{uuid}.{ext}`
(escopado por conta). Colunas aditivas novas em `incoming_messages`: `media_path`, `media_mime`,
`media_name`, `media_status`. **Migration rodou em produção** (aditiva, nada removido/alterado).

**Servir:** rota nova `GET /media/incoming/{id}` (dentro do grupo `auth`, escopada por conta):
`IncomingMessage::findOrFail` passa pelo escopo de conta → mídia de outra conta = **404**, nunca
vaza. Content-Type vem de `media_mime`. Testado: dono recebe o binário; outra conta 404.

## Frente 2 — render (imagem cheia + player de áudio)

No `thread()`, itens de mídia recebida agora carregam `in_kind` (image|audio), `full_url`
(quando baixado) e `thumb_url` (miniatura por URL). No blade:
- **imagem**: mostra a miniatura (leve) como preview **clicável** que abre a imagem cheia
  (`full_url`) em nova aba; enquanto o download não terminou, mostra a miniatura + "Prévia
  (imagem completa em breve)".
- **áudio**: `<audio controls preload="none">` apontando pra rota — toca no player do sistema.
- o rótulo "Imagem"/"Áudio" redundante é suprimido quando a mídia renderiza; a legenda é
  preservada. Layout de altura fixa (prompt 09) intacto; tamanhos contidos (sem rolagem
  horizontal, desktop e mobile).

## Frente 3 — thumbnail por URL (resolve o trade-off da Fatia 1)

A miniatura embutida (`jpegThumbnail`) **não é mais base64 inline** no HTML. A rota
`/media/incoming/{id}?thumb=1` serve os bytes do thumbnail extraídos on-the-fly
(`MessagePreview::thumbnailBinary`), escopada por conta. O `thread()` só emite a **URL**
(`MessagePreview::hasThumbnail` faz uma checagem barata de presença, sem reconstruir bytes).
Resultado: o HTML do `wire:poll.5s` fica leve (só `<img src=URL>`), sem os megabytes de base64
que pesavam em conversa densa de imagens.

## Restrições respeitadas

- Reativo intocado no caminho crítico; download best-effort não-bloqueante (validado: teste
  `test_inbound_nao_quebra_quando_download_falha`).
- Migration **aditiva** apenas. Isolamento por conta em download, path e rota (404 cross-account).
- Mídia **enviada** não foi tocada (rota `/media/{logId}` intacta).
- Segredos (token Meta, apikey Evolution) só via credenciais do canal / config; nunca logados,
  nunca na URL. Teto de tamanho (20 MB, configurável) + timeouts (20s/30s) no download.

## Testes (594 verdes, +9)

- `MidiaRecebidaDownloadTest` (8): Cloud imagem/áudio (media_id→Graph→binário→disco), Evolution
  imagem/áudio (getBase64 endpoint, mime normalizado), fail-safe (falha → status failed +
  SystemEvent, sem lançar), wiring inbound (falha de download não quebra o inbound), rota serve
  pro dono + 404 pra outra conta, rota thumb serve JPEG + 404 sem thumb.
- `MidiaRecebidaThumbTest` (atualizado): thumbnail agora por URL (não base64 inline), imagem
  cheia baixada vira link, sem-thumb/sem-download cai no rótulo, isolamento por conta.
- `MessagePreviewTest`: `thumbnailBinary`/`thumbnail`/`hasThumbnail` (formas + rejeições).
- `ChannelProviderTest`: dublê atualizado pro método novo do contrato.
- HTTP sempre mockado nos testes; Evolution validado ao vivo por fora (tinker).

## Checklist de teste manual (Fabio)

a. **Imagem recebida (Evolution):** abrir conversa → miniatura aparece; clicar abre a imagem
   **cheia** (o worker baixa em segundo plano; some o "Prévia" quando pronta). Validei que o
   download funciona no seu servidor (imagem 141 KB, áudio 20 KB).
b. **Áudio recebido:** aparece um **player** — dá pra ouvir. `preload="none"` (só baixa ao tocar).
c. **Cloud:** quando chegar mídia no número oficial, mesma coisa por media_id (validado por
   mock; confirmar no primeiro recebimento real).
d. **Thumbnail por URL:** no DevTools, o HTML da conversa (e do poll de 5s) **não tem mais**
   `data:image/jpeg;base64,...` gigante — só `<img src=".../media/incoming/{id}?thumb=1">`.
e. **Isolamento:** abrir a URL de uma mídia logado em outra conta → 404.
f. **Reativo intacto:** mandar mensagem que casa regra → robô responde normalmente (o download
   de mídia não interfere).
g. Desktop e mobile: imagem/player cabem no balão, sem rolagem horizontal.

## Observações / decisões

- **Caminho Evolution = endpoint próprio** (não descriptografia manual), validado ao vivo.
- **Auto-download LIGADO em produção** (config default true + migration aplicada): a próxima
  mídia recebida já dispara o job. Se quiser desligar, `INCOMING_MEDIA_DOWNLOAD=false` no .env.
- Escopo desta fatia: **imagem e áudio**. Vídeo/documento/sticker recebidos ainda caem no rótulo
  (o mecanismo já suporta estender: só ampliar `mediaCategory()` e o render).
- Vídeo do worker: os jobs vão pra fila redis (worker `msgautomation-worker`). Se o worker
  estiver parado, os downloads ficam na fila até ele rodar (sem perda, sem impacto no reativo).
- Commit único (feito verde a verde internamente; tudo testado + Evolution validado ao vivo).
