# Operacao em Dev (msgautomation)

Tudo em `192.168.11.210`, localhost/LAN, sem internet. Projeto em `/srv/www/msgautomation`.

## 1. Subir a infra Docker (2a Evolution + Redis do app)
```
cd /srv/www/msgautomation/docker/evolution
docker compose up -d
docker compose ps          # tudo "healthy"
```
- Evolution: `http://127.0.0.1:8090` (manager em `/manager`).
- Redis do app: `127.0.0.1:6380`.
- Para parar: `docker compose stop` (NAO usar `down -v`, apaga volumes).

## 2. App Laravel
```
cd /srv/www/msgautomation
php artisan migrate          # cria as tabelas (idempotente)
php artisan db:seed          # conta-ancora + channel da instancia
```

### Porta canonica: 8080 (serve + worker via systemd)
A porta canonica do app e **8080**. O webhook da Evolution aponta pra
`http://host.docker.internal:8080/webhook/evolution` (o container resolve `host.docker.
internal` -> gateway `172.17.0.1` e alcanca a 8080 do host). **Se mudar a porta, atualize
o webhook junto** (`.env` `EVOLUTION_WEBHOOK_URL` + `php artisan evolution:setup`), senao a
ingestao para silenciosamente.

Serve e worker rodam sob **systemd** (sobrevivem a queda da sessao e a reboot) — copias de
referencia dos units em `deploy/systemd/`:
```
sudo cp deploy/systemd/msgautomation-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now msgautomation-serve.service msgautomation-worker.service
systemctl status msgautomation-serve msgautomation-worker     # active (running)
journalctl -u msgautomation-worker -f                          # acompanhar a fila
```
- `msgautomation-serve`: `artisan serve --host=0.0.0.0 --port=8080` (UI na LAN, **atras de
  login** — ver doc 07). Se preferir tirar da LAN, bindar `172.17.0.1:8080` e usar tunel SSH.
- `msgautomation-worker`: `artisan queue:work --queue=default --tries=3 --max-time=3600`
  (consome a fila `default` no Redis; recicla de hora em hora). `Restart=always`.
- Escopo dos units = **so os processos do app**. Nao tocam em firewall/rede/seguranca do host.

## 3. Configurar a instancia da Evolution
```
php artisan evolution:setup    # cria a instancia (se faltar) + configura o webhook MESSAGES_UPSERT
php artisan evolution:status   # estado da conexao + ultimas mensagens recebidas
```

## 4. Conectar o numero (GATE — precisa do celular do Fabio)
```
php artisan evolution:qr       # salva o QR em storage/app/qr/<instance>.png (+ pairing code)
```
Escanear no WhatsApp do celular: **Aparelhos conectados -> Conectar aparelho**.
A sessao pode cair sozinha; reconectar com `evolution:qr` e checar com `evolution:status`.

## 5. Webhook
- URL (o container -> host): `http://host.docker.internal:8080/webhook/evolution`.
- Origem validada pelo header `X-Webhook-Secret` (segredo no `.env`, `hash_equals`).
- Fluxo resiliente: recebe -> valida -> **enfileira** (Redis) -> 200; o **worker sempre no ar**
  (systemd) persiste com idempotencia. **Nada e processado no request.**
- Conferir a URL registrada na Evolution: `php artisan evolution:status` (ou findWebhook).
- Diagnostico rapido de "parou de receber": (1) `evolution:status` mostra a URL/porta certa?
  (2) `systemctl is-active msgautomation-worker`? (3) `curl -s localhost:8080/up`?
  (4) ultima `incoming_messages.received_at` recente?

## Segredos
- Tudo no `.env` (app) e `docker/evolution/.env` (Evolution), ambos `chmod 600` e **gitignored**.
- Nunca colar segredos em chat/log/commit. Use as ferramentas do Laravel (leem o `.env` sozinhas).

## Testes e build
```
php artisan test           # sequencial (sqlite :memory:); NAO usar --parallel
npm run build              # foreground; NUNCA npm run dev
```
