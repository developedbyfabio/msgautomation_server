<?php

namespace App\Auth;

use App\Models\User;
use App\Tenancy\AccountContext;

/**
 * Fatia 22 — FONTE UNICA do mapa de permissoes por area (rota => papel minimo)
 * e da decisao de acesso. Consumida pelo middleware de rota (enforcement), pela
 * ocultacao cosmetica do menu e pelos gates de acao Livewire (defesa em
 * profundidade). Nada de RBAC granular: dois papeis de conta (owner|operador)
 * + is_platform_admin (super-admin, ortogonal — passa tudo).
 *
 * Papel e POR CONTA (pivot account_user.role): owner em A nao e nada em B.
 * Sem vinculo: em producao o SetAccountContext ja barra (403); no modo fase-1
 * (tenancy.single_account_fallback, suite legada) o usuario unico E o dono —
 * tratado como owner (semantica fase-1 preservada, registrado no relatorio).
 */
class AreaAccess
{
    /**
     * rota (name) => papel minimo. 'owner' = so dono/super-admin; 'operador' =
     * qualquer usuario vinculado. Itens marcados [A CONFIRMAR] no relatorio:
     * regras/fluxos/variaveis/conhecimento/campanhas ficaram owner-only
     * (default seguro: na duvida, mais restritivo); conexao ficou operador+
     * (o gate whatsapp.connected redireciona pra la quando o canal cai — a
     * secretaria precisa reconectar o QR; credenciais tem gate de acao).
     */
    public const MAP = [
        // Operacao do dia a dia (operador+):
        'painel' => 'operador',
        'conversas' => 'operador',
        'kanban' => 'operador',
        'contatos' => 'operador',
        'revisao' => 'operador',
        'perfil' => 'operador',
        'conexao' => 'operador',
        // Gestao/tecnico (owner+):
        'senhas' => 'owner',
        'variaveis' => 'owner',
        'regras' => 'owner',
        'fluxos' => 'owner',
        'conhecimento' => 'owner',
        'campanhas' => 'owner',
        'logs' => 'owner',
        'configuracoes' => 'owner',
        // /admin/*: fora deste mapa — continua com platform.admin + 2FA (Prompts 22/29).
    ];

    /** O usuario pode acessar uma area com este papel minimo, NA conta dada? */
    public static function allows(?User $user, int $accountId, string $minimo): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->is_platform_admin) {
            return true; // super-admin: ortogonal ao papel de conta
        }

        $role = $user->roleIn($accountId);
        if ($role === null) {
            // Fase-1/fallback (suite legada): usuario unico e o dono. Em producao
            // o flag e false e o SetAccountContext ja barrou usuario sem vinculo.
            return (bool) config('tenancy.single_account_fallback', false);
        }

        return $minimo === 'operador' || $role === 'owner';
    }

    /**
     * Gate de ACAO (defesa em profundidade): acoes Livewire sensiveis chamam
     * isto no topo — a rota ja barra a pagina, mas acao Livewire e forjavel.
     * So age com usuario LOGADO: a suite legada aciona componentes sem auth
     * (nas rotas reais o middleware 'auth' e persistente do Livewire garantem
     * que sempre ha usuario).
     */
    public static function authorizeOwnerAction(): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        try {
            $accountId = app(AccountContext::class)->id();
        } catch (\App\Tenancy\MissingAccountContextException) {
            abort(403, 'Acao restrita ao dono da conta.');
        }

        abort_unless(self::allows($user, $accountId, 'owner'), 403, 'Acao restrita ao dono da conta.');
    }
}
