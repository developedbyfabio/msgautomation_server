# 06 — Anexos, Parte C: enviar AUDIO

Terceira e ultima fatia de anexos. O Fabio disse que no futuro vai integrar **audio-robo** (geracao
de audio automatica), entao esta parte tambem prepara o terreno pra isso — mas o escopo AGORA e so
enviar audio manualmente pela conversa. So faca depois que A e B estiverem verdes e commitadas.

Releia o `00-LEIA-PRIMEIRO.md`. Baseline: git limpo, ler docs/09 e docs/10, `php artisan test` verde.

## Objetivo
Permitir anexar e enviar **audio** pelas conversas (arquivo de audio; gravacao pelo navegador e um
plus opcional). Deixar a base pronta pra que, no futuro, um audio gerado por robo use o mesmo caminho
de envio.

## Requisitos
- Reutilizar a infra de anexos (A/B): botao de anexar -> escolher/gravar audio, storage por conta,
  fluxo por ChannelProvider.
- UI: player de preview do audio antes de enviar, com opcao de cancelar. Ao enviar, aparece como
  mensagem de audio na conversa (com player).
- (Opcional, se sair limpo) gravar audio direto no navegador (MediaRecorder + Alpine) — se for
  complexo demais, deixa so upload de arquivo de audio e documenta o "gravar" como fatia futura.
- Tipos/formatos: aceitar os formatos de audio que **cada canal suporta**. Atencao: a Meta (Cloud)
  tem formatos especificos aceitos pra audio (ex.: aac, mp3, ogg opus, amr...) e limite de tamanho —
  validar contra isso. O Evolution tem o proprio endpoint de audio (v2.3.7). Se o formato de origem
  nao for aceito pelo canal, ou converter (se trivial) ou recusar com mensagem clara — **nao** enviar
  algo que o canal vai rejeitar.
- Envio por canal:
  - **Evolution**: endpoint de audio do Evolution.
  - **Cloud (Meta)**: upload em `/{phone_number_id}/media` -> media_id -> mensagem tipo `audio`.
    Respeitar janela de 24h.
- Persistir no historico.

## Base pro audio-robo futuro (so preparar, nao implementar geracao)
- Estruture o envio de audio de forma que a **fonte** do audio seja abstrata: hoje vem de upload/gravacao
  do usuario; amanha pode vir de um arquivo gerado por um servico de TTS/robo. Ou seja, o metodo de
  enviar audio no ChannelProvider deve receber um arquivo/stream de audio, sem assumir que veio da UI.
  Nao implemente TTS agora — so nao feche a porta pra ele. Documente esse ponto de extensao no relatorio.

## Cuidados
- Isolamento por conta. `TenantIsolationTest` gate.
- Nao quebrar texto, imagens (A) nem PDF (B), nem o pipeline reativo.
- Erro de envio no Cloud com visibilidade (sem falha silenciosa).

## Testes
- Upload de audio em formato aceito persiste e aparece com player.
- Formato nao aceito pelo canal e recusado (ou convertido) com mensagem clara.
- Envio Evolution vs Cloud (upload -> media_id -> tipo audio), mockando o Graph.
- Fora da janela 24h no Cloud, bloqueia.
- Isolamento entre contas.
- Regressao: texto, imagens, PDF continuam funcionando.
- Suite completa verde.

## Ao terminar
Verde: commita ("feat: anexos parte C — enviar audio (Evolution + Cloud) + ponto de extensao p/ audio-robo"),
push sem force, relatorio em docs/relatorios/. **Esta e a ultima da fila** — no fim, escreve um
relatorio-resumo em docs/relatorios/ listando tudo que foi entregue na noite (prompts 01 a 06), o
estado da suite, e qualquer item que ficou pendente ou parado pro Fabio ver de manha.
Quebrou: PARA no ultimo verde, relata.
