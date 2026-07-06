# Fatia 23 — Navegação reagrupada + linguagem de negócio + view-only do operador — 2026-07-06

Git no início: HEAD `c044502` (fatia 22), working tree limpo exceto os untracked pré-existentes
(dois relatórios + `public/fundo.webp`, fora dos commits). Baseline: **882 verdes / 3502 assertions**.

---

## De-para de navegação (mesmas rotas/URLs — só a árvore mudou)

| antes (flat) | agora | rota (inalterada) |
|---|---|---|
| Painel | **Inicio** | painel |
| Conversas | **Atendimento** | conversas |
| Kanban | Kanban | kanban |
| Contatos | **Clientes** | contatos |
| Campanhas | Campanhas | campanhas |
| Regras | grupo **Automacao** → **Respostas automaticas** | regras |
| Fluxos | grupo Automacao → **Menus de atendimento** | fluxos |
| Conhecimento | grupo Automacao → **Informacoes do negocio** | conhecimento |
| Variaveis | grupo Automacao → Variaveis | variaveis |
| Revisao | grupo Automacao → **Sugestoes da IA** | revisao |
| Senhas | Senhas (**NÃO renomeado** — Fatia 24) | senhas |
| Logs | Logs (owner-only pelo mapa — some pro operador) | logs |
| Configuracoes | Configuracoes | configuracoes |
| Perfil | Perfil | perfil |
| Tenants | **Empresas** (só super-admin, mecanismo intacto) | admin.tenants |

**Submenu:** `flux:sidebar.group expandable` (existia no Flux free — `sidebar/group.blade.php`).
Grupos sem heading rendem soltos; grupo cujos itens foram todos filtrados pelo papel **some junto**
(operador vê o grupo Automacao só com "Informacoes do negocio" + "Sugestoes da IA"). A lista plana
continua alimentando o breadcrumb. URLs provadas idênticas por teste (as 4 linhas `name(` no diff
de rotas são os pares -/+ de rotas **movidas de grupo de middleware**, nomes intactos).

## Glossário de rótulos aplicado (menu + h1; SÓ apresentação)

Cabeçalhos: "Painel"→"Inicio"; "Campanhas proativas"→"Campanhas"; "Regras (automacoes)"→
"Respostas automaticas"; "Fluxos (menus)"→"Menus de atendimento"; "Base de conhecimento (IA)"→
"Informacoes do negocio"; "Variaveis (placeholders)"→"Variaveis (textos dinamicos)"; "Revisao
(fila de aprovacao da IA)"→"Sugestoes da IA (aprovacao)"; "Contatos / Agenda"→"Clientes";
"Configuracoes / Freios"→"Configuracoes — Seguranca e controle".

**Deliberadamente NÃO renomeados (identificadores):** eventos (`AutoReplySent`...), valores de
`cause` (`sem_resposta`, `handoff`, `manual`, `regra`), slugs de coluna do Kanban, chaves de
config, nomes de rota, strings de logs técnicos e strings de lógica — **confirmado por git diff
dirigido**: zero diff em `app/Jobs/`, `app/Whatsapp/`, `app/Kanban/`, `app/Events/`; zero hit de
mudança em `cause`/`event_type`.

## View-only do operador (o ajuste real — decisão do dono)

- **`AreaAccess::MAP`:** `campanhas` e `conhecimento` passaram de `owner` para `operador` (a ROTA
  abre — mudança deliberada vs Fatia 22). **Regras/Fluxos/Variáveis: inalterados** (owner-only,
  nem ver — reafirmado por teste).
- **`AreaAccess::EDIT_MAP`** (novo): `campanhas`/`conhecimento` ⇒ editar = `owner`. Fonte única
  mantida: menu, rota e ver/editar consomem o mesmo `AreaAccess` (`canEdit`, `canEditArea`,
  `authorizeEditAction`).
- **Gates de escrita (13 ações):** Campanhas — `save`, `duplicate`, `usarTemplate`, `openPreview`
  (persiste 'previewed'), `approveConfirmed`, `cancelConfirmed`, `unapproveConfirmed`,
  `pauseConfirmed`, `resume`; Conhecimento — `save`, `usarTemplate`, `toggle`, `deleteConfirmed`.
  Operador forjando qualquer uma = **403 sem efeito** (provado). O persistent middleware do
  Livewire da Fatia 22 continua cobrindo a camada de rota.
- **UI de leitura (cosmética):** `podeEditar` no render dos dois componentes esconde "Nova
  campanha"/"Nova entrada", dropdowns de ação e seções de templates. Nota registrada: o "Ver
  destinatarios" vive no dropdown e some junto pro operador (a lista com barras de progresso
  permanece visível) — relaxável depois se o dono quiser.

## Identificador de contato na UI

`Contact::displayPhone()` — **helper de exibição puro** (o `remote_jid` armazenado e o matching
ficam intactos): `5541999887766@s.whatsapp.net` → `+55 (41) 99988-7766`; fora do padrão BR cai no
número cru sem o sufixo técnico. Aplicado na lista de Clientes (provado por teste que o jid cru
sumiu da lista). Nada além de view — **não** houve refatoração de armazenamento.

## Ajustes deliberados em testes (um a um)

1. `RolePermissionsTest::test_operador_barrado_por_url...` — campanhas/conhecimento saíram da
   lista de 403 (viraram view-only por decisão do dono) e entraram na lista de acesso do dia a dia.
2. `RolePermissionsTest::test_menu_oculta...` — rótulos de negócio + conhecimento agora VISÍVEL
   pro operador; a ocultação passou a ser asserida nos estruturais (Respostas automaticas/Menus de
   atendimento/Variaveis).
3. `NavegacaoSidebarTest::MENU` — rótulos novos (rotas idênticas).
4. `NavegacaoSidebarTest` (2 asserts) — `assertDontSee('Menu')` → `assertDontSee('Menu >')`: o
   rótulo novo "Menus de atendimento" contém "Menu" legitimamente; a amostra do breadcrumb trocou
   'regras' por 'kanban' pelo mesmo motivo.
5. `PainelTest` — h1 'Painel' → 'Inicio'.

## Testes novos (`ViewOnlyNavigationTest`, 8 casos)

Operador vê Campanhas/Conhecimento em modo leitura (rota 200 + escrita escondida); **escrita
forjada rejeitada** nas 9 ações de campanha e 4 de conhecimento amostradas (403, nada persiste —
status/active/contagens intactos); owner mantém ver+editar; estruturais seguem 403; **URLs
inalteradas** (14 rotas conferidas nome→path); menu reagrupado (owner vê heading "Automacao" e
"Menus de atendimento"; owner de conta não vê "Empresas"; super-admin vê); h1 "Clientes" +
telefone amigável e jid técnico fora da lista.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 882 | 3502 |
| Depois | **890** | **3553** |

Suíte inteira **sequencial**, tudo verde.

## Confirmações explícitas

- Pipeline/motor/matching/eventos: **zero diff**. Zero migration. Rotas/URLs idênticas (provado).
  Nenhum identificador interno renomeado (diff dirigido). "Senhas" não renomeado (Fatia 24).
- **`queue:restart` executado por precaução** (`Contact.php` — carregado pelo worker — ganhou o
  helper de exibição; inócuo pra jobs, mas o processo longevo recarrega). Pid/horário na resposta.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
