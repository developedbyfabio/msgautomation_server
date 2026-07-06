<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Variaveis V-1 — placeholder configuravel da conta, resolvido SO no envio pelo
 * renderizador unico (RuleResponder). Tipos: static | horario | dia_semana
 * (fallback valor_padrao obrigatorio nos condicionais; fuso SP).
 *
 * QUALQUER escrita invalida o cache de resolucao da conta (observer) — writer,
 * seed ou teste. Nomes RESERVADOS nunca viram variavel custom.
 */
class Variable extends Model
{
    use BelongsToAccount;

    public const TYPES = ['static', 'horario', 'dia_semana'];

    /** Reservados (nativos + cofre + sistema); comparacao com fold de acento/caixa. */
    public const RESERVED = ['nome', 'saudacao', 'data', 'hora', 'senha', 'palavra_sair'];

    protected $fillable = [
        'account_id',
        'name',      // slug [a-z0-9_]+, unico por conta
        'type',      // static | horario | dia_semana
        'config',
        'is_system', // saudacao: edita textos/faixas; nunca renomeia/exclui/desativa
        'active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Cache de resolucao SEMPRE invalidado em qualquer escrita (save do writer,
        // provisioner, teste) — o renderizador nunca ve estado velho.
        $limpar = fn (Variable $v) => Cache::forget('variaveis:' . $v->account_id);
        static::saved($limpar);
        static::deleted($limpar);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** O nome e reservado? (fold de acento + caixa: "Saudacão" conta como reservado) */
    public static function isReserved(string $name): bool
    {
        $norm = mb_strtolower(Str::ascii(trim($name)), 'UTF-8');

        return in_array($norm, self::RESERVED, true);
    }

    /**
     * Referencias {algo} de um texto que NAO sao nativas nem variaveis ATIVAS da
     * conta (aviso nos writers — a licao do bug da saudacao crua). {senha:...}
     * nao casa \w+ e fica fora (tratada pelo cofre).
     *
     * @return array<int,string>
     */
    public static function unknownRefs(int $accountId, string $texto): array
    {
        preg_match_all('/\{(\w+)\}/u', $texto, $m);
        $refs = array_unique(array_map(fn ($r) => mb_strtolower($r, 'UTF-8'), $m[1] ?? []));

        $nativas = ['nome', 'saudacao', 'data', 'hora', 'palavra_sair'];
        $ativas = $refs !== []
            ? self::withoutAccountScope()
                ->where('account_id', $accountId)
                ->where('active', true)
                ->pluck('name')
                ->all()
            : [];
        $desconhecidas = array_values(array_diff($refs, $nativas, $ativas));

        // Fatia 15 — {kb:slug} tambem e validado (mesma elegibilidade do
        // resolver: referenciavel E sem {senha:} no conteudo — o que nao
        // resolveria no envio vira aviso aqui, como 'kb:slug').
        preg_match_all('/\{kb:([a-z0-9_-]+)\}/iu', $texto, $mkb);
        $kbRefs = array_unique(array_map(fn ($r) => mb_strtolower($r, 'UTF-8'), $mkb[1] ?? []));
        if ($kbRefs !== []) {
            $vault = app(\App\Whatsapp\Secrets\SecretVault::class);
            $resolviveis = Knowledge::query()->referenciavel($accountId)
                ->whereIn('slug', $kbRefs)
                ->get(['slug', 'content'])
                ->filter(fn ($k) => ! $vault->hasRef((string) $k->content))
                ->pluck('slug')
                ->all();
            foreach (array_diff($kbRefs, $resolviveis) as $slug) {
                $desconhecidas[] = 'kb:' . $slug;
            }
        }

        return $desconhecidas;
    }
}
