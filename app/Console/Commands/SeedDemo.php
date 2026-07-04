<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\Card;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Models\ProactiveCampaign;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variable;
use App\Tenancy\AccountContext;
use App\Variables\VariableProvisioner;
use App\Variables\VariableWriter;
use App\Whatsapp\AutoReply\RuleWriter;
use App\Whatsapp\Flows\FlowTemplateCatalog;
use App\Whatsapp\Flows\InstantiateFlowTemplate;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Console\Command;

/**
 * Fatia 8 — popula UMA conta (explicita, obrigatoria) com dados de exemplo em
 * todas as abas, pra testar o sistema ponta a ponta. Guardas inegociaveis:
 *
 *  - ADITIVO e IDEMPOTENTE: so insere o que falta (marcadores reconheciveis:
 *    prefixo "Exemplo — " nos nomes, jids FAKE 550000000000X, ids DEMO- nas
 *    mensagens); re-rodar nao duplica; NUNCA apaga/sobrescreve dado existente.
 *  - Secrets do cofre = FAKE (placeholders obvios), nunca credencial real.
 *  - Campanha SO em draft — nenhum job de envio, nenhuma mensagem a ninguem.
 *  - Contatos/threads com jid FAKE (DDI 55 + DDD 00, inexistente no Brasil):
 *    nao colide com contato real e nao recebe envio.
 *  - Fluxos via o servico da Fatia 7 (InstantiateFlowTemplate) — editaveis, com
 *    handoff. NAO seta default_flow_id nem operation_mode (Fabio testa na UI).
 *  - Tudo via AccountContext::runAs(conta alvo): nenhuma query cai no fallback
 *    fase-1 (conta mais antiga) por engano.
 */
class SeedDemo extends Command
{
    protected $signature = 'msg:seed-demo
        {--account= : ID da conta alvo (obrigatorio, ou use --email)}
        {--email= : Email de um usuario da conta; resolve a conta se ele tiver exatamente uma}';

    protected $description = 'Popula uma conta EXPLICITA com dados de exemplo (todas as abas). Aditivo, idempotente, sem disparar nada.';

    /** JIDs FAKE documentados: DDI 55 + DDD 00 (inexistente) — nunca colide com numero real. */
    private const FAKE_JIDS = [
        '5500000000001@s.whatsapp.net' => 'Exemplo — Ana',
        '5500000000002@s.whatsapp.net' => 'Exemplo — Bruno',
        '5500000000003@s.whatsapp.net' => 'Exemplo — Carla',
        '5500000000004@s.whatsapp.net' => 'Exemplo — Diego',
    ];

    public function handle(AccountContext $context): int
    {
        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE; // mensagem ja emitida — NADA foi criado
        }

        $this->info("Semeando dados de exemplo na conta #{$account->id} ({$account->name})...");

        $context->runAs((int) $account->id, function () use ($account) {
            $id = (int) $account->id;
            $this->seedVariaveis($id);
            $this->seedVault($id);
            $this->seedTags($id);
            $this->seedContatos($id);
            $this->seedConversas($id);
            $this->seedRegras($id);
            $this->seedConhecimentos($id);
            $this->seedFluxos($id);
            $this->seedCampanha($id);
            $this->seedKanban($id);
        });

        $this->info('Pronto. Re-rodar e seguro (idempotente): nada duplica.');

