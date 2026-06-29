<?php

/*
|--------------------------------------------------------------------------
| Cofre de senhas (cifra dedicada)
|--------------------------------------------------------------------------
| Chave SEPARADA do APP_KEY (rotacao/escopo independentes). A cifra protege
| o valor EM REPOUSO no banco. O app decifra sob demanda (pro robo responder
| sozinho ele precisa do plaintext em memoria no envio) — ver modelo de
| seguranca no SecretVault. A chave vive so no .env (chmod 600, gitignored).
*/

return [
    'key' => env('SECRETS_KEY'),
    'cipher' => 'AES-256-CBC',
];
