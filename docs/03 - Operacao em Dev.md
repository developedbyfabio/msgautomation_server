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

### Servir (porta que o container alcanca)
```
php artisan serve --host=0.0.0.0 --port=8190
```
`0.0.0.0` e necessario pra o container da Evolution alcancar via `host.docker.internal`.

### Worker da fila (Redis)
Em outro terminal:
```
php artisan queue:work redis
# para testes pontuais: php artisan queue:work redis --stop-when-empty
```

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
- URL (o container -> host): `http://host.docker.internal:8190/webhook/evolution`.
- Origem validada pelo header `X-Webhook-Secret` (segredo no `.env`, `hash_equals`).
- Fluxo: recebe -> valida -> enfileira -> 200; o job persiste com idempotencia.

## Segredos
- Tudo no `.env` (app) e `docker/evolution/.env` (Evolution), ambos `chmod 600` e **gitignored**.
- Nunca colar segredos em chat/log/commit. Use as ferramentas do Laravel (leem o `.env` sozinhas).

## Testes e build
```
php artisan test           # sequencial (sqlite :memory:); NAO usar --parallel
npm run build              # foreground; NUNCA npm run dev
```
