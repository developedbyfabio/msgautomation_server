<?php

namespace App\Kanban;

use App\Models\Board;
use App\Models\BoardRule;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Contact;
use App\Tenancy\AccountContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Kanban K-1 — motor de movimento (headless). OBSERVADOR PURO: recebe um evento
 * de dominio ja ocorrido e move/cria o card correspondente. NUNCA envia mensagem,
 * NUNCA decide resposta, NUNCA toca no pipeline reativo.
 *
 * Semantica:
 *  - grupos (@g.us) ficam FORA (coerente com o robo);
 *  - contato desconhecido = sem card (o Kanban nao cria contato — so observa);
 *  - regras ativas do board default avaliadas em ordem (position): a PRIMEIRA que
 *    casa move/cria (first-match); nenhuma casando = so atualiza last_interaction;
 *  - mover pra coluna onde o card JA esta = no-op (sem transicao duplicada);
 *  - IDEMPOTENCIA de re-entrega: unique (card, event_type, event_ref) — o mesmo
 *    evento nunca gera duas transicoes; corrida de criacao cai no unique
 *    (board, contact) e reusa o card existente.
 */
class BoardEngine
{
    /** Tipos de evento estaveis (usados em board_rules e card_transitions). */
    public const EVENT_TYPES = [
        \App\Events\IncomingMessageStored::class => 'mensagem_recebida',
        \App\Events\AutoReplySent::class => 'resposta_enviada',
        \App\Events\ManualMessageSent::class => 'envio_manual',
        \App\Events\FlowNodeReached::class => 'fluxo_no',
        \App\Events\AiDecisionRecorded::class => 'ia_decisao',
    ];

    public function __construct(private AccountContext $context)
    {
    }

    /**
     * Aplica um evento ao board default da conta.
     *
     * @param  string  $eventType  tipo estavel (EVENT_TYPES)
     * @param  int  $eventRef  id do registro de origem (incoming/log/decisao/sessao)
     * @param  ?string  $direction  'in' | 'out' | null (nao muda)
     */
    public function apply(string $eventType, int $accountId, string $remoteJid, int $eventRef, ?string $direction = null): void
    {
        if (str_ends_with($remoteJid, '@g.us')) {
            return; // Kanban e de conversas com PESSOAS (grupos fora, como no robo)
        }

        $this->context->runAs($accountId, function () use ($eventType, $remoteJid, $eventRef, $direction) {
            $contact = Contact::query()->where('remote_jid', $remoteJid)->first();
            if ($contact === null) {
                return; // observador puro: nao cria contato
            }

            $board = Board::query()->where('is_default', true)->first();
            if ($board === null) {
                return;
            }

            $card = Card::query()->where('board_id', $board->id)->where('contact_id', $contact->id)->first();

            // Re-entrega do MESMO evento: ja gerou transicao neste card -> so touch.
            if ($card !== null && CardTransition::query()
                ->where('card_id', $card->id)
                ->where('event_type', $eventType)
                ->where('event_ref', $eventRef)
                ->exists()) {
                $this->touch($card, $direction);

                return;
            }

            // First-match nas regras ativas do board pro evento, em ordem.
            $rules = BoardRule::query()
                ->where('board_id', $board->id)
                ->where('event_type', $eventType)
                ->where('active', true)
                ->orderBy('position')
                ->get();

            foreach ($rules as $rule) {
                if (! $this->matches($rule, $card)) {
                    continue;
                }

                $card = $this->moveOrCreate($board->id, (int) $contact->id, $card, $rule, $eventType, $eventRef);
                break;
            }

            if ($card !== null) {
                $this->touch($card, $direction);
            }
        });
    }

    /** Condicoes minimas (JSON) da regra contra o estado atual do card. */
    private function matches(BoardRule $rule, ?Card $card): bool
    {
        $cond = (array) $rule->conditions;

        if (($cond['card'] ?? null) === 'absent' && $card !== null) {
            return false;
        }
        if (($cond['card'] ?? null) === 'present' && $card === null) {
            return false;
        }
        if (isset($cond['card_in_column'])) {
            if ($card === null || $card->column?->slug !== $cond['card_in_column']) {
                return false;
            }
        }
        if (isset($cond['not_in_column'])) {
            // Card ausente "nao esta" na coluna -> casa (cria direto no destino).
            if ($card !== null && $card->column?->slug === $cond['not_in_column']) {
                return false;
            }
        }

        return true;
    }

    /** Cria o card no destino ou move o existente, registrando a transicao com causa. */
    private function moveOrCreate(int $boardId, int $contactId, ?Card $card, BoardRule $rule, string $eventType, int $eventRef): Card
    {
        if ($card === null) {
            try {
                $card = Card::create([
                    'board_id' => $boardId,
                    'contact_id' => $contactId,
                    'column_id' => $rule->to_column_id,
                ]);
                $this->registrar($card, null, $rule, $eventType, $eventRef);

                return $card;
            } catch (UniqueConstraintViolationException) {
                // Corrida: outro job criou primeiro -> reusa e segue como movimento.
                $card = Card::query()->where('board_id', $boardId)->where('contact_id', $contactId)->firstOrFail();
            }
        }

        // Ja esta na coluna destino -> no-op (sem transicao duplicada).
        if ((int) $card->column_id === (int) $rule->to_column_id) {
            return $card;
        }

        $de = (int) $card->column_id;
        $card->update(['column_id' => $rule->to_column_id]);
        $this->registrar($card, $de, $rule, $eventType, $eventRef);

        return $card;
    }

    private function registrar(Card $card, ?int $fromColumnId, BoardRule $rule, string $eventType, int $eventRef): void
    {
        try {
            CardTransition::create([
                'card_id' => $card->id,
                'from_column_id' => $fromColumnId,
                'to_column_id' => $rule->to_column_id,
                'cause' => 'regra',
                'board_rule_id' => $rule->id,
                'event_type' => $eventType,
                'event_ref' => $eventRef,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Re-entrega em corrida: a transicao deste evento ja existe -> mantem.
        }
    }

    private function touch(Card $card, ?string $direction): void
    {
        $card->forceFill([
            'last_interaction_at' => now(),
            'last_direction' => $direction ?? $card->last_direction,
        ])->save();
    }
}
