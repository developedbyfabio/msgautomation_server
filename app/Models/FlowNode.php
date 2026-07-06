<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowNode extends Model
{
    protected $fillable = ['flow_id', 'parent_node_id', 'kind', 'message', 'ordem', 'display_number'];

    /**
     * Fatia 17 — paleta FIXA de 12 cores de identidade (dot do no), bem
     * distinguiveis entre si e com par claro/escuro por tema. Deterministica:
     * cor = paleta[(display_number - 1) % 12] — mesmo no, mesma cor, sempre;
     * zero persistencia/escolha. A cor NUNCA e o unico indicador: o #N
     * acompanha o dot em todos os pontos. Strings literais de proposito
     * (o scanner do Tailwind v4 varre qualquer arquivo nao-gitignored).
     */
    public const IDENTITY_COLORS = [
        'bg-red-500 dark:bg-red-400',
        'bg-orange-500 dark:bg-orange-400',
        'bg-amber-500 dark:bg-amber-400',
        'bg-lime-500 dark:bg-lime-400',
        'bg-green-500 dark:bg-green-400',
        'bg-teal-500 dark:bg-teal-400',
        'bg-cyan-500 dark:bg-cyan-400',
        'bg-blue-500 dark:bg-blue-400',
        'bg-indigo-500 dark:bg-indigo-400',
        'bg-violet-500 dark:bg-violet-400',
        'bg-fuchsia-500 dark:bg-fuchsia-400',
        'bg-rose-500 dark:bg-rose-400',
    ];

    protected static function booted(): void
    {
        // Fatia 17 — numeracao POR FLUXO (mata o "fluxo 5 com no #20": a PK e
        // auto-increment GLOBAL da tabela e vazava pra UI). Choke point unico
        // (padrao do slug da Fatia 15): cobre editor, templates, duplicacao e
        // seed. ESTAVEL: deletar nao renumera (buracos ficam, como issue
        // tracker); o proximo e sempre max+1 DENTRO do fluxo.
        static::creating(function (self $n) {
            if ($n->display_number === null) {
                $n->display_number = (int) self::where('flow_id', $n->flow_id)->max('display_number') + 1;
            }
        });
    }

    /** Fatia 17 — classes do dot de identidade (ver IDENTITY_COLORS). */
    public function identityColor(): string
    {
        return self::IDENTITY_COLORS[(max(1, (int) $this->display_number) - 1) % count(self::IDENTITY_COLORS)];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FlowOption::class)->orderBy('ordem')->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->kind === 'final';
    }

    /** Fatia 5 — no de HANDOFF pra humano (kind e string(16) no banco: aditivo, sem migration). */
    public function isHandoff(): bool
    {
        return $this->kind === 'handoff';
    }
}
