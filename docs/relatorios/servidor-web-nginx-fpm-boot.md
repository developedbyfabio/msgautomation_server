# Servidor web (nginx + php8.5-fpm) na :9100 + daemons no boot — 2026-07-07

Git no início: HEAD `4f8746d`, branch `master`, remote `origin` (`msgautomation_server`).
**`APP_ENV=local`** (DEV `192.168.11.210` / hostname `laravel-dev`). **Sem push.**
Produção `187.127.24.165` / `painel.nextgest.com.br` / Nextgest / nginx de produção /
Cloudflare Tunnel / 2FA: **NÃO tocados** (trabalho 100% no DEV).

Suíte: **1087 verdes / 4233 assertions** (baseline 1087 — zero regressão). Toquei só o `.env`
(`APP_URL`), nenhum código de app. `queue:restart` executado. `php8.5-fpm` recarregado.

---

## ⚠️ No topo — o que depende de você (nada bloqueia o que já está no ar)

O painel + webhook **já estão de pé na :9100 e sobem no boot** (via instância nginx dedicada,
detalhe abaixo). Os itens abaixo são melhorias/decisões suas, **não** bloqueadores:

1. **Token órfão do agente LOCAL deste box.** O `msgautomation-agent` local (config em
   `/etc/msgautomation-agent/config`, IP `192.168.11.210`) usa um token que **não resolve pra
   nenhum servidor cadastrado** → o webhook devolve `401` a cada ~30s e o `agent.service` fica
   `failed`. **Isso é pré-existente e não tem a ver com o nginx.** Os **dois servidores realmente
   monitorados** (`10.40.132.19` e `10.40.132.2`) reportam **200 normalmente**. Decisão sua:
   - Se este box (`laravel-dev`) **deve** se auto-monitorar → cadastre/re-emita o token dele na
     tela **Servidores** e grave o novo token no `/etc/msgautomation-agent/config`; ou
   - Se **não** deve → `systemctl disable --now msgautomation-agent.timer` (para o ruído).
   - **Não** rotacionei token sozinho: rotação mexe em dado de app e poderia derrubar o report
     dos dois servidores que hoje funcionam. Fica pra você.

2. **Consolidar num único nginx (opcional).** Preferi uma **instância nginx dedicada** ao
   msgautomation pra **não tocar** no site `desenvLaravel` (de outro projeto) nem no apache. Se
   você quiser um nginx só servindo tudo, veja "Opção de consolidação" no fim. **Não é
   necessário** — o atual funciona e sobe no boot.

3. **nginx principal segue `failed` no boot (pré-existente, não é meu).** O `nginx.service`
   padrão está `enabled` mas `failed` há semanas porque o site `desenvLaravel` quer a **:80**, já
   ocupada pelo **apache**. **Não mexi** nele. Isso não afeta o msgautomation (que roda em
   instância separada), mas gera um serviço `failed` no boot que você pode querer resolver algum
   dia (trocar a porta do `desenvLaravel` ou desabilitar o symlink).

---

## Inspeção (antes de instalar)

| Item | Achado |
|------|--------|
| **nginx** | Instalado (1.18.0), `enabled` mas **`failed`** desde 23/06 — `bind() :80` falha porque o **apache** ocupa a :80. Config em si é válida (`nginx -t` ok). |
| **apache2** | `enabled` + `active`, dono da **:80** (6 workers). É de outro uso da máquina — **não tocado**. |
| **php-fpm** | Rodando 8.2 / 8.3 / 8.4. **8.5-fpm NÃO instalado** — mas a app roda em PHP **8.5.8** (CLI). Constraint do composer: `^8.3`. |
| **:9100** | **Livre** (nada escutando). Instalar aqui é aditivo, sem colisão. |
| **:8080** | Livre. Era a porta do `artisan serve` (serviço `msgautomation-serve`, parado há 4 dias). `APP_URL` ainda apontava pra cá — realinhei. |
| **Sites nginx** | `sites-enabled/desenvLaravel` → `/srv/www/laravel/desenvLaravel`, **listen 80**, fpm 8.3. Config de **outro projeto** — **não alterada**. |
| **Outro projeto vivo** | `engeinsights` em `php artisan serve :8100` (manual). **Intacto.** |
| **Agente** | `AGENT_URL=http://192.168.11.210:9100/webhook/servers/ingest`. Falhava com `connection refused` (nada na :9100). |
| **Daemons** | `worker` (`--queue=default`), `scheduler` (`schedule:work`), `agent.timer` — todos `enabled`. Fila de alerta = `default` (mesma do worker; sem fila dedicada). |

Conclusão da inspeção: instalar `php8.5-fpm` + subir nginx na **:9100** é **100% aditivo** e não
colide com nada em uso (a :9100 estava livre; apache/desenvLaravel/engeinsights não são tocados).

## Web — nginx + php8.5-fpm servindo o msgautomation na :9100

- **`php8.5-fpm` instalado** (8.5.8, idêntico ao CLI — mesmas extensões da app). Pool `www-data`,
  socket `/run/php/php8.5-fpm.sock`. `enabled` + `active`.
