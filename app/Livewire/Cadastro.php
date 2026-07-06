<?php

namespace App\Livewire;

use App\Actions\RegisterTenant;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fatia 25 — CADASTRO PUBLICO (self-signup) PF/PJ. Maior mudanca de superficie
 * de ataque do projeto: qualquer nao-autenticado dispara criacao de tenant.
 * Defesas, TODAS server-side (form e forjavel):
 *  - CPF/CNPJ com digito verificador REAL + unicidade global (1 documento =
 *    1 conta; anti-farm de trial) + e-mail unico;
 *  - rate limiting proprio (mesmo desenho do login hardening, Prompt 28);
 *  - provisionamento ATOMICO via RegisterTenant (rollback total em falha);
 *  - endereco REVALIDADO no submit (o ViaCEP do navegador e so conveniencia);
 *  - conta nasce em TRIAL (7d) e o painel so abre depois do e-mail verificado.
 */
#[Layout('components.layouts.auth')]
class Cadastro extends Component
{
    private const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
        'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public string $tipo = 'pf'; // 'pf' | 'pj' — o form adapta os campos

    // identificacao
    public string $nome = '';          // PF: nome completo | PJ: nome do responsavel
    public string $razaoSocial = '';   // PJ
    public string $nomeFantasia = '';  // PJ (opcional; vira o nome da conta)
    public string $documento = '';     // CPF ou CNPJ (mascara livre; normaliza p/ digitos)
    public string $email = '';
    public string $telefone = '';

    // endereco
    public string $cep = '';
    public string $endereco = '';
    public string $numero = '';
    public string $complemento = '';
    public string $bairro = '';
    public string $cidade = '';
    public string $uf = '';

    // acesso + LGPD
    public string $password = '';
    public string $password_confirmation = '';
    public bool $aceite = false;

    /**
     * Conveniencia: o navegador consulta o ViaCEP e entrega aqui. NADA disto e
     * confiavel nem final — os campos continuam editaveis (fallback manual com
     * o servico fora) e a validacao REAL e a do submit. So sanitiza e preenche.
     */
    public function preencherEndereco(array $end): void
    {
        $campo = fn (string $k, int $max) => mb_substr(trim((string) ($end[$k] ?? '')), 0, $max);

        if ($campo('logradouro', 190) !== '') {
            $this->endereco = $campo('logradouro', 190);
        }
        if ($campo('bairro', 100) !== '') {
            $this->bairro = $campo('bairro', 100);
        }
        if ($campo('localidade', 100) !== '') {
            $this->cidade = $campo('localidade', 100);
        }
        $uf = strtoupper($campo('uf', 2));
        if (in_array($uf, self::UFS, true)) {
            $this->uf = $uf;
        }
    }

    public function cadastrar(RegisterTenant $registrar)
    {
        $ip = request()->ip();
        // Anti-abuso (mesmo desenho do login hardening, Prompt 28) — freios:
        //  - submissoes por e-mail+IP e por IP (bot martelando o form);
        //  - CRIACOES por IP (3/h): um IP nao fabrica farm de contas trial.
        // Unicidade de CPF/CNPJ e e-mail completa o cerco (1 documento = 1 conta).
        $key = 'cadastro:' . Str::lower($this->email) . '|' . $ip;
        $ipKey = 'cadastro-ip:' . $ip;
        $criadasKey = 'cadastro-criadas-ip:' . $ip;

        if (RateLimiter::tooManyAttempts($key, 6)
            || RateLimiter::tooManyAttempts($ipKey, 15)
            || RateLimiter::tooManyAttempts($criadasKey, 3)) {
            $seconds = max(
                RateLimiter::availableIn($key),
                RateLimiter::availableIn($ipKey),
                RateLimiter::availableIn($criadasKey),
            );
            throw ValidationException::withMessages([
                'email' => "Muitas tentativas. Tente de novo em {$seconds}s.",
            ]);
        }
        RateLimiter::hit($key, 600);
        RateLimiter::hit($ipKey, 600);

        $dados = $this->validar();

        ['account' => $account, 'owner' => $owner] = $registrar->handle($dados);
        RateLimiter::hit($criadasKey, 3600);

        // E-mail de verificacao DEPOIS do commit (nunca e-mail de conta que nao
        // existe). Best-effort: SMTP fora nao derruba o cadastro — a tela de
        // aviso tem o reenvio.
        try {
            $owner->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
        }

        Auth::login($owner);
        session()->regenerate();
        session()->put('tenancy.account_id', $account->id);

        return $this->redirectRoute('verification.notice', navigate: false);
    }

