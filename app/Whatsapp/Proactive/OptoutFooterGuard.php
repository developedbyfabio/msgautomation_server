<?php

namespace App\Whatsapp\Proactive;

use App\Models\AutoReplySetting;
use App\Whatsapp\Secrets\SecretVault;

/**
 * P-4 — validacao UNICA do rodape de saida das proativas, usada pelo default da
 * conta (/configuracoes), pelo save da campanha e re-executada na APROVACAO
 * (defesa em profundidade: a palavra pode ter mudado depois do draft).
 *
 * Regras:
 *  - rodape OBRIGATORIO (vazio = erro): toda proativa ensina como sair — quem
 *    nao sabe sair clica em denunciar, e denuncia derruba numero;
 *  - {senha:...}/segredo = erro (coerente com a jaula da P-1, sem excecao);
 *  - {palavra_sair} presente = OK (resolve pro valor ATUAL no envio);
 *  - palavra literal atual presente (sem a variavel) = AVISO recomendando a
 *    variavel — literal quebra em silencio se a palavra mudar depois;
 *  - sem variavel e sem a palavra = erro.
 */
class OptoutFooterGuard
{
    /** Seed do rodape padrao (espelho do default da migration 000033). */
    public const DEFAULT = 'Para nao receber mais mensagens assim, responda {palavra_sair}.';

    public function __construct(private SecretVault $vault)
    {
    }

    /**
     * $palavraAtual: sobrepoe a palavra salva — /configuracoes salva palavra e
     * rodape JUNTOS, entao o literal e comparado com a palavra do formulario.
     *
     * @return array{error: ?string, warning: ?string}
     */
    public function check(int $accountId, string $footer, ?string $palavraAtual = null): array
    {
        $footer = trim($footer);

        if ($footer === '') {
            return ['error' => 'O rodape de saida e OBRIGATORIO: toda proativa precisa ensinar como parar de receber. Quem nao sabe sair, denuncia — e denuncia derruba o numero.', 'warning' => null];
        }

        if ($this->vault->hasRef($footer) || preg_match('/\{senha\b/iu', $footer)) {
            return ['error' => 'Rodape NAO pode conter {senha:...} — segredo jamais sai em proativa, sem excecao.', 'warning' => null];
        }

        if (preg_match('/\{palavra_sair\}/iu', $footer)) {
            return ['error' => null, 'warning' => null];
        }

        $palavra = trim($palavraAtual ?? (string) AutoReplySetting::withoutAccountScope()
            ->where('account_id', $accountId)->value('proactive_optout_word'));
        if ($palavra !== '' && mb_stripos($footer, $palavra, 0, 'UTF-8') !== false) {
            return ['error' => null, 'warning' => 'O rodape usa a palavra literal "' . $palavra . '" — prefira {palavra_sair}: se a palavra mudar nas configuracoes, o rodape se atualiza sozinho (o literal quebraria em silencio).'];
        }

        return ['error' => 'O rodape precisa conter {palavra_sair} (vira a palavra de opt-out atual no envio). Sem a instrucao de saida, a campanha nao aprova.', 'warning' => null];
    }
}
