<?php

namespace App\Whatsapp\Secrets;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypter DEDICADO do cofre, com chave separada do APP_KEY (config secrets.key).
 * AES-256 (Laravel Encrypter). Usado SO pra cifrar/decifrar o valor das senhas em
 * repouso. A chave nunca aparece em log/relatorio.
 */
class SecretCipher
{
    private ?Encrypter $encrypter = null;

    /**
     * Encrypter PREGUICOSO: so e construido (e a SECRETS_KEY so e exigida) quando
     * realmente cifra/decifra. Assim, montar o SecretCipher/SecretVault NUNCA quebra
     * (ex.: ao construir o Sender numa auto-resposta SEM senha). So quem usa senha
     * de verdade depende da chave.
     */
    private function encrypter(): Encrypter
    {
        return $this->encrypter ??= new Encrypter($this->parseKey(), (string) config('secrets.cipher', 'AES-256-CBC'));
    }

    public function encrypt(string $plain): string
    {
        return $this->encrypter()->encryptString($plain);
    }

    public function decrypt(string $payload): string
    {
        return $this->encrypter()->decryptString($payload);
    }

    /** Aceita chave em formato base64: (como o APP_KEY) ou bytes crus. */
    private function parseKey(): string
    {
        $key = (string) config('secrets.key');

        if ($key === '') {
            throw new RuntimeException('SECRETS_KEY nao configurada no .env.');
        }

        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7), true) ?: '';
        }

        return $key;
    }
}
