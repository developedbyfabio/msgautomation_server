<?php

namespace App\Kanban;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardRule;

/**
 * Kanban K-1 — provisiona o board DEFAULT de uma conta (D4 aprovada): colunas
 * Novo / Em atendimento / Aguardando resposta / Resolvido / Reativacao + regras
 * de movimento minimas. Idempotente (conta que ja tem board default = no-op).
 * Chamado pela migration (contas existentes) e pelo hook Account::created
 * (contas futuras). Tudo com account_id EXPLICITO (nao depende de contexto).
 *
 * REGRAS DEFAULT (documentadas; editaveis na UI da K-2):
 *  1. mensagem_recebida + sem card            -> cria o card em NOVO
 *  2. mensagem_recebida + card em Resolvido   -> volta pra NOVO (reabertura)
 *  3. resposta_enviada  + fora de Em atendim. -> move pra EM ATENDIMENTO
 *  4. envio_manual      + fora de Em atendim. -> move pra EM ATENDIMENTO
 * Sem regra por tempo aqui (reativacao e arco das proativas — coluna existe,
 * regra automatica nao). fluxo_no e ia_decisao sao emitidos mas sem regra default.
 */
class BoardProvisioner
{
    /** Colunas D4: slug estavel => rotulo. */
    public const DEFAULT_COLUMNS = [
        'novo' => 'Novo',
        'em_atendimento' => 'Em atendimento',
        'aguardando' => 'Aguardando resposta',
        'resolvido' => 'Resolvido',
        'reativacao' => 'Reativacao',
    ];

    public function ensureDefaultBoard(int $accountId): Board
    {
        $existente = Board::withoutAccountScope()
            ->where('account_id', $accountId)->where('is_default', true)->first();
        if ($existente !== null) {
            return $existente;
        }

        $board = Board::create(['account_id' => $accountId, 'name' => 'Atendimento', 'is_default' => true]);

        $cols = [];
        $pos = 0;
        foreach (self::DEFAULT_COLUMNS as $slug => $name) {
            $cols[$slug] = BoardColumn::create([
                'board_id' => $board->id, 'slug' => $slug, 'name' => $name, 'position' => $pos++,
            ]);
        }

        $regras = [
            ['mensagem_recebida', ['card' => 'absent'], 'novo'],
            ['mensagem_recebida', ['card_in_column' => 'resolvido'], 'novo'],
            ['resposta_enviada', ['not_in_column' => 'em_atendimento'], 'em_atendimento'],
            ['envio_manual', ['not_in_column' => 'em_atendimento'], 'em_atendimento'],
        ];
        foreach ($regras as $i => [$evento, $cond, $destino]) {
            BoardRule::create([
                'account_id' => $accountId,
                'board_id' => $board->id,
                'event_type' => $evento,
                'conditions' => $cond,
                'action_type' => 'move_column',
                'to_column_id' => $cols[$destino]->id,
                'active' => true,
                'is_default' => true,
                'position' => $i,
            ]);
        }

        return $board;
    }
}
