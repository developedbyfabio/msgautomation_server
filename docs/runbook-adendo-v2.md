# Runbook — Adendo v2 (o que mudou com o lote 2)

Deltas sobre o runbook original. Onde nada é dito, o runbook original continua valendo. Use este adendo junto com ele ao testar as Partes 2–8.

---

## Parte 2 (Configurar) — SIMPLIFICADA

- **2.1** igual: habilite o template desejado na aba **Fluxos** (os 3 seguem desabilitados até você ligar).
- **2.2** (selecionar o padrão em Configurações) agora é **OPCIONAL** — o modal de ativação escolhe o fluxo (Fatia 9). Pode pular direto pro toggle.
- **2.3/2.4** (preset anti-ban e janela de horário) iguais.

---

## Parte 4 — deltas

- **4.1 Ligar (mudou):** o controle agora é um **switch**, e o modal exige **escolher o fluxo** num select (lista só os habilitados da conta; o padrão atual vem pré-selecionado quando válido).
  - **Esperado:** sem escolher, o Confirmar não anda; confirmar grava fluxo padrão + modo juntos; cancelar não grava nada.
- **4.5 Handoff (mudou):** o card do contato agora vai para **"Aguardando resposta"** (não mais "Em atendimento").
  - **Esperado extra (a corrida da Fatia 11):** a mensagem de despedida do handoff **não** regride o card — ele **permanece** em "Aguardando resposta" mesmo depois do envio processado.
- **4.x novo (opcional):** em modo **Pessoal**, mensagem sem match agora move o card do contato para **"Aguardando resposta"** (inbox de pendências) — inclusive contato novo (não para em "Novo"). Valide se o comportamento te agrada na prática.

---

## Parte 5 — deltas

- **5.2 (o teste antigo ficou inválido por design):** a variante de aviso agora **só aparece quando NÃO há NENHUM fluxo habilitado** na conta.
  - **Teste novo:** desabilite todos os fluxos → ligar o modo → aviso com link pra Fluxos + "Ativar mesmo assim" (pode ativar; nada será respondido até habilitar um fluxo).
  - Setar o padrão como "Nenhum" em Configurações **com** fluxos habilitados **não** dispara mais aviso — o modal simplesmente pede a escolha.
- **5.3:** re-habilite o fluxo que for usar depois deste teste.

---

## Novidades do lote 2 pra validar (checklist)

- [ ] **Badge "Padrão"** na aba Fluxos: indigo no fluxo padrão; âmbar "Padrão (desabilitado)" se o padrão estiver off; nenhum badge com padrão vazio.
- [ ] **Tags standalone:** no modal "Gerenciar tags" (em Contatos), criar tag nova direto (nome + cor) sem passar por um contato; contagem de uso ao lado de cada tag; renomear/excluir seguem lá.
- [ ] **Duplicar fluxo:** botão na listagem → a cópia nasce **desabilitada**, nome "(cópia)", abre direto no editor; original intacto; o badge "Padrão" continua no original.
- [ ] **Duplicar campanha:** a cópia nasce **rascunho** limpo (sem agenda/aprovação), qualquer status pode ser duplicado.
- [ ] **Templates ("Começar com um modelo")** nas 4 abas:
  - **Regras (5):** nascem **desligadas** com [colchetes] pra editar — troque os colchetes e ligue.
  - **Conhecimentos (4):** nascem ativos, título sufixado se colidir.
  - **Campanhas (3):** nascem rascunho com rodapé de opt-out; público a definir antes de aprovar.
  - **Variáveis (3):** `{empresa}`, `{atendente}`, `{horario_funcionamento}` — se já existir a variável, é rejeitada sem sobrescrever (o seu valor fica).
- [ ] **Conhecimento como variável:** no editor de nó de fluxo, dropdown "conhecimento" insere `{kb:slug}`; a mensagem enviada sai com o **conteúdo** do conhecimento; renomear o título **não** quebra o token; conhecimento sensível não aparece no dropdown.
- [ ] **IA global:** a seção "IA para este contato" **saiu** de Contatos (era o design antigo); o controle agora é só o global em Configurações ("vale pra conta inteira, padrão desligado, silenciados continuam fora").
- [ ] **Kanban:** handoff e mensagens sem resposta chegam em **"Aguardando resposta"**; respostas normais do robô seguem indo pra "Em atendimento".
- [ ] **Polish:** breadcrumb sem "Menu >" em todas as abas; pulso verde no status conectado; botões olho/olho-cortado no cofre.

---

## Lembretes que continuam valendo

- Mandar mensagem de teste sempre de **outro número** (fromMe é ignorado).
- Janela anti-ban **08–20** (fora dela o envio é bloqueado pelo gate).
- Canal **Evolution** pra teste de envio (Cloud API segue travada no 130497 da Meta).
- **Voltar pra Pessoal** ao terminar e desabilitar os fluxos que não for usar.
- Troubleshooting do runbook original continua válido — com um ajuste: no passo 7, "fluxo padrão selecionado" agora se resolve direto no modal de ativação.

---

## Notas de verificação (adicionadas pelo agente, 2026-07-06 — conferido contra o HEAD `8f62009`)

Todos os itens acima batem com o que foi entregue nas fatias 9–16. Quatro precisões pra não
estranhar durante o teste:

1. **Sufixo de cópia é "(copia)" SEM acento** (fluxo e campanha) — convenção do codebase. O
   comportamento é o descrito; só a grafia difere do adendo.
2. **Hint do handoff no editor está com copy defasada:** ao editar um nó handoff, o aviso ainda
   diz "move o card pra **Em atendimento**" — o comportamento real (e o que o teste 4.5 deve
   validar) é **"Aguardando resposta"**. Registrado na fatia 15 como polish futuro.
3. **Dropdown "conhecimento" exclui mais que o sensível:** ficam fora também os inativos, os
   restritos a contatos específicos e os com `{senha:...}` no conteúdo — mesma elegibilidade do
   envio (o dropdown nunca oferece o que sairia vazio).
4. **Variável duplicada:** a rejeição aparece como toast "Modelo nao criado: Ja existe variavel
   com esse nome." — o valor existente fica intacto (confirmado por teste).

**Operacional (já feito, nada pendente pro teste):** assets rebuildados (fatia 10), migration do
slug aplicada em produção com backfill conferido (fatia 15), e o **worker da fila reiniciado**
(`queue:restart` gracioso) após o último commit — sem isso os jobs (handoff→Kanban, `{kb:}`, IA)
rodariam código antigo em memória. Worker novo no ar desde 01:24 de 2026-07-06.
