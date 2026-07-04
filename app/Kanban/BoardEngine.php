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
     * SEMANTICA DAS ACOES (T-1, documentada e testada):
     *  - move_column: FIRST-MATCH — so a primeira regra de coluna que casa move;
     *  - add_tag/remove_tag: CUMULATIVAS — TODAS as regras de tag que casam aplicam
     *    (antes ou depois do move, na ordem da lista).
     *
     * @param  string  $eventType  tipo estavel (EVENT_TYPES)
     * @param  int  $eventRef  id do registro de origem (incoming/log/decisao/sessao)
     * @param  ?string  $direction  'in' | 'out' | null (nao muda)
     * @param  array{intent?:?string,acao?:?string}  $meta  extras do evento (condicao por intent)
     */
    public function apply(string $eventType, int $accountId, string $remoteJid, int $eventRef, ?string $direction = null, array $meta = []): void
    {
        if (str_ends_with($remoteJid, '@g.us')) {
            return; // Kanban e de conversas com PESSOAS (grupos fora, como no robo)
        }

        $this->context->runAs($accountId, function () use ($eventType, $remoteJid, $eventRef, $direction, $meta) {
            $contact = Contact::query()->where('remote_jid', $remoteJid)->first();
            if ($contact === null) {
                return; // observador puro: nao cria contato
            }

            $board = Board::query()->where('is_default', true)->first();
            if ($board === null) {
                return;
            }

            $card = Card::query()->where('board_id', $board->id)->where('contact_id', $contact->id)->first();

            // Re-entrega do MESMO evento: ja gerou transicao neste card -> so touch
            // (acoes de tag ja aplicadas na 1a execucao; pivo unique segura o resto).
            if ($card !== null && CardTransition::query()
                ->where('card_id', $card->id)
                ->where('event_type', $eventType)
                ->where('event_ref', $eventRef)
                ->exists()) {
                $this->touch($card, $direction);

                return;
            }

            $rules = BoardRule::query()
                ->where('board_id', $board->id)
                ->where('event_type', $eventType)
                ->where('active', true)
                ->orderBy('position')
                ->get();

            $moveu = false;
            foreach ($rules as $rule) {
                if (! $this->matches($rule, $card, $meta)) {
                    continue;
                }

                if (($rule->action_type ?: 'move_column') === 'move_column') {
                    if ($moveu || $rule->to_column_id === null) {
                        continue; // first-match: a primeira coluna vence; sem destino = invalida
                    }
                    $card = $this->moveOrCreate($board->id, (int) $contact->id, $card, $rule, $eventType, $eventRef);
                    $moveu = true;
                } else {
                    // add_tag / remove_tag: cumulativo (todas as que casam).
                    $this->applyTagAction($rule, $contact);
                }
            }

            if ($card !== null) {
                $this->touch($card, $direction);
            }
        });
    }

    /**
     * Fatia 5 — movimento DETERMINISTICO por acao de SISTEMA (handoff): mesma
     * semantica do motor (board default, card por contato, no-op na mesma coluna,
     * idempotencia por (card, event_type, event_ref), touch), enderecado por SLUG
     * de coluna — nao depende de BoardRule por conta (regras sao dados por conta;
     * o handoff precisa mover SEMPRE). Cria o card se nao existir (diferente do
     * observador de regras: aqui a acao e do proprio sistema, nao observacao).
     */
    public function moveToColumnSlug(string $slug, int $accountId, string $remoteJid, string $eventType, int $eventRef): void
    {
        if (str_ends_with($remoteJid, '@g.us')) {
            return; // Kanban e de conversas com pessoas
        }

        $this->context->runAs($accountId, function () use ($slug, $remoteJid, $eventType, $eventRef) {
            $contact = Contact::query()->where('remote_jid', $remoteJid)->first();
            if ($contact === null) {
                return;
            }

            $board = Board::query()->where('is_default', true)->first();
            $col = $board?->columns()->where('slug', $slug)->first();
            if ($board === null || $col === null) {
                return;
            }

            $card = Card::query()->where('board_id', $board->id)->where('contact_id', $contact->id)->first();

            // Idempotencia de re-entrega: este evento ja moveu este card -> no-op.
            if ($card !== null && CardTransition::query()
                ->where('card_id', $card->id)
                ->where('event_type', $eventType)
                ->where('event_ref', $eventRef)
                ->exists()) {
                return;
            }

            $registrar = function (?int $de) use (&$card, $col, $eventType, $eventRef) {
                try {
                    CardTransition::create([
                        'card_id' => $card->id,
                        'from_column_id' => $de,
                        'to_column_id' => $col->id,
                        'cause' => 'handoff',
                        'event_type' => $eventType,
                        'event_ref' => $eventRef,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // corrida de re-entrega: a transicao deste evento ja existe
                }
            };

            if ($card === null) {
                try {
                    $card = Card::create(['board_id' => $board->id, 'contact_id' => $contact->id, 'column_id' => $col->id]);
                    $registrar(null);
                    $this->touch($card, null);

                    return;
                } catch (UniqueConstraintViolationException) {
                    // corrida: outro job criou primeiro -> reusa e segue como movimento.
                    $card = Card::query()->where('board_id', $board->id)->where('contact_id', $contact->id)->firstOrFail();
                }
            }

            if ((int) $card->column_id === (int) $col->id) {
                $this->touch($card, null);

                return; // ja na coluna destino: sem transicao duplicada
            }

            $de = (int) $card->column_id;
            $card->update(['column_id' => $col->id]);
            $registrar($de);
            $this->touch($card, null);
        });
    }

    /** Condicoes minimas (JSON) da regra contra o estado atual do card + meta do evento. */
    private function matches(BoardRule $rule, ?Card $card, array $meta = []): bool
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
        // T-1: condicao por INTENT (evento ia_decisao): casa quando a IA RESPONDEU
        // (acima do limiar) com o intent exato — ex.: "pedir_pix" -> tag.
        if (isset($cond['intent'])) {
            if (($meta['intent'] ?? null) !== $cond['intent'] || ($meta['acao'] ?? null) !== 'respondeu') {
                return false;
            }
        }

        return true;
    }

    /** Aplica/remove a tag da regra no CONTATO (origem rastreada; idempotente). */
    private function applyTagAction(BoardRule $rule, Contact $contact): void
    {
        if ($rule->tag_id === null) {
            return; // alvo removido/invalido -> regra inerte
        }

        if ($rule->action_type === 'remove_tag') {
            $contact->tags()->detach($rule->tag_id);

            return;
        }

        // add_tag — origem: 'ai_intent' quando a condicao e por intent (ref = intent);
        // senao 'board_rule' (ref = id da regra). Pivo UNIQUE = idempotente.
        $cond = (array) $rule->conditions;
        $origin = isset($cond['intent']) ? 'ai_intent' : 'board_rule';
        $ref = isset($cond['intent']) ? (string) $cond['intent'] : (string) $rule->id;

        try {
            $contact->tags()->attach($rule->tag_id, ['origin' => $origin, 'origin_ref' => $ref]);
        } catch (UniqueConstraintViolationException) {
            // Tag ja aplicada -> no-op (re-aplicacao/re-entrega).
        }
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
