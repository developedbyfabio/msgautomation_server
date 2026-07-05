# Fatia 10 — Polish de UI: breadcrumb + pulso no status + botões do cofre — 2026-07-05

Git no início: HEAD `b5ffbd5` (fatia 9), working tree limpo exceto os dois relatórios untracked
pré-existentes (`diagnostico-modo-automatico.md`, `fatia8-STOP-pre-requisito.md` — fora do commit,
como nas fatias anteriores). Baseline: **752 verdes / 2943 assertions**.

---

## Mapeamentos (lido antes de escrever)

1. **Breadcrumb:** montado num **único ponto** — `resources/views/components/layouts/app.blade.php`
   (linhas ~87–92), via `flux:breadcrumbs` com item fixo "Menu" + item da aba atual (`$navAtual`,
   resolvido por `request()->routeIs()` sobre o array `$nav` do próprio layout). **"Menu" NÃO era
   link** — `<flux:breadcrumbs.item>` sem `href`, puramente decorativo; nenhuma rota perdeu acesso.
2. **Status:** `resources/views/livewire/status-conexao.blade.php` — dot colorido por `match ($state)`
   (`open`/`connecting`/`verificando`/`sem_canal`/default). Lógica de poll em `StatusConexao.php`
   (intocada).
3. **Cofre:** `resources/views/livewire/senhas.blade.php` + `app/Livewire/Senhas.php`. **Mecânica
   confirmada segura:** a lista renderiza `••••••••` fixo; o valor só existe em
   `$revealedValue`, que só é preenchido em `confirmReveal()` **após** validar a re-digitação da
   senha de login (server-side). O valor **não entra no DOM antes da action** — nenhum achado de
   segurança; o teste de blindagem novo torna isso permanente.

## O que mudou (apresentação apenas)

### 2.1 Breadcrumb → só o título
- Removido o item "Menu"; o `flux:breadcrumbs` agora renderiza só `{{ $navAtual[1] }}`, e apenas
  quando a rota está no menu (`@if ($navAtual)`). Ponto único — vale pra todas as páginas de uma vez.
- **Edge registrado:** páginas fora do `$nav` (só `/admin/tenants`, do super-admin) mostravam
  breadcrumb "Menu" solto; agora não mostram breadcrumb (o hamburguer mobile e o cluster do header
  não dependem dele — são elementos irmãos, mobile intacto).

### 2.2 Pulso no status conectado
- Padrão "sonar": segundo `<span>` sobreposto ao dot com `motion-safe:animate-ping`
  (`bg-emerald-400 opacity-75`), **só quando `$state === 'open'`** — desconectado, conectando,
  verificando e sem canal ficam com o dot estático. Escolhido `animate-ping` (não `animate-pulse`):
  o dot sólido permanece firme e a onda expande — mais "vivo" sem piscar o indicador em si.
- `motion-safe:` garante que `prefers-reduced-motion` não recebe animação (com motion reduzido, a
  onda fica estática atrás do dot sólido de mesmo tamanho — invisível).
- Rebuild dos assets em **foreground** (`npm run build`, Tailwind 4/Vite) — `animate-ping` presente
  no CSS final (verificado por grep no bundle). `public/build` é gitignored, nada de asset no commit.

### 2.3 Botões do cofre
- Links de texto "revelar"/"ocultar" viraram botões compactos com **ícone de olho**
  (`flux:icon eye` / `eye-slash` — heroicons via Flux free), borda + hover, mesmo estilo dos botões
  compactos do header (Reconectar/Desconectar) — conjunto harmonizado; não há botão de copiar na
  linha. `aria-label`/`title` por estado ("Revelar senha"/"Ocultar senha"), `font-sans` no rótulo
  (a linha é `font-mono` das bolinhas), `wire:loading` desabilita o revelar.
- **Mecânica intocada:** mesmo `askReveal` → modal de re-senha → `confirmReveal` server-side.

## Testes

- `tests/Feature/NavegacaoSidebarTest.php` (ajustado): o assert `assertSee('Menu')` do layout era o
  design antigo — **mudança deliberada** para `assertDontSee('Menu')` (comentário no teste registra a
  fatia). Novo caso `test_breadcrumb_mostra_so_o_titulo_sem_prefixo_menu`: amostra de 3 abas
  (Regras, Senhas, Configuracoes) renderiza OK com o título e sem "Menu". (Fluxos ficou fora da
  amostra de propósito: a página tem "Menu numerado"/"Menu (nos e opcoes)" legítimos no conteúdo.)
- `tests/Feature/StatusConexaoPulseTest.php` (novo, 3 casos): conectado renderiza com
  `motion-safe:animate-ping`; desconectado sem `animate-ping`; sem canal sem `animate-ping`
  (estados montados com `Http::fake` no connectionState, mesmo padrão do
  `StatusConexaoIsolamentoTest` — que segue verde sem alteração).
- `tests/Feature/SenhasTest.php` (+1, **blindagem — o que vale ouro**):
  `test_blindagem_valor_fora_do_html_antes_de_revelar_e_some_ao_ocultar` — na MESMA instância do
  componente: valor ausente do HTML na lista mascarada, ausente com o modal de re-senha aberto,
  presente **só** após `confirmReveal` com a senha correta, ausente de novo após `hideReveal`.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 752 | 2943 |
| Depois | **757** | **2966** |

Suíte inteira **sequencial**, tudo verde — zero regressão (único ajuste deliberado: o assert de
"Menu" no `NavegacaoSidebarTest`, justificado acima).

## Confirmações explícitas

- **Nenhuma mudança de lógica:** git diff só em 3 views de apresentação
  (`app.blade.php`, `status-conexao.blade.php`, `senhas.blade.php`) + testes. Nada em pipeline,
  polling de status, mecânica do cofre, rotas, models ou actions.
- Zero migration; sem toque em Nextgest/nginx/Tunnel/2FA.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
