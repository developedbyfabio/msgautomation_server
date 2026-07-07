<?php

namespace App\Servers;

use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Support\Str;

/**
 * Servidores S1 — ciclo de vida do token do agente coletor.
 *
 * MODELO: o token em claro vive SO no Cofre (SecretVault, cifra dedicada
 * SECRETS_KEY) sob o nome "agente-servidor-{id}"; a tabela servers guarda a
 * REFERENCIA (nome do segredo) e o sha256 do token (lookup indexado O(1) na
 * ingestao). Exibido UMA vez na criacao/regeneracao; depois, recuperavel so
 * pelo Cofre (revelar com re-senha de login, padrao existente).
 *
 * Regenerar = put() no MESMO nome do Cofre (updateOrCreate substitui o valor)
 * + hash novo na tabela: o token antigo deixa de casar na hora (401).
 */
class AgentToken
{
    public function __construct(private SecretVault $vault) {}

    /** Nome do segredo no Cofre para um servidor. */
    public static function secretRef(Server $server): string
    {
        return 'agente-servidor-'.$server->id;
    }

    /**
     * Gera token novo (criacao ou regeneracao), guarda o claro no Cofre e o
     * hash na tabela. Retorna o claro — exibir UMA vez e descartar.
     */
    public function issue(Server $server): string
    {
        $plain = 'agt_'.Str::random(48);
        $ref = self::secretRef($server);

        $this->vault->put(
            $server->account_id,
            $ref,
            $plain,
            'servidores',
            'Token do agente coletor — '.$server->name.' (gerado pelo sistema; regeneravel na tela Servidores)',
        );

        $server->forceFill([
            'agent_token_secret_ref' => $ref,
            'agent_token_hash' => hash('sha256', $plain),
        ])->save();

        return $plain;
    }

    /**
     * Resolve o SERVIDOR dono do token apresentado (ingestao). Lookup pelo
     * sha256 indexado (withoutAccountScope: webhook nao tem sessao — mesmo
     * padrao do VerifyWebhookSecret) + hash_equals (timing-safe) confirmando.
     * Token vazio/desconhecido -> null (o chamador responde 401).
     */
    public function resolve(string $plainToken): ?Server
    {
        if ($plainToken === '') {
            return null;
        }

        $hash = hash('sha256', $plainToken);
        $server = Server::withoutAccountScope()->where('agent_token_hash', $hash)->first();

        if ($server === null || ! hash_equals((string) $server->agent_token_hash, $hash)) {
            return null;
        }

        return $server;
    }
}
