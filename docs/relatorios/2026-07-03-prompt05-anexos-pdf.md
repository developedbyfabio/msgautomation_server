# Fila noturna — Prompt 05: Anexos parte B — PDF/documentos — 2026-07-03

Status: **VERDE. 572/572 (baseline 567/567 do prompt 04).** Texto, imagens
(parte A) e pipeline reativo intocados; smoke pós-deploy ok (login 200,
serviços ativos).

## O que mudou

**Contrato `ChannelProvider` ganhou `sendDocument`** (nome ORIGINAL do arquivo
faz parte do contrato — é o que o destinatário vê no WhatsApp):
- **Evolution**: mesmo `POST /message/sendMedia/{instance}` da parte A com
  `mediatype=document` + `fileName` original. O `sendImage` virou wrapper do
  núcleo comum `sendMedia` (refatoração interna, zero mudança de comportamento
  — suite da parte A intacta).
- **Cloud API**: as duas etapas viraram helpers compartilhados
  (`uploadMedia` → media_id; `sendMediaMessage` type=image|document) —
  documento envia `document: {id, filename, caption}`. Erros com a mesma
  visibilidade da parte A (log → /logs; falha assíncrona já coberta pelo 02).

**Sender**: `media` ganhou `kind` (image|document) e `name`; despacho pro
transporte certo. Freios/janela/log idênticos — mesma disciplina.

**Schema (migration ADITIVA)**: `auto_reply_logs.media_name` (nome original;
o path no disco é uuid).

**UI /conversas**: o MESMO clipe agora aceita imagem OU documento
(`$anexo` unificado — a propriedade `$foto` da parte A foi renomeada, testes
ajustados). Documento: card de preview com ícone + nome + tamanho em KB +
Cancelar; legenda opcional. Na thread, documento enviado aparece como card
clicável com o nome original (abre/baixa pela rota autenticada/escopada, com
filename correto no download). Validação com mensagem clara:
- Imagem: jpg/jpeg/png/webp, máx 5 MB (inalterado).
- Documento: **PDF** (obrigatório do prompt) + docx/xlsx (saíram de graça na
  mesma mecânica), máx **10 MB** — folga sob os tetos dos canais (Meta aceita
  documento bem maior, mas 10 MB fica sob o limite de upload do Livewire e do
  transporte base64 da Evolution sem mexer em mais infra).

## Infra (deploy) — limite de upload do PHP
O `php artisan serve` roda com o php.ini da CLI (2M/8M) — nem a imagem de 5 MB
da parte A passaria. Corrigido com **drop-in systemd REVERSÍVEL**
(`/etc/systemd/system/msgautomation-serve.service.d/uploads.conf`:
`-d upload_max_filesize=25M -d post_max_size=30M`; apagar o arquivo +
daemon-reload volta ao original). Unit de referência em `deploy/systemd/`
documenta o drop-in. Verificado ativo pós-restart.

## Testes (5 novos — AnexoDocumentoTest + 1 ajuste na parte A)
Evolution: mediatype document + fileName original + persistência + card na
thread; >10 MB recusado (nada enviado); Cloud: upload multipart → media_id →
type=document com filename e caption (duas etapas verificadas); fora da janela
de 24h bloqueado sem HTTP; isolamento: conta A recebe 404 no documento da B.
Ajuste na parte A: o teste de "tipo inválido" usava PDF — trocado por
executável (PDF virou tipo aceito). **Suíte completa: 572/572 (2.099
assertions).** `TenantIsolationTest` intacto.

## Checklist de teste manual pro Fabio
1. /conversas → clipe → escolher um PDF → card com nome/tamanho → legenda →
   Enviar → chega no WhatsApp como documento com o nome certo.
2. Card do documento na conversa abre/baixa o PDF com o nome original.
3. PDF acima de 10 MB → recusa clara; .exe/tipo estranho → recusa clara.
4. Regressão: enviar texto e imagem continuam funcionando.

## Deploy
Migration aditiva aplicada; drop-in de upload criado + daemon-reload;
`npm run build`; restart serve+worker; smoke ok.

Próximo da fila: **06 — Anexos parte C (áudio).**
