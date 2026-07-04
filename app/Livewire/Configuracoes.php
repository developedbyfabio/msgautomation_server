<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\AutoReplySetting;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Configuracoes extends Component
{
    public bool $enabled = false;
    public string $reply_policy = 'allowlist';

    // Fatia 3 — fluxo de atendimento padrao (usado pelo modo automatico na Fatia 4;
    // por ora INERTE no robo: aqui so grava a escolha). null = nenhum.
    public ?int $default_flow_id = null;
    public string $window_start = '08:00';
    public string $window_end = '20:00';
    public int $min_interval_seconds = 30;
    public int $per_minute_cap = 4;
    public int $per_day_cap = 40;
    public int $contact_rate_seconds = 1800;
    public int $delay_min_seconds = 3;
    public int $delay_max_seconds = 15;
    public bool $skip_groups = true;
    public bool $warmup_enabled = false;

    // Prompt 14 — auto-download de midia recebida (por conta; default do .env quando nunca tocado).
    public bool $media_autodownload = true;

    // S2 — toggles liga/desliga por freio (desligado = nao bloqueia).
    public bool $window_enabled = true;
    public bool $min_interval_enabled = true;
    public bool $per_minute_enabled = true;
    public bool $per_day_enabled = true;
    public bool $contact_rate_enabled = true;

    // Camada 3 (IA) — kill switch proprio + ajuste fino (Fatia 4: limiar e temas editaveis).
    public bool $ai_enabled = false;
    public float $ai_confidence_threshold = 0.75;
    /** @var array<int,string> */
    public array $ai_approval_topics = [];

    public bool $salvo = false;
    public bool $confirmingEnable = false;
    public bool $confirmingAiEnable = false;

    // Proativas P-1 — bloco proprio (kill switch INDEPENDENTE + tetos D5). Jitter
    // (3-15min) fica no default do schema; editavel so em fatia futura se precisar.
    public bool $proactive_enabled = false;
    public int $proactive_daily_cap = 20;
    public int $proactive_per_contact_weekly_cap = 1;
    public string $proactive_window_start = '09:00';
    public string $proactive_window_end = '18:00';
    public string $proactive_optout_word = 'PARAR';
    // P-4: rodape PADRAO de saida da conta (pre-preenche campanha nova).
    public string $proactive_optout_footer = '';
    public bool $confirmingProactiveEnable = false;
    public bool $confirmingProactiveRelax = false;
    /** @var array<int,string> */
    public array $proactiveRelaxWarnings = [];

    // Fatia 4 — confirmacao ao AFROUXAR a IA (reduzir limiar < 0.70 / desmarcar tema).
    public bool $confirmingAiRelax = false;
    /** @var array<int,string> */
    public array $aiRelaxWarnings = [];

    /** Rotulos pt-BR dos temas de aprovacao (mesmos 4 do desenho aprovado). */
    public const AI_TOPIC_LABELS = [
        'pagamento' => 'Pagamento/PIX/valores',
        'dados_bancarios' => 'Dados bancarios/senhas',
        'compromissos' => 'Compromissos/agendamentos',
        'conteudo_high' => 'Conteudo sensivel (high) da base',
    ];

    public function mount(): void
    {
        $s = $this->settings();
        $this->enabled = (bool) $s->enabled;
        $this->reply_policy = (string) $s->reply_policy;
        $this->default_flow_id = $s->default_flow_id ? (int) $s->default_flow_id : null;
        $this->window_start = substr((string) $s->window_start, 0, 5);
        $this->window_end = substr((string) $s->window_end, 0, 5);
        $this->min_interval_seconds = (int) $s->min_interval_seconds;
        $this->per_minute_cap = (int) $s->per_minute_cap;
        $this->per_day_cap = (int) $s->per_day_cap;
        $this->contact_rate_seconds = (int) $s->contact_rate_seconds;
        $this->delay_min_seconds = (int) $s->delay_min_seconds;
        $this->delay_max_seconds = (int) $s->delay_max_seconds;
        $this->skip_groups = (bool) $s->skip_groups;
        // Prompt 14: mostra o estado EFETIVO (escolha da tela, ou default do .env).
        $this->media_autodownload = $s->mediaAutodownloadEnabled();
        $this->warmup_enabled = (bool) $s->warmup_enabled;
        $this->window_enabled = (bool) $s->window_enabled;
        $this->min_interval_enabled = (bool) $s->min_interval_enabled;
        $this->per_minute_enabled = (bool) $s->per_minute_enabled;
        $this->per_day_enabled = (bool) $s->per_day_enabled;
        $this->contact_rate_enabled = (bool) $s->contact_rate_enabled;
        $this->ai_enabled = (bool) $s->ai_enabled;
        $this->ai_confidence_threshold = (float) $s->ai_confidence_threshold;
        $this->ai_approval_topics = $s->aiApprovalTopics();
        $this->proactive_enabled = (bool) $s->proactive_enabled;
        $this->proactive_daily_cap = (int) $s->proactive_daily_cap;
        $this->proactive_per_contact_weekly_cap = (int) $s->proactive_per_contact_weekly_cap;
        $this->proactive_window_start = substr((string) $s->proactive_window_start, 0, 5);
        $this->proactive_window_end = substr((string) $s->proactive_window_end, 0, 5);
        $this->proactive_optout_word = (string) $s->proactive_optout_word;
        $this->proactive_optout_footer = (string) $s->proactive_optout_footer;
    }

    private function settings(): AutoReplySetting
    {
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return AutoReplySetting::firstOrCreate(['account_id' => app(\App\Tenancy\AccountContext::class)->id()]);
    }

    /**
     * Ligar pede confirmacao (modal) — ativa respostas automaticas no numero pessoal.
     * Desligar e INSTANTANEO, sem modal (freio de emergencia, zero friccao).
     */
    public function requestKillSwitch(): void
    {
        if ($this->settings()->enabled) {
            $this->settings()->update(['enabled' => false]);
            $this->enabled = false;
            $this->dispatch('toast', message: 'Robo desligado.');

            return;
        }

        $this->confirmingEnable = true;
    }

    public function enableConfirmed(): void
    {
        $this->settings()->update(['enabled' => true]);
        $this->enabled = true;
        $this->confirmingEnable = false;
        $this->dispatch('toast', message: 'Robo LIGADO.', type: 'error');
    }

    public function cancelEnable(): void
    {
        $this->confirmingEnable = false;
    }

    /**
     * Prompt 14 — liga/desliga o auto-download de midia recebida (INSTANTANEO, sem
     * modal). Grava a escolha EXPLICITA por conta (passa a mandar sobre o .env).
     */
    public function toggleMediaAutodownload(): void
    {
        $this->media_autodownload = ! $this->media_autodownload;
        $this->settings()->update(['media_autodownload' => $this->media_autodownload]);
        $this->dispatch('toast', message: $this->media_autodownload
            ? 'Auto-download de midia LIGADO.'
            : 'Auto-download de midia desligado.');
    }

    /**
     * Kill switch PROPRIO da IA (separado do robo). Ligar pede confirmacao (passa a
     * classificar mensagens sem regra e pode responder sozinho pela sua resposta).
     * Desligar e INSTANTANEO (freio de emergencia). Nasce OFF.
     */
    public function requestAiSwitch(): void
    {
        if ($this->settings()->ai_enabled) {
            $this->settings()->update(['ai_enabled' => false]);
            $this->ai_enabled = false;
            $this->dispatch('toast', message: 'IA desligada.');

            return;
        }

        $this->confirmingAiEnable = true;
    }

    public function aiEnableConfirmed(): void
    {
        $this->settings()->update(['ai_enabled' => true]);
        $this->ai_enabled = true;
        $this->confirmingAiEnable = false;
        $this->dispatch('toast', message: 'IA LIGADA.', type: 'error');
    }

    public function cancelAiEnable(): void
    {
        $this->confirmingAiEnable = false;
    }

    /**
     * Proativas P-1 — kill switch PROPRIO (independente do robo e da IA). LIGAR
     * abre confirmacao (mensagem proativa e o MAIOR risco de ban: o sistema
     * INICIA conversa). Desligar e instantaneo (freio de emergencia). Nasce OFF.
     * Nesta fase NAO existe caminho de disparo (P-3) — ligar so arma a jaula.
     */
    public function requestProactiveSwitch(): void
    {
        if ($this->settings()->proactive_enabled) {
            $this->settings()->update(['proactive_enabled' => false]);
            $this->proactive_enabled = false;
            $this->dispatch('toast', message: 'Proativas desligadas.');

            return;
        }

        $this->confirmingProactiveEnable = true;
    }

    public function proactiveEnableConfirmed(): void
    {
        $this->settings()->update(['proactive_enabled' => true]);
        $this->proactive_enabled = true;
        $this->confirmingProactiveEnable = false;
        $this->dispatch('toast', message: 'Proativas LIGADAS (disparo real so na P-3, com campanha aprovada).', type: 'error');
    }

    public function cancelProactiveEnable(): void
    {
        $this->confirmingProactiveEnable = false;
    }

    /**
     * P-1 — salva tetos/janela/palavra. ENDURECER salva direto; AFROUXAR (teto
     * diario acima de 20, semanal acima de 1, janela mais larga que a atual)
     * pede confirmacao — mesmo padrao do limiar da IA.
     */
    public function saveProactive(\App\Whatsapp\Proactive\OptoutFooterGuard $footerGuard): void
    {
        $this->validate([
            'proactive_daily_cap' => 'required|integer|min:1|max:200',
            'proactive_per_contact_weekly_cap' => 'required|integer|min:1|max:7',
            'proactive_window_start' => 'required|date_format:H:i',
            'proactive_window_end' => 'required|date_format:H:i|after:proactive_window_start',
            'proactive_optout_word' => 'required|string|min:2|max:40',
            'proactive_optout_footer' => 'required|string|max:500',
        ], [], [
            'proactive_daily_cap' => 'teto diario',
            'proactive_per_contact_weekly_cap' => 'limite por contato/semana',
            'proactive_window_start' => 'inicio da janela',
            'proactive_window_end' => 'fim da janela',
            'proactive_optout_word' => 'palavra de opt-out',
            'proactive_optout_footer' => 'rodape de saida',
        ]);

        // P-4: rodape padrao valido (obrigatorio + {palavra_sair} + sem segredo).
        // Valida contra a PALAVRA NOVA do form (que sera salva junto).
        $rodape = $footerGuard->check(app(\App\Tenancy\AccountContext::class)->id(), $this->proactive_optout_footer, trim($this->proactive_optout_word));
        if ($rodape['error'] !== null) {
            $this->addError('proactive_optout_footer', $rodape['error']);

            return;
        }
        if ($rodape['warning'] !== null) {
            $this->dispatch('toast', message: 'Aviso: ' . $rodape['warning'], type: 'error');
        }

        $s = $this->settings();
        $avisos = [];

        if ($this->proactive_daily_cap > (int) $s->proactive_daily_cap && $this->proactive_daily_cap > 20) {
            $avisos[] = sprintf('Teto diario sobe de %d para %d (acima do padrao seguro de 20/dia): mais mensagens INICIADAS por dia = mais risco de ban.', (int) $s->proactive_daily_cap, $this->proactive_daily_cap);
        }
        if ($this->proactive_per_contact_weekly_cap > (int) $s->proactive_per_contact_weekly_cap && $this->proactive_per_contact_weekly_cap > 1) {
            $avisos[] = sprintf('Limite por contato sobe de %d para %d por semana: contato abordado com mais frequencia = mais denuncia/bloqueio.', (int) $s->proactive_per_contact_weekly_cap, $this->proactive_per_contact_weekly_cap);
        }
        $startAtual = substr((string) $s->proactive_window_start, 0, 5);
        $endAtual = substr((string) $s->proactive_window_end, 0, 5);
        if ($this->proactive_window_start < $startAtual || $this->proactive_window_end > $endAtual) {
            $avisos[] = sprintf('Janela alargada (%s-%s -> %s-%s): mensagens iniciadas fora do horario comercial incomodam mais.', $startAtual, $endAtual, $this->proactive_window_start, $this->proactive_window_end);
        }

        if ($avisos !== []) {
            $this->proactiveRelaxWarnings = $avisos;
            $this->confirmingProactiveRelax = true;

            return;
        }

        $this->persistProactive();
    }

    public function proactiveRelaxConfirmed(): void
    {
        $this->confirmingProactiveRelax = false;
        $this->proactiveRelaxWarnings = [];
        $this->persistProactive();
    }

    /** Cancelar volta os campos pro que esta salvo (nada aplicado). */
    public function cancelProactiveRelax(): void
    {
        $this->confirmingProactiveRelax = false;
        $this->proactiveRelaxWarnings = [];
        $s = $this->settings();
        $this->proactive_daily_cap = (int) $s->proactive_daily_cap;
        $this->proactive_per_contact_weekly_cap = (int) $s->proactive_per_contact_weekly_cap;
        $this->proactive_window_start = substr((string) $s->proactive_window_start, 0, 5);
        $this->proactive_window_end = substr((string) $s->proactive_window_end, 0, 5);
        $this->proactive_optout_word = (string) $s->proactive_optout_word;
        $this->proactive_optout_footer = (string) $s->proactive_optout_footer;
    }

    /** CH-2 — sanidade LEVE sob demanda (nunca em loop): atualiza channels.status. */
    public function verificarCanal(int $id): void
    {
        $canal = \App\Models\Channel::query()->find($id);
        if (! $canal) {
            return;
        }

        $estado = app(\App\Channels\ProviderRegistry::class)->for($canal)->connectionState($canal);
        $mapa = ['connected' => 'connected', 'disconnected' => 'disconnected'];
        if (isset($mapa[$estado])) {
            $canal->update(['status' => $mapa[$estado]]);
        }
        $this->dispatch('toast', message: "Canal {$canal->instance}: {$estado}.", type: $estado === 'connected' ? 'success' : 'error');
    }

    private function persistProactive(): void
    {
        $this->settings()->update([
            'proactive_daily_cap' => $this->proactive_daily_cap,
            'proactive_per_contact_weekly_cap' => $this->proactive_per_contact_weekly_cap,
            'proactive_window_start' => $this->proactive_window_start . ':00',
            'proactive_window_end' => $this->proactive_window_end . ':00',
            'proactive_optout_word' => trim($this->proactive_optout_word),
            'proactive_optout_footer' => trim($this->proactive_optout_footer),
        ]);

        $this->dispatch('toast', message: 'Configuracoes das proativas salvas.');
    }

    /**
     * Fatia 4 — salva limiar + temas de aprovacao. ENDURECER (subir limiar, marcar
     * tema) salva direto; AFROUXAR (reduzir limiar pra baixo de 0.70, desmarcar
     * tema) pede confirmacao em modal (mesmo padrao do kill switch) porque libera
     * a IA a responder sozinha em mais casos. Mudancas valem so pra decisoes FUTURAS.
     */
    public function saveAi(): void
    {
        $this->validate([
            'ai_confidence_threshold' => 'required|numeric|min:0.50|max:0.95',
        ], [], ['ai_confidence_threshold' => 'limiar de confianca']);

        // So os 4 temas conhecidos (descarta qualquer valor estranho do payload).
        $this->ai_approval_topics = array_values(array_intersect(
            $this->ai_approval_topics,
            array_keys(self::AI_TOPIC_LABELS),
        ));

        $s = $this->settings();
        $avisos = [];

        if ($this->ai_confidence_threshold < (float) $s->ai_confidence_threshold
            && $this->ai_confidence_threshold < 0.70) {
            $avisos[] = sprintf(
                'Limiar de confianca reduzido de %.2f para %.2f (abaixo de 0.70): a IA vai responder sozinha com MENOS certeza.',
                (float) $s->ai_confidence_threshold,
                $this->ai_confidence_threshold,
            );
        }

        foreach (array_diff($s->aiApprovalTopics(), $this->ai_approval_topics) as $tema) {
            $avisos[] = 'Tema "' . (self::AI_TOPIC_LABELS[$tema] ?? $tema) . '" deixa de exigir aprovacao: a IA PODE responder sozinha nesse assunto.';
        }

        if ($avisos !== []) {
            $this->aiRelaxWarnings = $avisos;
            $this->confirmingAiRelax = true;

            return;
        }

        $this->persistAi();
    }

    public function aiRelaxConfirmed(): void
    {
        $this->confirmingAiRelax = false;
        $this->aiRelaxWarnings = [];
        $this->persistAi();
    }

    /** Cancelar volta os campos pro que esta salvo (nada aplicado). */
    public function cancelAiRelax(): void
    {
        $this->confirmingAiRelax = false;
        $this->aiRelaxWarnings = [];
        $s = $this->settings();
        $this->ai_confidence_threshold = (float) $s->ai_confidence_threshold;
        $this->ai_approval_topics = $s->aiApprovalTopics();
    }

    private function persistAi(): void
    {
        $this->settings()->update([
            'ai_confidence_threshold' => $this->ai_confidence_threshold,
            // Array EXPLICITO (pode ser vazio = nenhum tema). NULL = default (todos).
            'ai_approval_topics' => array_values($this->ai_approval_topics),
        ]);

        $this->dispatch('toast', message: 'Configuracoes da IA salvas (valem pra decisoes futuras).');
    }

    protected function rules(): array
    {
        return [
            'reply_policy' => 'required|in:allowlist,all',
            // Fatia 3 — SEGURANCA: o flow_id do cliente NUNCA e confiado cru. So
            // persiste fluxo DA CONTA ATIVA e HABILITADO (where account_id explicito
            // + escopo BelongsToAccount). Excecao unica: manter o valor JA salvo
            // (fluxo que foi desabilitado depois nao quebra o save da tela).
            'default_flow_id' => ['nullable', 'integer', function ($attr, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                $atual = $this->settings()->default_flow_id;
                if ($atual !== null && (int) $value === (int) $atual) {
                    return; // mantido — nao e escolha nova
                }
                $ok = \App\Models\Flow::query()
                    ->where('account_id', app(\App\Tenancy\AccountContext::class)->id())
                    ->where('enabled', true)
                    ->whereKey((int) $value)
                    ->exists();
                if (! $ok) {
                    $fail('Fluxo invalido — escolha um fluxo habilitado da sua conta.');
                }
            }],
            'window_start' => 'required|date_format:H:i',
            'window_end' => 'required|date_format:H:i',
            'min_interval_seconds' => 'required|integer|min:0',
            'per_minute_cap' => 'required|integer|min:1',
            'per_day_cap' => 'required|integer|min:1',
            'contact_rate_seconds' => 'required|integer|min:0',
            'delay_min_seconds' => 'required|integer|min:0',
            'delay_max_seconds' => 'required|integer|min:0|gte:delay_min_seconds',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $this->settings()->update([
            'reply_policy' => $this->reply_policy,
            'default_flow_id' => $this->default_flow_id ?: null, // 'Nenhum' grava null
            'window_start' => $this->window_start . ':00',
            'window_end' => $this->window_end . ':00',
            'min_interval_seconds' => $this->min_interval_seconds,
            'per_minute_cap' => $this->per_minute_cap,
            'per_day_cap' => $this->per_day_cap,
            'contact_rate_seconds' => $this->contact_rate_seconds,
            'delay_min_seconds' => $this->delay_min_seconds,
            'delay_max_seconds' => $this->delay_max_seconds,
            'skip_groups' => $this->skip_groups,
            'warmup_enabled' => $this->warmup_enabled,
            'window_enabled' => $this->window_enabled,
            'min_interval_enabled' => $this->min_interval_enabled,
            'per_minute_enabled' => $this->per_minute_enabled,
            'per_day_enabled' => $this->per_day_enabled,
            'contact_rate_enabled' => $this->contact_rate_enabled,
        ]);

        $this->salvo = true;
        $this->dispatch('toast', message: 'Configuracoes salvas.');
    }

    public function render(\App\Whatsapp\AutoReply\RuleResponder $responder)
    {
        // P-4: preview HONESTO do rodape — o MESMO renderizador do envio, com a
        // palavra que esta SALVA (o form pode ter valor ainda nao persistido).
        // CH-2: a conta lista TODOS os canais (Evolution + Cloud API), cada um
        // com URL de webhook mascarada (token nunca inteiro na tela).
        $canais = \App\Models\Channel::query()->orderBy('id')->get()->map(function ($c) {
            $mask = '(sem token)';
            if ($c->webhook_token) {
                $t = (string) $c->webhook_token;
                $rota = $c->provider === 'cloud_api' ? 'cloud' : 'evolution';
                $mask = "/webhook/{$rota}/" . substr($t, 0, 4) . '...' . substr($t, -4);
            }
            $c->setAttribute('webhook_mascarado', $mask);

            return $c;
        });

        // Fatia 3 — opcoes do fluxo padrao: SO fluxos HABILITADOS da conta ativa
        // (Flow escopado por BelongsToAccount). Edge: se o default salvo aponta um
        // fluxo desabilitado, exibe-o marcado "(desabilitado)" pra tela nao mentir
        // nem quebrar (re-selecionar outro habilitado ou 'Nenhum' resolve).
        $fluxosDisponiveis = \App\Models\Flow::query()->where('enabled', true)->orderBy('name')->get(['id', 'name']);
        $fluxoAtualDesabilitado = ($this->default_flow_id && ! $fluxosDisponiveis->contains('id', $this->default_flow_id))
            ? \App\Models\Flow::query()->find($this->default_flow_id)
            : null;

        return view('livewire.configuracoes', [
            'footerPreview' => $responder->render($this->proactive_optout_footer),
            'canais' => $canais,
            'fluxosDisponiveis' => $fluxosDisponiveis,
            'fluxoAtualDesabilitado' => $fluxoAtualDesabilitado,
        ]);
    }
}
