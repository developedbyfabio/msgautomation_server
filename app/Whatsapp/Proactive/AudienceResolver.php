<?php

namespace App\Whatsapp\Proactive;

use App\Models\Card;
use App\Models\Contact;
use App\Tenancy\AccountScope;
use Illuminate\Support\Collection;

/**
 * Proativas P-2 — resolve o PUBLICO de uma campanha AGORA (retrato do momento;
 * o snapshot so congela na aprovacao). API por parametro (bypass nomeado).
 *
 * FILTRO ESTRUTURAL (nao e de UI — nenhum caminho escapa): contato so entra com
 * `proactive_opt_in` E `auto_reply_mode != off` E nao-grupo. Os EXCLUIDOS saem
 * listados com motivo (sem_opt_in | off | grupo) pro preview mostrar com
 * honestidade quem NAO vai receber e por que.
 *
 * Tipos: tags (QUALQUER uma das tags) | coluna_kanban (cards atuais na coluna) |
 * contatos (lista explicita).
 */
class AudienceResolver
{
    /**
     * @param  array{tag_ids?:array<int,int>,column_id?:int,contact_ids?:array<int,int>}  $config
     * @return array{eligiveis: Collection<int,Contact>, excluidos: array<int,array{contact:Contact,motivo:string}>}
     */
    public function resolve(int $accountId, string $audienceType, array $config): array
    {
        $candidatos = $this->candidatos($accountId, $audienceType, $config);

        $eligiveis = collect();
        $excluidos = [];
        foreach ($candidatos as $contact) {
            if (str_ends_with((string) $contact->remote_jid, '@g.us')) {
                $excluidos[] = ['contact' => $contact, 'motivo' => 'grupo'];

                continue;
            }
            if ($contact->auto_reply_mode === 'off') {
                $excluidos[] = ['contact' => $contact, 'motivo' => 'off'];

                continue;
            }
            if (! $contact->proactive_opt_in) {
                $excluidos[] = ['contact' => $contact, 'motivo' => 'sem_opt_in'];

                continue;
            }
            $eligiveis->push($contact);
        }

        return ['eligiveis' => $eligiveis->unique('id')->values(), 'excluidos' => $excluidos];
    }

    /** @return Collection<int,Contact> candidatos ANTES do filtro estrutural */
    private function candidatos(int $accountId, string $audienceType, array $config): Collection
    {
        // F2 — contato de SISTEMA (Alertas de Infra) nunca entra em campanha.
        $base = Contact::withoutAccountScope()->where('account_id', $accountId)->where('is_system', false);

        return match ($audienceType) {
            // Por TAG: quem tem QUALQUER uma das tags (da MESMA conta — join valida).
            'tags' => $base->whereHas('tags', function ($q) use ($config, $accountId) {
                $q->withoutGlobalScope(AccountScope::class)
                    ->where('tags.account_id', $accountId)
                    ->whereIn('tags.id', array_map('intval', $config['tag_ids'] ?? []));
            })->orderBy('id')->get(),

            // Por COLUNA do Kanban: os cards ATUAIS na coluna (retrato).
            'coluna_kanban' => $base->whereIn('id', Card::withoutAccountScope()
                ->where('account_id', $accountId)
                ->where('column_id', (int) ($config['column_id'] ?? 0))
                ->pluck('contact_id'))->orderBy('id')->get(),

            // Lista EXPLICITA de contatos (da conta).
            'contatos' => $base->whereIn('id', array_map('intval', $config['contact_ids'] ?? []))
                ->orderBy('id')->get(),

            default => collect(),
        };
    }
}
