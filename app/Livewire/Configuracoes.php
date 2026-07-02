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
        $this->window_start = substr((string) $s->window_start, 0, 5);
        $this->window_end = substr((string) $s->window_end, 0, 5);
        $this->min_interval_seconds = (int) $s->min_interval_seconds;
        $this->per_minute_cap = (int) $s->per_minute_cap;
        $this->per_day_cap = (int) $s->per_day_cap;
        $this->contact_rate_seconds = (int) $s->contact_rate_seconds;
        $this->delay_min_seconds = (int) $s->delay_min_seconds;
        $this->delay_max_seconds = (int) $s->delay_max_seconds;
        $this->skip_groups = (bool) $s->skip_groups;
        $this->warmup_enabled = (bool) $s->warmup_enabled;
        $this->window_enabled = (bool) $s->window_enabled;
        $this->min_interval_enabled = (bool) $s->min_interval_enabled;
        $this->per_minute_enabled = (bool) $s->per_minute_enabled;
        $this->per_day_enabled = (bool) $s->per_day_enabled;
        $this->contact_rate_enabled = (bool) $s->contact_rate_enabled;
        $this->ai_enabled = (bool) $s->ai_enabled;
        $this->ai_confidence_threshold = (float) $s->ai_confidence_threshold;
        $this->ai_approval_topics = $s->aiApprovalTopics();
    }

    private function settings(): AutoReplySetting
    {
        $account = Account::query()->oldest('id')->first()
            ?? Account::create(['name' => config('app.name', 'msgautomation')]);

        return AutoReplySetting::firstOrCreate(['account_id' => $account->id]);
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

    public function render()
    {
        return view('livewire.configuracoes');
    }
}
