# Fatia 24 — Cofre de credenciais: renomeação da fachada de "Senhas" — 2026-07-06

Git no início: HEAD `c0eda65` (fatia 21), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **892 verdes / 3564 assertions**.

---

## Tabela de classificação (registrada ANTES de editar)

| ocorrência | tipo | mudou? |
|---|---|---|
| Rótulo do menu 'Senhas' (`app.blade.php`) | rótulo | **SIM** → "Cofre de credenciais" (cabe no menu — "Respostas automaticas" é maior; escolha registrada) |
| h1 "Senhas (cofre)" + copy da página (botões, modais, empty, aviso) | rótulo | **SIM** (de-para abaixo) |
| Copy da nativa `{senha:nome}` em Variáveis ("senha do cofre") | rótulo | **SIM** → "credencial do cofre" (token citado intacto) |
| **Token `{senha:nome}`** (sintaxe, prefixo, resolução no Sender) | **identificador/contrato de dados** | **NÃO** — byte-idêntico |
| Rota `/senhas` + `name('senhas')` | identificador | NÃO (provado por teste: `route('senhas') === url('/senhas')`) |
| Chave `'senhas'` no `AreaAccess::MAP` | identificador | NÃO (provado: `MAP['senhas'] === 'owner'`) |
| Componente `Senhas`, ações `inserirSenhaNo`/`confirmReveal`, `SecretVault`, tabela `secrets` | identificadores | NÃO |
| Guarda anti-exfiltração (Fatia 15: `{senha:}` em conteúdo = órfão) + `unknownRefs` | identificador/lógica | NÃO |
| "revelar/mascarar senha" no SIMULADOR de fluxos e "inserir senha" no editor de regras | rótulo, **mas asserido em teste** (`FlowTreeEditModalTest`, `RegrasAvancadasTest`) e refere-se ao segredo em si | **NÃO** (mantidos — decisão registrada: mudar exigiria alterar testes de ação, proibido pela fatia) |
| "senha de login" (modal de re-autenticação) | fato literal (é a senha de login do usuário) | NÃO |
| Prompts de console do AuthTest ("Nova senha...") | identificador de CLI | NÃO |

## De-para de copy (fachada)

| antes | depois |
|---|---|
| menu "Senhas" | **"Cofre de credenciais"** |
| h1 "Senhas (cofre)" | "Cofre de credenciais" |
| "Nova senha" (botão/modal) | "Nova credencial" / "Editar credencial" |
| "Revelar senha" / "Excluir senha" (títulos de modal) | "Revelar credencial" / "Excluir credencial" |
| aria "Revelar senha"/"Ocultar senha" (botões da lista) | "Revelar valor"/"Ocultar valor" (mais preciso) |
| "Cofre vazio. Cadastre a primeira senha." / "Nenhuma senha encontrada." | "...primeira credencial." / "Nenhuma credencial encontrada." |
| Aviso de segurança | Reescrito: "Cofre técnico, separado do conteúdo de atendimento: valores **cifrados**... só saem se VOCÊ usar o código `{senha:nome}` numa regra..." — explica melhor E documenta o token **intacto** |

## Confirmação por git diff dirigido

- `git diff --stat app/ routes/ database/` → **vazio** (zero PHP de aplicação, zero rota, zero
  schema). Só 3 blades + 2 arquivos de teste.
- As 3 linhas do diff contendo `senha:` são copy de blade AO REDOR do token — o `{senha:nome}`
  aparece idêntico nos dois lados (o aviso novo até o cita explicitamente).
- Pipeline/motor/Sender/matching: zero diff.

## Reafirmações (não reimplementado — Fatias 15/22 intactas)

- **Suítes-contrato verdes SEM NENHUMA ALTERAÇÃO:** `SenhasTest` (blindagem do DOM + reveal),
  `SecretSendTest` (o token resolve no POST), `SecretVaultTest`, `KbVariableTest` (anti-exfiltração:
  `{senha:}` em conteúdo = órfão), `RolePermissionsTest` (owner-only + confirmReveal gated).
- `CofreFachadaTest` re-afirma explicitamente pós-renomeação: operador 403 na rota E no
  `confirmReveal` forjado (valor nunca entra no componente); owner revela com re-autenticação.

## Ajustes deliberados em testes (2, só rótulo visível)

1. `NavegacaoSidebarTest::MENU` — `'senhas' => 'Senhas'` → `'Cofre de credenciais'`.
2. `NavegacaoSidebarTest` (amostra do breadcrumb) — rótulo esperado atualizado.

**Nenhum teste de token/ação/gate/resolução precisou mudar** (o sinal de identificador intocado).

## Testes novos (`CofreFachadaTest`, 4 casos)

Fachada nova no menu/h1/copy com os rótulos antigos ausentes E o token `{senha:nome}` presente na
copy; URL/rota/chave do mapa idênticas; reafirmação do gate (operador barrado em rota + ação
forjada, mesmo com senha de login correta); fluxo do owner intacto.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 892 | 3564 |
| Depois | **896** | **3576** |

Suíte inteira **sequencial**, tudo verde.

## Confirmações explícitas

- Zero migration; isolamento por conta inalterado; 2FA intocado.
- **`queue:restart` não necessário e não executado**: diff = 3 blades + 2 arquivos de teste —
  nenhum código carregado por job (critério da fatia atendido e registrado).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