    /** Validacao server-side completa (pt-BR: e a primeira tela do cliente final). */
    private function validar(): array
    {
        $pj = $this->tipo === 'pj';

        // Normaliza ANTES de validar: documento/CEP/telefone so digitos; a
        // unicidade de documento compara sempre a forma canonica.
        $this->documento = preg_replace('/\D/', '', $this->documento);
        $this->cep = preg_replace('/\D/', '', $this->cep);
        $this->telefone = preg_replace('/\D/', '', $this->telefone);
        $this->email = mb_strtolower(trim($this->email));
        $this->uf = strtoupper(trim($this->uf));

        $mensagens = [
            'required' => 'Campo obrigatorio.',
            'email.email' => 'Informe um e-mail valido.',
            'email.unique' => 'Este e-mail ja tem uma conta. Use "Entrar".',
            'documento.unique' => $pj ? 'Este CNPJ ja tem uma conta.' : 'Este CPF ja tem uma conta.',
            'telefone.min' => 'Telefone incompleto (use DDD + numero).',
            'cep.size' => 'CEP invalido (8 digitos).',
            'uf.in' => 'UF invalida.',
            'password.min' => 'A senha precisa de pelo menos 10 caracteres.',
            'password.confirmed' => 'A confirmacao nao confere com a senha.',
            'aceite.accepted' => 'Para criar a conta e preciso aceitar os termos.',
        ];

        $dados = $this->validate([
            'tipo' => ['required', Rule::in(['pf', 'pj'])],
            'nome' => ['required', 'string', 'max:120'],
            'razaoSocial' => [$pj ? 'required' : 'nullable', 'string', 'max:190'],
            'nomeFantasia' => ['nullable', 'string', 'max:190'],
            // Digito verificador REAL + unicidade global (accounts.document e a
            // forma canonica, so digitos — comparacao exata).
            'documento' => ['required', $pj ? new Cnpj : new Cpf, Rule::unique('accounts', 'document')],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'telefone' => ['required', 'string', 'min:10', 'max:13'],
            'cep' => ['required', 'string', 'size:8'],
            'endereco' => ['required', 'string', 'max:190'],
            'numero' => ['required', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['required', 'string', 'max:100'],
            'cidade' => ['required', 'string', 'max:100'],
            'uf' => ['required', Rule::in(self::UFS)],
            // Mesma politica da criacao de tenant do admin (min:10) + confirmacao.
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'aceite' => ['accepted'],
        ], $mensagens, [
            'nome' => $pj ? 'nome do responsavel' : 'nome completo',
            'razaoSocial' => 'razao social', 'documento' => $pj ? 'CNPJ' : 'CPF',
            'telefone' => 'telefone', 'endereco' => 'endereco', 'password' => 'senha',
        ]);

        return [
            // Nome da conta: PF = a pessoa; PJ = fantasia (ou razao social).
            // NAO exige unicidade de nome (o documento e a ancora unica; o id
            // desambigua no admin) — decisao registrada no relatorio.
            'account_name' => $pj ? (trim($this->nomeFantasia) ?: trim($this->razaoSocial)) : trim($this->nome),
            'owner_name' => trim($this->nome),
            'email' => $dados['email'],
            'password' => $dados['password'],
            'person_type' => $this->tipo,
            'document' => $dados['documento'],
            'razao_social' => $pj ? trim($this->razaoSocial) : null,
            'phone' => $dados['telefone'],
            'cep' => $dados['cep'],
            'endereco' => trim($dados['endereco']),
            'numero' => trim($dados['numero']),
            'complemento' => trim($this->complemento) ?: null,
            'bairro' => trim($dados['bairro']),
            'cidade' => trim($dados['cidade']),
            'uf' => $dados['uf'],
        ];
    }

    public function render()
    {
        return view('livewire.cadastro', [
            'plano' => config('billing.plan'),
            'trialDias' => (int) config('billing.trial_days', 7),
            'ufs' => self::UFS,
        ]);
    }
}
