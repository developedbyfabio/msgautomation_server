<?php

namespace App\Ai;

use App\Models\Contact;
use App\Models\Knowledge;
use App\Whatsapp\Secrets\SecretVault;

/**
 * Fatia 4 — caminho OFICIAL (unico) de gravacao de entrada da base de conhecimento,
 * usado pelo CRUD de /conhecimento e pela promocao "virar entrada" do /revisao.
 *
 * Guarda de segredo (coerente com S5 das regras): conteudo com {senha:...} exige
 * CONTATOS RESTRITOS (a entrada nao pode valer "pra todos com IA" — a resposta
 * fundamentada nela levaria a referencia a qualquer contato; restringir espelha o
 * escopo obrigatorio das regras com senha). A guarda de envio da Fatia 2 segue
 * valendo por cima: resposta com {senha:} nunca e auto-enviada (escala).
 */
class KnowledgeWriter
{
    public function __construct(private SecretVault $vault)
    {
    }

    /**
     * Cria/atualiza a entrada. Retorna ['knowledge' => Knowledge|null,
     * 'errors' => [campo => mensagem]] — com erros, NADA e persistido.
     *
     * @param array{title:string,content:string,sensitivity:string,active:bool,contact_ids:array<int,int>} $dados
     * @return array{knowledge: ?Knowledge, errors: array<string,string>}
     */
    public function save(int $accountId, array $dados, ?int $editingId = null): array
    {
        $title = trim((string) ($dados['title'] ?? ''));
        $content = trim((string) ($dados['content'] ?? ''));
        $sensitivity = in_array($dados['sensitivity'] ?? '', Knowledge::SENSITIVITIES, true)
            ? $dados['sensitivity']
            : 'medium';

        // Contatos permitidos, validados como do mesmo account (vazio = todos com IA).
        $contactIds = Contact::query()->where('account_id', $accountId)
            ->whereIn('id', $dados['contact_ids'] ?? [])->pluck('id')->all();

        // Guarda de segredo: {senha:...} no conteudo exige contatos restritos.
        if ($this->vault->hasRef($content) && $contactIds === []) {
            return ['knowledge' => null, 'errors' => [
                'contactIds' => 'Este conteudo tem {senha:...}. Restrinja os contatos permitidos — sem restricao, a referencia valeria pra QUALQUER contato com IA em modo conhecimento.',
            ]];
        }

        $persist = [
            'title' => $title,
            'content' => $content,
            'sensitivity' => $sensitivity,
            'active' => (bool) ($dados['active'] ?? true),
        ];

        if ($editingId !== null) {
            $k = Knowledge::query()->where('account_id', $accountId)->findOrFail($editingId);
            $k->update($persist);
        } else {
            $k = Knowledge::create(array_merge($persist, ['account_id' => $accountId]));
        }

        $k->contacts()->sync($contactIds);

        return ['knowledge' => $k, 'errors' => []];
    }
}
