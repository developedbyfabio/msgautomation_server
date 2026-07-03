# 04 — Anexos, Parte A: enviar IMAGENS pelas conversas

Primeira fatia de anexos. **So imagens** nesta parte (foto). PDF e audio vem nas partes B e C, pra
manter cada mudanca pequena e testavel. Nao tente fazer os tres de uma vez.

Releia o `00-LEIA-PRIMEIRO.md`. Baseline: git limpo, ler docs/09 e docs/10, `php artisan test` verde.

## Contexto importante: dois canais, envio diferente
O envio de midia e **diferente em cada canal**, e isso e o cerne da complexidade:
- **Evolution API**: tem endpoint proprio pra enviar midia (base64 ou URL, conforme a versao v2.3.7).
- **Cloud API (Meta)**: envio de midia e em duas etapas — primeiro faz **upload** da midia pro
  endpoint `/{phone_number_id}/media` (recebe um media_id), depois envia a mensagem referenciando esse
  media_id. Alem disso, no canal Cloud a **janela de 24h** vale pra midia tambem (fora da janela,
  free-form/midia e bloqueado — respeitar o mesmo tratamento que ja existe pro texto).

Portanto: a UI de anexar imagem e uma so, mas o **ChannelProvider** de cada canal implementa o envio
do seu jeito. Respeite o contrato `ChannelProvider` que ja existe (CH-1) e adicione a capacidade de
enviar imagem de forma que cada provider trate a sua mecanica.

## Requisitos de UI (aba Conversas)
- Botao de **anexar** (clipe) na caixa de mensagem -> escolher imagem do dispositivo.
- Tipos aceitos: jpg, jpeg, png, webp (e talvez gif). **Validar tipo e tamanho** (limite razoavel,
  ex.: 5 MB — checar o limite do canal; a Meta tem limites por tipo de midia, respeitar).
- **Preview** da imagem antes de enviar, com opcao de cancelar.
- Legenda opcional (caption) junto da imagem.
- Ao enviar, a imagem aparece na conversa (do lado do enviado) e persiste no historico.
- Respeitar os freios/tetos e o comportamento do envio manual atual (mesma logica de "envia de
  verdade") — anexo nao e desculpa pra furar teto.

## Armazenamento
- Definir onde a midia enviada fica guardada (storage local do app, com path por conta/conversa —
  respeitando multi-tenant e nao vazando entre contas). Nao guardar segredos; nomes de arquivo nao
  devem expor dados sensiveis.
- Servir a preview/thumbnail de forma segura (nao publicar publicamente midia de uma conta pra outra).

## Cuidados (nao quebrar)
- Nao mexer no pipeline reativo nem no envio de texto que ja funciona.
- Isolamento por conta em tudo (upload, storage, exibicao). `TenantIsolationTest` gate.
- No Cloud, tratar erro de envio de midia com a mesma visibilidade do prompt 02 (se a Meta recusar,
  registrar status/erro legivel — nada de falha silenciosa).

## Testes
- Upload de imagem valida persiste e aparece na conversa.
- Tipo invalido / tamanho acima do limite e recusado com mensagem clara.
- Envio pelo Evolution usa o caminho do Evolution; envio pelo Cloud faz upload -> media_id -> envia
  (mockar o Graph nos testes; verificar as duas etapas).
- No Cloud, fora da janela de 24h, envio de imagem e bloqueado igual ao texto (nao tenta free-form).
- Isolamento: conta A nao acessa midia da conta B.
- Suite completa verde.

## Ao terminar
Verde: commita ("feat: anexos parte A — enviar imagens (Evolution + Cloud com upload/media_id)"),
push sem force, relatorio em docs/relatorios/. Passa pro `05`.
Quebrou: PARA no ultimo verde, relata. Nao segue.
