# RESUMO DA NOITE — Fila 01–06 COMPLETA — 2026-07-03

**Estado final: 577/577 verdes (2.125 assertions). Baseline da noite: 544.**
Todos os 6 prompts entregues, cada um commitado em verde com relatório
próprio. Webhook Evolution e pipeline reativo INTOCADOS (smoke após cada
deploy: login 200, webhook token inválido 401, serviços ativos).

Nota: a sessão caiu UMA vez, no meio do prompt 02 — retomada do estado
parcial sem perda (detalhe no relatório do 02).

## O que foi entregue (na ordem)

| # | Entrega | Commit | Suíte | Relatório |
|---|---|---|---|---|
| 01 | 2FA TOTP (Fortify) + página /perfil (e-mail/senha/2FA) | `93d5e93` | 554 | prompt01-2fa-perfil.md |
| 02 | Página /logs + status FAILED da Meta persistido (fim do buraco do 130497) | `84a36c6` | 560 | prompt02-pagina-de-logs.md |
| 03 | Conversas: Enter envia, Ctrl/Shift+Enter quebra, textarea cresce, emojis (+seletor) | `057581a` | 562 | prompt03-conversas-input.md |
| 04 | Anexos A: imagens (Evolution sendMedia base64; Cloud upload→media_id) | `eff92b0` | 567 | prompt04-anexos-imagens.md |
| 05 | Anexos B: PDF/docx/xlsx com nome original + drop-in de upload (25M) | `087cac2` | 572 | prompt05-anexos-pdf.md |
| 06 | Anexos C: áudio (Evolution sendWhatsAppAudio; Cloud type=audio) + base pro áudio-robô | (este) | 577 | prompt06-anexos-audio.md |

Migrations (todas ADITIVAS, aplicadas): two_factor em users; system_events;
auto_reply_logs += media_path/media_mime/media_name.

## PENDÊNCIA pro Fabio de manhã

1. **`git push` NÃO foi feito** — o classificador de permissões do agente
   bloqueou push direto pra master (sem force; commits todos locais).
   Rodar `git push origin master` na mão (5 commits à frente do origin).
2. **Ativar o 2FA da sua conta** (roteiro no relatório do prompt 01).
3. **Checklists de teste manual** (front não coberto por PHPUnit) nos
   relatórios 03, 04, 05 e 06 — 5 min no painel cobrem tudo.
4. Infra nova pra conhecer: drop-in
   `/etc/systemd/system/msgautomation-serve.service.d/uploads.conf`
   (limites de upload do serve, 25M/30M — reversível apagando o arquivo)
   e mídia enviada em `storage/app/private/media/{conta}/...`.

## Registros técnicos que valem releitura

- **Binding implícito de rota roda ANTES do SetAccountContext** — a rota
  /media/{logId} resolve o model DENTRO da closure com query escopada
  (prompt 04). Vale pra qualquer rota futura com model escopado.
- Fortify registra POST /logout duplicado — `route:cache` passaria a falhar
  (não usamos; aviso no relatório do 01).
- Emojis: banco já era utf8mb4 ponta a ponta — nenhuma migration necessária.
- Meta: GIF é vídeo (fora da parte A); áudio não tem caption/filename;
  conversão de formato (ffmpeg) e MediaRecorder ficaram como fatia futura
  junto do áudio-robô (ponto de extensão documentado no relatório do 06).
- `UploadedFile::fake()->create()` esparso chega VAZIO no upload de teste do
  Livewire — usar `createWithContent` quando o conteúdo importa.

## O que NÃO foi tocado (de propósito)
Canal Evolution vivo, fluxo reativo, freios/jaula das proativas, IA, Kanban,
cofre S5 — tudo como estava. `TenantIsolationTest` (gate) verde em todas as
etapas e ESTENDIDO pelos testes de isolamento de cada prompt (logs, mídia).
