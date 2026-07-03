# 02 — Pagina de Logs / Eventos no painel

**Por que isso importa:** a noite inteira o Fabio teve que pedir pro agente "escuta o log e me
diz o que chegou". Isso nao escala. Esta pagina existe pra que ele veja sozinho, no painel, os
eventos relevantes e os erros — principalmente falhas de envio que hoje passam despercebidas
(ex.: o erro 130497 da Meta que ficou escondido a noite toda porque status `failed` era ignorado).

Releia o `00-LEIA-PRIMEIRO.md`. Baseline: git limpo, ler docs/09 e docs/10, `php artisan test` verde.

## Objetivo
Uma aba **Logs** (ou **Eventos**) no painel, rota tipo `/logs`, visual Flux, que mostra de forma
legivel os acontecimentos importantes do sistema — sem o Fabio precisar de SSH nem de pedir pro agente.

**Nao e** um dump cru do `laravel.log`. E uma visao curada e util: eventos de negocio + erros,
filtraveis, com o essencial em destaque.

## O que a pagina deve mostrar (fonte: dados que o sistema ja gera)
Levante o que ja existe (auto_reply_log, unmatched_messages, logs de envio, eventos de canal, etc.)
e monte uma timeline unificada. Categorias minimas:
- **Envios reativos**: enviado (com canal, contato, status) / **falhou** (com o motivo). Aqui e
  critico: se a Meta devolve status `failed` com code/title (ex.: 130497, 131030, 131047), **mostrar
  o code e a descricao legivel**, nao so "falhou". Esse foi o buraco de hoje.
- **Mensagens recebidas** (por canal: Evolution / Cloud), com quem enviou.
- **Sem match** (unmatched_messages): mensagens que chegaram e nao casaram regra (util pra criar regra).
- **Eventos de canal**: conexao/desconexao, webhook verificado, mudanca de status.
- **Erros do sistema**: excecoes relevantes (nivel warning/error) — de forma resumida e legivel.

## Requisitos de UX
- **Filtros**: por tipo de evento (envio ok / envio falhou / recebida / sem match / erro), por canal
  (Evolution / Cloud), e por periodo (hoje / 24h / 7d).
- **Horario em SP (UTC-3)**, exibido de forma amigavel (o servidor e UTC — converta na exibicao, nao
  mostre UTC cru pro usuario).
- **Destaque visual pra erros/falhas** (cor/badge), porque e o que mais importa ver rapido.
- Cada linha de falha de envio deve deixar claro: canal, destinatario, code do erro, descricao. Se
  possivel, um tooltip ou expandir com o payload/detalhe (mascarando qualquer segredo/token).
- Paginacao ou scroll infinito (nao carregar tudo de uma vez se houver muito volume).
- **Somente leitura**: esta pagina nao executa acoes, so mostra. Respeita multi-tenant (o usuario ve
  os eventos da conta dele). `TenantIsolationTest` gate.

## Sobre a "falha silenciosa" (faca junto, e o coracao do valor)
Hoje o adaptador do Cloud tratava status `failed` da Meta como "ignora com log leve" (decisao D5),
e por isso o 130497 passou batido. Corrija isso **na origem**, alem de mostrar na pagina:
- Quando um webhook de status trouxer `status=failed`, **persistir** o registro com o `code`, o
  `title`/descricao e o `recipient_id`, e marcar o envio correspondente como falho de forma visivel
  (nao so DEBUG). Assim a pagina de Logs tem de onde ler, e nunca mais uma falha da Meta some.
- Nao quebrar o comportamento reativo: continuar respondendo 200 rapido pro webhook; so passar a
  gravar o status com o valor real em vez de descartar.

## Testes
- Um envio que falhou (simular webhook de status `failed` com code) aparece na pagina como falha,
  com code + descricao legivel.
- Um envio ok aparece como enviado.
- Uma mensagem sem match aparece na categoria certa.
- Filtros por tipo/canal/periodo retornam o subconjunto correto.
- Isolamento: usuario de uma conta nao ve eventos de outra.
- Horario exibido convertido pra SP (nao UTC cru).
- Suite completa verde.

## Ao terminar
Verde: commita ("feat: pagina de Logs/Eventos + persistencia de status failed da Meta (code/title)"),
push sem force, relatorio em docs/relatorios/. Passa pro `03`.
Quebrou: PARA no ultimo verde, relata. Nao segue.
