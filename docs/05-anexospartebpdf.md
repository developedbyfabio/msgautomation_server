# 05 — Anexos, Parte B: enviar PDF / documentos

Segunda fatia de anexos. Reaproveita quase tudo da Parte A (imagens); a diferenca e o tipo de midia
(documento) e os detalhes de cada canal. So faca isto depois que a Parte A estiver verde e commitada.

Releia o `00-LEIA-PRIMEIRO.md`. Baseline: git limpo, ler docs/09 e docs/10, `php artisan test` verde.

## Objetivo
Permitir anexar e enviar **documentos** pelas conversas — foco em **PDF** (e, se sair de graca com a
mesma logica, tipos comuns tipo docx/xlsx; mas o obrigatorio e PDF).

## Requisitos
- Reutilizar a infraestrutura de anexos da Parte A (botao de anexar, storage por conta, preview,
  fluxo de envio por ChannelProvider). Adicionar o **tipo documento**.
- UI: ao anexar, mostrar o nome do arquivo + tamanho (documento nao tem thumbnail de imagem; mostrar
  um card/icone de PDF com o nome). Legenda/caption opcional.
- Validar tipo (application/pdf no minimo) e tamanho (respeitar limites do canal; a Meta tem limite
  de tamanho pra documento — checar e validar).
- Envio por canal:
  - **Evolution**: endpoint de documento do Evolution v2.3.7.
  - **Cloud (Meta)**: mesma mecanica de midia — upload em `/{phone_number_id}/media` -> media_id ->
    enviar mensagem tipo `document` com o media_id e o filename. Respeitar a **janela de 24h**.
- Persistir no historico da conversa (aparece como documento enviado, com nome do arquivo).

## Cuidados
- Isolamento por conta no storage e exibicao. `TenantIsolationTest` gate.
- Nao quebrar texto nem imagens (Parte A) nem o pipeline reativo.
- Erro de envio no Cloud com visibilidade (status/erro legivel, sem falha silenciosa).

## Testes
- Upload de PDF valido persiste e aparece como documento na conversa.
- Tipo invalido / tamanho acima do limite recusado com mensagem clara.
- Envio Evolution vs Cloud (upload -> media_id -> tipo document), mockando o Graph.
- Fora da janela 24h no Cloud, bloqueia.
- Isolamento entre contas.
- Regressao: imagens (Parte A) e texto continuam funcionando.
- Suite completa verde.

## Ao terminar
Verde: commita ("feat: anexos parte B — enviar PDF/documentos (Evolution + Cloud)"), push sem force,
relatorio em docs/relatorios/. Passa pro `06`.
Quebrou: PARA no ultimo verde, relata. Nao segue.

