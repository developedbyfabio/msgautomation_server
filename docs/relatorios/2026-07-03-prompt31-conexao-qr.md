# Prompt 31 — Conexão WhatsApp: QR em modal, atualizar funcional e countdown — 2026-07-03

**Status: ENTREGUE.** Baseline 666 → **670 verdes** (+4), `TenantIsolationTest` 28. Sem migration.
Provisionamento/reativo/envio/recebimento **intocados**. Isolamento por conta (prompt 27) preservado.

## Passo 1 — Achados
1. **Como o QR é buscado:** ambos os lugares chamam `provider->api($canal)->connect()` (GET
   `/instance/connect/{instance}` do Evolution). Eram **dois códigos** quase iguais: `Conexao::gerarQr()`
   (página `/conexao`) e `StatusConexao::abrirQr()` (modal em `/conversas`). Ambos já escopados por
   conta (`Channel::defaultFor(accountId())`, guarda de canal null — prompt 27).
2. **Por que o "Atualizar" parecia não regenerar:** a causa **no código** é o poll da `/conexao`
   (`wire:poll.5s="poll"`) só buscar QR quando `qr === null` — depois do 1º QR, **o poll nunca
   re-buscava**, então o QR ficava **parado na tela** e expirava (o WhatsApp rotaciona o QR em
   ~20-30s), dando a impressão de "travado". O botão em si já re-chamava `connect()`. Não havia
   countdown, então o usuário não sabia que expirou. (Ressalva: se o Evolution devolver o **mesmo**
   QR em `connect()` repetido numa instância `connecting`, a rotação real dependeria de `restart` —
   não verificável ao vivo aqui, ver "Ressalva" abaixo.)
3. **Tempo/expiração:** a instância viva do Fabio está `open` (connect retorna sem base64 = "já
   conectado", tratado); não deu pra medir ao vivo o tempo do QR numa instância `connecting` sem
   disromper. Calibrei o auto-refresh em **40s** (abaixo do tempo em que o QR do WhatsApp deixa de ser
   escaneável), configurável via prop `lifetime` do painel.

## Passos 2/3/4 — O que foi feito

### Componente ÚNICO de QR — `resources/views/components/qr-panel.blade.php`
Reusado nos dois lugares (corrige/unifica de uma vez). Recebe `qr`, `qrError`, `refreshAction`
(o método Livewire que re-busca: `gerarQr` na /conexao, `abrirQr` no /conversas) e `lifetime`.
Contém: imagem do QR, **countdown regressivo** (Alpine), instruções e o botão **"Atualizar QR"**.

### Passo 2 — "Atualizar" funcional
O botão faz `wire:click="{{ refreshAction }}"` → re-chama `connect()` da **instância existente**
(novo QR) e re-renderiza; reinicia o contador. **Não** reprovisiona/recria instância (teste
`test_atualizar_nao_reprovisiona_nem_cria_instancia`: sem POST `/instance/create`, canal count = 1).

### Passo 3 — Countdown + auto-refresh
Timer client-side (Alpine): mostra "Expira em Ns" e, ao **zerar**, chama o `refreshAction` (novo
`connect`) automaticamente — resolvendo o QR-parado do Passo 1. O `wire:key` do painel é keyado no
QR (`md5`), então **cada QR novo reinicia o contador** (auto ou via botão). Escolhi auto-refresh (em
vez de só "expirado") por ser mais limpo — o QR fica sempre escaneável sem ação do usuário.

### Passo 4 — /conexao em modal
O QR agora abre num **`x-modal` por cima** (mesmo componente do `/conversas`), com o `<x-qr-panel>`
dentro. O fundo da página é mínimo ("Conecte seu WhatsApp pra ativar o canal" + estado). O modal é
mandatório enquanto há canal e não está `open`; `wireClose="poll"` = fechar re-checa o estado (segue
aberto se não conectou; **redireciona pras conversas ao conectar**). **Onboarding preservado:** conta
sem canal continua vendo o botão "Conectar WhatsApp" (provisiona) e o redirect da fatia 27 pra
`/conexao` segue levando ao QR.

## GATE (confirmado por teste)
- **"Atualizar" regenera:** `test_atualizar_rebusca_um_qr_novo` (sequência QR-A → QR-B via novo
  `connect`), batendo no `/instance/connect/` da **instância da conta** (escopo).
- **Não reprovisiona:** sem `/instance/create`, canal não duplica.
- **Escopo por conta / isolamento (27) preservado:** `defaultFor(accountId())` + guarda null mantidos;
  `StatusConexaoIsolamentoTest`/`GateSemCanalTest`/`ConexaoSelfServiceTest` verdes.
- **Reativo/envio/recebimento/provisionamento intocados** (diff só de blades + componente + teste).
- `TenantIsolationTest` 28 verde.

## Testes (670 verdes, +4)
`ConexaoQrTest`: atualizar re-busca QR novo (escopado); não reprovisiona; painel de QR presente na
`/conexao` e no `/conversas` (com countdown e auto-refresh `$wire.gerarQr()`/`$wire.abrirQr()`).

## Ressalva (validação viva — recomendação)
Não consegui validar ao vivo se o `connect()` do Evolution **rotaciona** o QR a cada chamada numa
instância `connecting` (a instância do Fabio está `open`). O painel re-chama `connect()` (o correto)
e o auto-refresh resolve o QR-parado. **Se**, num tenant novo real, o "Atualizar"/auto-refresh trouxer
sempre o **mesmo** QR, o passo seguinte é usar `restart` do Evolution pra forçar novo QR — isso mexe
no ciclo da instância e precisa de validação viva, então ficou **fora** desta fatia (PARE conforme
regra). Recomendo o Fabio testar com um tenant novo.

## Checklist manual (Fabio)
- [ ] `/conexao` (conta com canal, desconectada): QR abre em **modal por cima**; contador regressivo
  correndo; ao zerar, QR atualiza sozinho.
- [ ] Botão "Atualizar QR" traz QR novo e reinicia o contador.
- [ ] Ao escanear/conectar, segue pras conversas (poll).
- [ ] Conta **sem** canal: ainda mostra "Conectar WhatsApp" e conecta (onboarding intacto).
- [ ] `/conversas` (Reconectar): mesmo painel de QR no modal.
- [ ] Desktop e mobile.
