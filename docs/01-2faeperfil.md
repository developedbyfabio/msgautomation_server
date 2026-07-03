# 01 — 2FA (TOTP) + Página de Perfil (trocar email/senha)

**Prioridade máxima (segurança).** Hoje o painel entra só com email+senha, e existe regra com
dados bancarios reais do Fabio em texto. Vamos endurecer o login com 2FA e dar uma pagina de
perfil pra trocar email/senha de dentro do painel.

Releia o `00-LEIA-PRIMEIRO.md` (limites duros + regra de parar se quebrar). Baseline: `git status`
limpo, ler docs/09 e docs/10, rodar `php artisan test` e anotar o nº de verdes.

## Parte A — 2FA por TOTP (via Laravel Fortify, nativo do Laravel 13)
Em Laravel 13 o 2FA por TOTP e nativo do Fortify (codigo de 6 digitos de app tipo Google
Authenticator; o Fortify define a rota /two-factor-challenge e adiciona colunas na tabela users
via migration aditiva). Use isso — nao invente solucao propria nem gambiarra.

Requisitos:
- Instalar/configurar o Fortify **sem quebrar** o login atual (que hoje ja funciona com email/senha
  + Livewire/Flux). Se o app ja tem auth proprio, integrar o 2FA de forma que o fluxo atual continue
  valido; se precisar migrar o login pro Fortify, faca de forma que a experiencia (mesma tela, mesma
  aparencia Flux) nao mude pro usuario, so ganhe a etapa de 2FA quando ativado.
- Adicionar a trait `TwoFactorAuthenticatable` no model User. Migration **aditiva** pras colunas de 2FA.
- 2FA e **opt-in por usuario** (nasce desligado; o Fabio ativa quando quiser). Exigir confirmacao de
  senha antes de ativar/desativar (recurso de password confirmation do Fortify).
- Fluxo completo: ativar 2FA -> mostrar **QR code** pra escanear no app autenticador -> confirmar com
  um codigo valido -> gerar e mostrar **codigos de recuperacao** (recovery codes) -> permitir
  regenerar recovery codes -> desativar 2FA (com confirmacao de senha).
- Na tela de login, quando o usuario tem 2FA ativo, apos email+senha corretos, cair na tela de
  **/two-factor-challenge** pedindo o codigo de 6 digitos (ou um recovery code). Estilizar essa tela
  no mesmo visual Flux do login atual.
- Rate limiting no challenge (Fortify ja suporta) pra evitar brute force do codigo.

Atencao com o Cloudflare Access na frente: o Access ja e uma camada, mas o 2FA do app e independente
e desejado (defesa em profundidade). Garanta que o fluxo funciona atras do tunel (a licao do
trustProxies vale aqui: cookies/sessao ok em HTTPS).

## Parte B — Pagina de Perfil (trocar email e senha)
Nova aba/pagina no painel, nome **Perfil** (rota tipo `/perfil`), no visual Flux consistente com o resto.
Conteudo:
- Mostrar dados do usuario logado (nome, email).
- **Trocar email**: com confirmacao de senha atual. Validar formato e unicidade. Se o app tiver
  verificacao de email, reenviar verificacao pro novo endereco (opcional; se nao tiver, so troca).
- **Trocar senha**: pedir senha atual + nova senha + confirmacao. Regras de forca minima razoaveis.
- **Secao de 2FA**: embutir aqui a gestao do 2FA da Parte A (ativar/desativar, QR, recovery codes).
- Tudo com confirmacao de senha nas acoes sensiveis, e mensagens de sucesso/erro claras.

Multi-tenant: respeitar o escopo por usuario/conta existente (MT-1). Um usuario so edita o proprio
perfil. `TenantIsolationTest` continua gate.

## Testes (adicionar, sequenciais)
- Ativar 2FA gera secret + QR + recovery codes; confirmar com codigo valido liga o 2FA.
- Login com 2FA ativo exige o challenge; codigo valido passa, invalido barra; recovery code funciona.
- Desativar 2FA exige confirmacao de senha.
- Trocar email exige senha atual; email invalido/duplicado barra; troca valida persiste.
- Trocar senha exige senha atual correta; nova senha fraca barra; troca valida permite login com a nova.
- Um usuario nao consegue editar perfil de outro (isolamento).
- Rodar a suite completa verde no fim.

## Ao terminar
Se tudo verde: commita ("feat: 2FA (TOTP/Fortify) + pagina de Perfil (troca email/senha)"), push sem
force, escreve relatorio em docs/relatorios/ (o que mudou, migrations criadas, como testou, e um passo
a passo curto de como o Fabio ativa o 2FA dele). Depois passa pro `02`.
Se quebrar: PARA, deixa no ultimo verde, relata onde travou. Nao segue pro 02.
