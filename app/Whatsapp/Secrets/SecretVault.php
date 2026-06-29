<?php

namespace App\Whatsapp\Secrets;

use App\Models\Secret;

/**
 * Cofre de senhas. MODELO DE SEGURANCA:
 *  - valor cifrado em repouso (SecretCipher, chave dedicada);
 *  - decifra SO sob demanda, em memoria; o plaintext nao e persistido nem logado;
 *  - em log/historico vai a REDACAO "[senha: nome]", nunca o valor;
 *  - referencia por nome na resposta da regra: {senha:nome}.
 *
 * Limite conhecido: pro robo responder sozinho, o app decifra e o valor vai em texto
 * puro pelo WhatsApp pra quem disparar a regra. Por isso o guarda de escopo (S5).
 */
class SecretVault
{
    /** Sintaxe da referencia: {senha:nome}. nome = letras/numeros/._- */
    private const REF = '/\{senha:([\w.\-]+)\}/u';

    public function __construct(private SecretCipher $cipher)
    {
    }

    /** Cria/atualiza uma senha (cifra o valor). Escopo account. WHERE em (account,nome). */
    public function put(int $accountId, string $nome, string $plain, ?string $categoria = null, ?string $notes = null): Secret
    {
        return Secret::updateOrCreate(
            ['account_id' => $accountId, 'nome' => $nome],
            ['value_encrypted' => $this->cipher->encrypt($plain), 'categoria' => $categoria, 'notes' => $notes],
        );
    }

    /** Decifra o valor de UMA senha (em memoria). Null se nao existe. */
    public function reveal(int $accountId, string $nome): ?string
    {
        $enc = Secret::query()->where('account_id', $accountId)->where('nome', $nome)->value('value_encrypted');

        return $enc ? $this->cipher->decrypt($enc) : null;
    }

    /** Nomes cadastrados (NUNCA valores) — pro picker. */
    public function names(int $accountId): array
    {
        return Secret::query()->where('account_id', $accountId)->orderBy('nome')->pluck('nome')->all();
    }

    public function hasRef(string $text): bool
    {
        return (bool) preg_match(self::REF, $text);
    }

    /** Nomes referenciados em {senha:...} num texto. */
    public function refsIn(string $text): array
    {
        preg_match_all(self::REF, $text, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Resolve {senha:nome} -> valor decifrado (EM MEMORIA, no envio). Senha ausente ->
     * SecretMissingException (falha controlada, sem meia-resposta). O retorno e o
     * plaintext: usar e DESCARTAR (nao logar, nao persistir).
     */
    public function resolve(int $accountId, string $text): string
    {
        return preg_replace_callback(self::REF, function ($mm) use ($accountId) {
            $nome = $mm[1];
            $valor = $this->reveal($accountId, $nome);
            if ($valor === null) {
                throw new SecretMissingException($nome);
            }

            return $valor;
        }, $text);
    }

    /** Redacao pra LOG/UI: {senha:nome} -> [senha: nome]. Nunca expoe o valor. */
    public function redact(string $text): string
    {
        return preg_replace(self::REF, '[senha: $1]', $text);
    }

    /** Mascara pra exibicao no testador: {senha:nome} -> [senha: nome ••••]. */
    public function mask(string $text): string
    {
        return preg_replace(self::REF, '[senha: $1 ••••]', $text);
    }
}
