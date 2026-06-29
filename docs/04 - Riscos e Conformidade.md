# Riscos e Conformidade (msgautomation)

## Anti-ban (WhatsApp)
- Usa **WhatsApp Web nao-oficial** (Baileys, via Evolution). A Meta pode **banir** o numero.
- O numero e **pessoal do Fabio** -> banir doi de verdade. Por isso:
  - **Instancia isolada** da do Nextgest (D1): risco nao se cruza com os clientes pagantes.
  - Camadas futuras que **respondem** devem ter: limite de taxa, atrasos humanos, sem disparo em
    massa, sem mensagem nao solicitada. **Responder so a quem falou primeiro.**
- Camada 1 e a de menor risco: **so recebe**, nao envia nada (envio e stub, lanca excecao).

## LGPD
- Conteudo de mensagens pessoais e **dado pessoal** (as vezes sensivel). Hoje guardamos
  `raw_payload` integral + texto.
- Cuidados ja adotados: banco proprio, acesso restrito (dev local, sem internet), segredos fora do repo.
- A endereçar nas proximas camadas: retencao/expurgo de mensagens, minimizacao (guardar so o necessario),
  e base legal/consentimento se houver terceiros envolvidos. Nao compartilhar a base.

## Exposicao futura
- Hoje **nada exposto a internet** (localhost/LAN, firewall fechado pra fora, app em dev).
- Quando expor o webhook publicamente (prod), exigir: **HTTPS**, segredo forte (ja ha `hash_equals`),
  idealmente validacao adicional (IP allowlist/assinatura), rate limiting e WAF/reverse proxy.
- O `artisan serve` e **so dev**. Prod precisa de servidor real (php-fpm + Nginx) e processo
  supervisionado pro worker (systemd/supervisor).

## Operacional
- A sessao da Evolution **cai sozinha** as vezes -> monitorar (`evolution:status`) e reconectar (`evolution:qr`).
- Disco do servidor: data-root do Docker em `/srv/docker` (xvda3, folgado). A **raiz xvda2** vive
  perto de 80% — nao jogar dados grandes nela.
- Backup antes de qualquer operacao destrutiva (e operacoes destrutivas pedem um humano).