- **Instância nginx dedicada** (`nginx-msgautomation.service`, config
  `/etc/nginx/msgautomation-standalone.conf`): escuta **:9100**, root
  `/srv/www/msgautomation/public`, passa PHP pro `php8.5-fpm` via socket, regras Laravel
  (`try_files`, `fastcgi`). **Isolada**: não usa o `nginx.service` principal, não toca
  `desenvLaravel` nem apache. Contorna o conflito :80 de vez. Versionada em `deploy/nginx/`.
- **Permissões**: o php-fpm roda como `www-data`; os daemons (worker/scheduler) rodam como `root`.
  Usei **ACL** (`setfacl`, incl. default ACL) em `storage` e `bootstrap/cache` pra os dois
  usuários coexistirem sem quebrar logs. E dei **leitura do `.env` pro `www-data`** — este era o
  motivo do `500`: o `.env` era `600 root`, o fpm (www-data) não lia, a app caía em defaults
  (`env=production`, sem `APP_KEY`) → `MissingAppKeyException`. Fix durável: `.env` passou a
  `root:www-data 640` (+ ACL de reforço) — o grupo sobrevive a edições in-place; **continua sem
  leitura pra "outros"** (segredos protegidos). ⚠️ Gotcha: editar o `.env` com ferramenta que
  reescreve o inode **derruba a ACL** (aconteceu ao ajustar o `APP_URL`); o grupo `www-data` é a
  garantia — se o `www-data` voltar a não ler o `.env`, rode `chgrp www-data .env && chmod 640 .env`.
- **`artisan serve` aposentado**: `msgautomation-serve.service` (:8080) → `disable --now`
  (`disabled` + `inactive`). `APP_URL` realinhado pra `http://192.168.11.210:9100`.

### Prova (tudo via nginx+fpm, não `artisan serve`)

| Alvo | Resultado |
|------|-----------|
| `GET /` | **302 → /login** (redirect de auth correto) |
| `GET /login` | **200**, HTML da app com formulário (`password`/`_token`) |
| Livewire `GET /livewire-…/livewire.js` | **200 `application/javascript`** |
| `POST /webhook/servers/ingest` (sem token) | **401** deliberado da app (rota chega no controller) |
| **Agentes remotos** `10.40.132.19` e `10.40.132.2` | **200** — ingestão real gravando `last_seen` |

O monitoramento **dependia** do :9100: os dois servidores remotos recebiam `connection refused`
antes; agora recebem `200`. O `500` inicial (todos os IPs) era o `.env` ilegível — resolvido.

## Daemons (estado final)

| Serviço | enabled | active | Restart |
|---------|---------|--------|---------|
| `nginx-msgautomation` | enabled | active | **always** |
| `php8.5-fpm` | enabled | active | on-failure *(default do pacote; reinicia em queda)* |
| `msgautomation-worker` | enabled | active | **always** |
| `msgautomation-scheduler` | enabled | active | **always** |
| `msgautomation-agent.timer` | enabled | active | — *(timer; cadência OnUnitActiveSec=30s)* |
| `msgautomation-serve` (aposentado) | **disabled** | inactive | — |

## Boot

Validado por `systemctl is-enabled` + `is-active` (tabela acima) — **sem reboot**. Um reboot
derrubaria o `engeinsights` (:8100, `artisan serve` **manual**, não sobe sozinho), o `tmux` e a
própria sessão de trabalho; o dono pediu pra não reiniciar se afetar outros projetos, então
**não reiniciei**. Tudo que precisa está `enabled`; na próxima reinicialização o apache pega a
:80 e a instância dedicada pega a :9100, sem colisão. (Ressalva: o `nginx.service` principal
continuará `failed` no boot pelo motivo pré-existente da :80 — item 3 no topo.)

## Opção de consolidação (só se você quiser um nginx só)

1. Liberar a :80 pro nginx principal ou tirar o `desenvLaravel` de lá: trocar o `listen 80` do
   `desenvLaravel` por outra porta **ou** `rm /etc/nginx/sites-enabled/desenvLaravel` (o arquivo
   em `sites-available` fica preservado — reversível).
2. `ln -s /etc/nginx/sites-available/msgautomation /etc/nginx/sites-enabled/` (o server block
   `:9100` do msgautomation pro nginx principal já está em `sites-available`, criado nesta fatia).
3. `systemctl disable --now nginx-msgautomation` e `systemctl restart nginx`.

## Confirmações

- **Produção / Nextgest / nginx de produção / Cloudflare Tunnel / 2FA: intactos.** 100% no DEV.
- **Outros projetos da máquina intactos**: apache (:80), site `desenvLaravel`, `engeinsights`
  (:8100) — nenhuma porta/config/processo deles foi alterado.
- Pacotes instalados (aditivo): `php8.5-fpm`, `acl`. Novos: `nginx-msgautomation.service`,
  `/etc/nginx/msgautomation-standalone.conf`, ACLs em `storage`/`bootstrap/cache`, e `.env`
  passado a `root:www-data 640`.
- `APP_ENV=local` confirmado; `APP_URL` → :9100. Suíte 1087 verdes; `queue:restart` feito. Sem push.
