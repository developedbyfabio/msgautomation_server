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

    public bool $salvo = false;

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
    }

    private function settings(): AutoReplySetting
    {
        $account = Account::query()->oldest('id')->first()
            ?? Account::create(['name' => config('app.name', 'msgautomation')]);

        return AutoReplySetting::firstOrCreate(['account_id' => $account->id]);
    }

    /** Kill switch flipa INSTANTANEO (sem precisar do botao Salvar). */
    public function toggleKillSwitch(): void
    {
        $s = $this->settings();
        $s->update(['enabled' => ! $s->enabled]);
        $this->enabled = (bool) $s->enabled;
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
        ]);

        $this->salvo = true;
    }

    public function render()
    {
        return view('livewire.configuracoes');
    }
}