        return self::SUCCESS;
    }

    /** Conta EXPLICITA obrigatoria: --account=<id> ou --email=<usuario com 1 conta>. */
    private function resolveAccount(): ?Account
    {
        $accountOpt = $this->option('account');
        $emailOpt = $this->option('email');

        if ($accountOpt === null && $emailOpt === null) {
            $this->error('Alvo obrigatorio: informe --account=<id> ou --email=<email do usuario>. Nunca semeio uma conta implicita.');

            return null;
        }

        if ($accountOpt !== null) {
            $account = Account::query()->find((int) $accountOpt);
            if ($account === null) {
                $this->error("Conta #{$accountOpt} nao existe. Nada foi criado.");
            }

            return $account;
        }

        $user = User::query()->where('email', (string) $emailOpt)->first();
        if ($user === null) {
            $this->error("Usuario {$emailOpt} nao existe. Nada foi criado.");

            return null;
        }
        $contas = $user->accounts()->get();
        if ($contas->count() !== 1) {
            $ids = $contas->pluck('id')->implode(', ') ?: '(nenhuma)';
            $this->error("Usuario {$emailOpt} tem " . $contas->count() . " conta(s) [{$ids}] — desambigue com --account=<id>. Nada foi criado.");

            return null;
        }

        return $contas->first();
    }

    private function resumo(string $dominio, int $criados, int $pulados): void
    {
        $this->line(sprintf('  %-14s %d criado(s), %d ja existia(m)', $dominio . ':', $criados, $pulados));
    }

    // ---- Variaveis (V-1): {saudacao} de sistema + {empresa} de exemplo ----------

    private function seedVariaveis(int $accountId): void
    {
        // Provisioner oficial (idempotente) garante a {saudacao} com faixas por horario.
        app(VariableProvisioner::class)->ensureSystemVariables($accountId);

        $criados = 0;
        $pulados = 0;
        if (Variable::query()->where('name', 'empresa')->exists()) {
            $pulados++;
        } else {
            $res = app(VariableWriter::class)->save($accountId, [
                'name' => 'empresa',
                'type' => 'static',
                'config' => ['valor' => 'Empresa Exemplo'],
            ]);
            $res['errors'] === [] ? $criados++ : $this->warn('  variaveis: {empresa} nao criada: ' . implode('; ', $res['errors']));
        }
        $this->resumo('variaveis', $criados, $pulados + 1); // +1 = {saudacao} (sistema, sempre presente)
    }

    // ---- Cofre: SO placeholders FAKE (nunca sobrescreve segredo existente) ------

    private function seedVault(int $accountId): void
    {
        $vault = app(SecretVault::class);
        $existentes = $vault->names($accountId);
        $fakes = [
            'token_exemplo' => 'TOKEN_EXEMPLO_123',
            'api_key_demo' => 'API_KEY_DEMO_abc',
        ];

        $criados = 0;
        $pulados = 0;
        foreach ($fakes as $nome => $valorFake) {
            if (in_array($nome, $existentes, true)) {
                $pulados++; // NUNCA sobrescreve (put e updateOrCreate — por isso o guard)

                continue;
            }
            $vault->put($accountId, $nome, $valorFake, 'demo', 'Exemplo criado pelo seed de demonstracao. Valor FAKE — pode apagar.');
            $criados++;
        }
        $this->resumo('cofre', $criados, $pulados);
    }

    // ---- Tags -------------------------------------------------------------------

    private function seedTags(int $accountId): void
    {
        $tags = ['cliente' => 'emerald', 'lead' => 'sky', 'vip' => 'amber'];
        $criados = 0;
        $pulados = 0;
        foreach ($tags as $nome => $cor) {
            $tag = Tag::query()->firstOrCreate(
                ['account_id' => $accountId, 'name' => $nome],
                ['color' => $cor],
            );
            $tag->wasRecentlyCreated ? $criados++ : $pulados++;
        }
        $this->resumo('tags', $criados, $pulados);
    }

    // ---- Contatos FAKE (saved) ----------------------------------------------------

    private function seedContatos(int $accountId): void
    {
        $criados = 0;
        $pulados = 0;
        foreach (self::FAKE_JIDS as $jid => $nome) {
            $contato = Contact::query()->firstOrCreate(
                ['account_id' => $accountId, 'remote_jid' => $jid],
                ['push_name' => $nome, 'saved' => true, 'auto_reply_mode' => 'default'],
            );
            $contato->wasRecentlyCreated ? $criados++ : $pulados++;
        }

        // Tags de exemplo nos contatos FAKE (origem manual, como a UI faz).
        $porNome = fn (string $n) => Tag::query()->where('name', $n)->first();
        $vinculos = [
            '5500000000001@s.whatsapp.net' => ['cliente', 'vip'],
            '5500000000002@s.whatsapp.net' => ['lead'],
            '5500000000003@s.whatsapp.net' => ['cliente'],
        ];
        foreach ($vinculos as $jid => $nomes) {
            $contato = Contact::query()->where('remote_jid', $jid)->first();
            foreach ($nomes as $n) {
                $tag = $porNome($n);
                if ($contato && $tag && ! $contato->tags()->where('tags.id', $tag->id)->exists()) {
                    $contato->tags()->attach($tag->id, ['origin' => 'manual', 'origin_ref' => null]);
                }
            }
        }
        $this->resumo('contatos', $criados, $pulados);
    }

    // ---- Conversas: threads inertes pros contatos FAKE (nenhum envio) -------------

    private function seedConversas(int $accountId): void
    {
        $canal = Channel::query()->first(); // canal da conta (contexto runAs)
        $roteiros = [
            '5500000000001@s.whatsapp.net' => [
                ['in', 'Oi, tudo bem? Queria saber mais sobre voces.'],
                ['out', 'Ola! Tudo otimo. Como posso ajudar?'],
                ['in', 'Voces atendem aos sabados?'],
            ],
            '5500000000002@s.whatsapp.net' => [
                ['in', 'Qual o horario de funcionamento?'],
                ['out', 'Atendemos de segunda a sexta, das 8h as 18h.'],
            ],
            '5500000000003@s.whatsapp.net' => [
                ['in', 'Queria um orcamento, por favor.'],
            ],
        ];

        $criados = 0;
        $pulados = 0;
        $minutos = 60; // escalona received_at pra thread ficar em ordem natural
        foreach ($roteiros as $jid => $mensagens) {
            foreach ($mensagens as $i => [$direcao, $texto]) {
                $demoId = 'DEMO-' . substr($jid, 10, 3) . '-' . ($i + 1); // marcador reconhecivel
                if (IncomingMessage::query()->where('evolution_message_id', $demoId)->exists()) {
                    $pulados++;

                    continue;
                }
                IncomingMessage::create([
                    'account_id' => $accountId,
                    'channel_id' => $canal?->id,
                    'instance' => $canal->instance ?? 'demo-seed',
                    'evolution_message_id' => $demoId,
                    'remote_jid' => $jid,
                    'from_me' => $direcao === 'out',
                    'push_name' => self::FAKE_JIDS[$jid],
                    'type' => 'conversation',
                    'text' => $texto,
                    'raw_payload' => ['seed' => 'demo'], // marcador: linha inerte criada pelo seed
                    'received_at' => now()->subMinutes($minutos--),
                ]);
                $criados++;
            }
        }
        $this->resumo('conversas', $criados, $pulados);
    }

    // ---- Regras (RuleWriter oficial), variando exact/contains/starts_with ---------

    private function seedRegras(int $accountId): void
    {
        $regras = [
            [
                'triggers' => [
                    ['type' => 'exact', 'value' => 'oi', 'precision' => 'exato'],
                    ['type' => 'exact', 'value' => 'ola', 'precision' => 'exato'],
                ],
                'responses' => ['{saudacao}! Bem-vindo(a) a {empresa}. Como posso ajudar?'],
            ],
            [
                'triggers' => [
                    ['type' => 'contains', 'value' => 'horario', 'precision' => 'exato'],
                    ['type' => 'contains', 'value' => 'que horas', 'precision' => 'exato'],
                ],
                'responses' => ['Nosso horario de atendimento e de segunda a sexta, das 8h as 18h.'],
            ],
            [
                'triggers' => [
                    ['type' => 'starts_with', 'value' => 'preco', 'precision' => 'exato'],
                    ['type' => 'contains', 'value' => 'valor', 'precision' => 'exato'],
                ],
                'responses' => ['Os precos variam por produto/servico. Me conta o que voce procura que ja te passo os valores!'],
            ],
        ];

        $writer = app(RuleWriter::class);
        $criados = 0;
        $pulados = 0;
        foreach ($regras as $r) {
            // Idempotencia + nao-conflito: pula se a conta ja tem regra com a MESMA
            // resposta (seed re-rodado) OU um gatilho com o mesmo valor (regra real
            // do usuario — o seed nunca cria uma concorrente por cima).
            $valores = array_column($r['triggers'], 'value');
            $jaExiste = AutoReplyRule::query()->where('response_text', $r['responses'][0])->exists()
                || AutoReplyRule::query()->whereHas('triggers', fn ($q) => $q->whereIn('match_value', $valores))->exists();
            if ($jaExiste) {
                $pulados++;

                continue;
            }
            $res = $writer->save($accountId, [
                'triggers' => $r['triggers'],
                'responses' => $r['responses'],
                'enabled' => true,
                'cooldown_mode' => 'global',
                'scope' => 'global',
            ]);
            $res['errors'] === [] ? $criados++ : $this->warn('  regras: exemplo nao criado: ' . implode('; ', $res['errors']));
        }
        $this->resumo('regras', $criados, $pulados);
    }

    // ---- Conhecimentos (KB da IA) ---------------------------------------------------

    private function seedConhecimentos(int $accountId): void
    {
        $entradas = [
            ['Exemplo — Horario de funcionamento', 'Atendemos de segunda a sexta, das 8h as 18h, e aos sabados das 8h as 12h. Nao abrimos aos domingos e feriados.'],
            ['Exemplo — Formas de pagamento', 'Aceitamos Pix, cartao de credito (ate 3x sem juros), cartao de debito e dinheiro. Nao aceitamos cheque.'],
            ['Exemplo — Politica de trocas', 'Trocas em ate 7 dias corridos com nota fiscal e produto sem uso. Para itens com defeito, o prazo e de 30 dias.'],
        ];

        $criados = 0;
        $pulados = 0;
        foreach ($entradas as [$titulo, $conteudo]) {
            $kb = Knowledge::query()->firstOrCreate(
                ['account_id' => $accountId, 'title' => $titulo],
                ['content' => $conteudo, 'sensitivity' => 'low', 'active' => true],
            );
            $kb->wasRecentlyCreated ? $criados++ : $pulados++;
        }
        $this->resumo('conhecimentos', $criados, $pulados);
    }

    // ---- Fluxos: os 3 templates da Fatia 7 (nomes REAIS do catalogo) ------------------

    private function seedFluxos(int $accountId): void
    {
        $catalog = app(FlowTemplateCatalog::class);
        $service = app(InstantiateFlowTemplate::class);

        $criados = 0;
        $pulados = 0;
        foreach ($catalog->all() as $key => $template) {
            // Marcador de idempotencia: um fluxo com o NOME do template ja existe na
            // conta (semeado antes, ou instanciado pelo usuario na UI) -> pula.
            if (Flow::query()->where('name', $template['name'])->exists()) {
                $pulados++;

                continue;
            }
            $service->handle($key, $accountId);
            $criados++;
        }
        $this->resumo('fluxos', $criados, $pulados);
    }

    // ---- Campanha: SO rascunho (draft) — nada dispara, nenhum target, nenhum job ------

    private function seedCampanha(int $accountId): void
    {
        $nome = 'Exemplo — Reativacao de clientes';
        if (ProactiveCampaign::query()->where('name', $nome)->exists()) {
            $this->resumo('campanhas', 0, 1);

            return;
        }

        $lead = Tag::query()->where('name', 'lead')->first();
        ProactiveCampaign::create([
            'account_id' => $accountId,
            'name' => $nome,
            'message' => '{saudacao}, {nome}! Sentimos sua falta na {empresa}. Temos novidades que podem te interessar — quer saber mais?',
            'optout_footer' => 'Se nao quiser mais receber mensagens, responda {palavra_sair}.',
            'audience_type' => 'tags',
            'audience_config' => ['tag_ids' => $lead ? [$lead->id] : []],
            'status' => 'draft', // NUNCA alem de draft: preview/aprovacao/disparo sao do Fabio, na UI
        ]);
        $this->resumo('campanhas', 1, 0);
    }

    // ---- Kanban: cards dos contatos FAKE em colunas distintas (board default) ---------

    private function seedKanban(int $accountId): void
    {
        $board = app(\App\Kanban\BoardProvisioner::class)->ensureDefaultBoard($accountId);
        $porColuna = [
            '5500000000001@s.whatsapp.net' => 'em_atendimento',
            '5500000000002@s.whatsapp.net' => 'novo',
            '5500000000003@s.whatsapp.net' => 'resolvido',
        ];

        $criados = 0;
        $pulados = 0;
        foreach ($porColuna as $jid => $slug) {
            $contato = Contact::query()->where('remote_jid', $jid)->first();
            $coluna = $board->columns()->where('slug', $slug)->first();
            if (! $contato || ! $coluna) {
                continue;
            }
            if (Card::query()->where('board_id', $board->id)->where('contact_id', $contato->id)->exists()) {
                $pulados++; // card e 1 por contato — se ja existe (real ou seed), NAO mexe

                continue;
            }
            Card::create([
                'account_id' => $accountId,
                'board_id' => $board->id,
                'contact_id' => $contato->id,
                'column_id' => $coluna->id,
                'last_interaction_at' => now(),
                'last_direction' => 'in',
            ]);
            $criados++;
        }
        $this->resumo('kanban', $criados, $pulados);
    }
}
