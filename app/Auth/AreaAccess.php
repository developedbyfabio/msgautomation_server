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
        // Fatia 23 — VIEW-ONLY do operador (decisao do dono): operador VE
        // campanhas e conhecimento (rota liberada); a ESCRITA e barrada pelo
        // EDIT_MAP + gates de acao (authorizeEditAction).
        'campanhas' => 'operador',
        'conhecimento' => 'operador',
        // Gestao/tecnico (owner+; regras/fluxos/variaveis = engenharia
        // estrutural — operador nem ve, inalterado da Fatia 22):
        'senhas' => 'owner',
        'variaveis' => 'owner',
        'regras' => 'owner',
        'fluxos' => 'owner',
        'logs' => 'owner',
        'configuracoes' => 'owner',
        'billing' => 'owner', // Fatia 26: assinatura/pagamento e decisao do dono
        'servidores' => 'owner', // Servidores S1: monitoramento de infra — ferramenta interna do dono
        'servidores.alertas' => 'owner', // Servidores S2: regras/limiares
        'servidores.incidentes' => 'owner', // Servidores S2: incidentes + ack

        // /admin/*: fora deste mapa — continua com platform.admin + 2FA (Prompts 22/29).
    ];

    /**
     * Fatia 23 — areas com distincao VER x EDITAR: o papel minimo pra ESCREVER.
     * Áreas fora deste mapa: editar = mesmo papel de acessar (MAP).
     */
    public const EDIT_MAP = [
        'campanhas' => 'owner',
        'conhecimento' => 'owner',
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

    // ---- Fatia 23: ver x editar (campanhas/conhecimento) ----------------------

    /** O usuario pode EDITAR a area (nao so ver)? */
    public static function canEdit(?User $user, int $accountId, string $area): bool
    {
        return self::allows($user, $accountId, self::EDIT_MAP[$area] ?? self::MAP[$area] ?? 'operador');
    }

    /**
     * Versao de UI/componente (usuario + conta ATUAIS): esconde botoes de
     * escrita pro operador (cosmetico — a barreira real e o gate abaixo).
     * Sem usuario/contexto (suite legada): true, como no authorizeOwnerAction.
     */
    public static function canEditArea(string $area): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return true;
        }
        try {
            $accountId = app(AccountContext::class)->id();
        } catch (\App\Tenancy\MissingAccountContextException) {
            return true;
        }

        return self::canEdit($user, $accountId, $area);
    }

    /** Gate de ESCRITA por area — rejeita operador MESMO com a acao forjada. */
    public static function authorizeEditAction(string $area): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        try {
            $accountId = app(AccountContext::class)->id();
        } catch (\App\Tenancy\MissingAccountContextException) {
            abort(403, 'Somente leitura para o seu perfil.');
        }

        abort_unless(self::canEdit($user, $accountId, $area), 403, 'Somente leitura para o seu perfil.');
    }
}
