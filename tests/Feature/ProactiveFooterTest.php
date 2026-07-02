<?php

namespace Tests\Feature;

use App\Jobs\SendProactiveMessage;
use App\Livewire\Campanhas;
use App\Livewire\Configuracoes;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\CampaignTarget;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ProactiveCampaign;
use App\Models\User;
use App\Variables\VariableWriter;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\Proactive\AgendaBuilder;
use App\Whatsapp\Proactive\OptoutFooterGuard;
use App\Whatsapp\Proactive\ProactiveGuard;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P-4 — rodape de saida OBRIGATORIO em toda proativa. Provas centrais:
 *  - {palavra_sair} resolve pra palavra de opt-out ATUAL das settings (lookup no
 *    envio: trocar a palavra muda o rodape ate de campanha JA aprovada);
 *  - aprovacao BLOQUEADA sem rodape ou sem a instrucao de saida; literal da
 *    palavra = salva com AVISO recomendando a variavel;
 *  - texto final do disparo = mensagem + linha em branco + rodape, renderizados
 *    JUNTOS pelo renderizador unico; a guarda de segredo avalia o CONJUNTO;
 *  - default da conta pre-preenche campanha NOVA sem tocar draft/aprovada;
 *  - campanha antiga sem a coluna (pre-backfill) ainda sai com rodape (fallback);
 *  - nome reservado contra custom; nativa listada em /variaveis.
 * HTTP sempre mockado (nunca envio real). Robo reativo intocado.
 */
class ProactiveFooterTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private const FOOTER_RENDERED_PARAR = 'Para nao receber mais mensagens assim, responda PARAR.';

    private Account $account;
    private Channel $channel;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        // Quinta 02/07/2026 10:00 SP (janela proativa default 09-18 aberta).
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
            'proactive_enabled' => true, // MOCK de teste — producao segue OFF
        ]);
        $this->contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'push_name' => 'Cliente', 'proactive_opt_in' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----------------------------------------------------------------

    /** Campanha aprovada com 1 target vencido (footer default = espelho do backfill). */
    private function aprovada(?Contact $contact = null, array $extra = []): array
    {
        $contact = $contact ?: $this->contact;
        $camp = ProactiveCampaign::create(array_merge([
            'account_id' => $this->account->id,
            'name' => 'Follow-up',
            'message' => '{saudacao}, {nome}! Ainda tem interesse?',
            'optout_footer' => OptoutFooterGuard::DEFAULT,
            'audience_type' => 'contatos',
            'audience_config' => ['contact_ids' => [$contact->id]],
            'status' => 'approved',
            'approved_at' => now(),
        ], $extra));
        $target = CampaignTarget::create([
            'campaign_id' => $camp->id, 'contact_id' => $contact->id,
            'status' => 'pending', 'scheduled_at' => now()->subMinute(),
        ]);

        return [$camp, $target];
    }

    private function runJob(CampaignTarget $target): void
    {
        (new SendProactiveMessage((int) $target->id, (int) $this->account->id))->handle(
            app(ProactiveGuard::class), app(Sender::class),
            app(RuleResponder::class), app(AgendaBuilder::class),
        );
    }

    private function contato(string $sufixo): Contact
    {
        return Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => "55418888{$sufixo}@s.whatsapp.net",
            'auto_reply_mode' => 'on', 'push_name' => "Contato {$sufixo}", 'proactive_opt_in' => true,
        ]);
    }

    // ---- {palavra_sair}: lookup no ENVIO ------------------------------------------

    public function test_palavra_sair_resolve_a_palavra_atual_e_troca_reflete_em_campanha_aprovada(): void
    {
        // Render simples: default 'PARAR'.
        $this->assertSame('Responda PARAR.', app(RuleResponder::class)->render('Responda {palavra_sair}.'));

        // Campanha APROVADA com 2 targets. 1o disparo com a palavra atual...
        $c2 = $this->contato('0002');
        [$camp, $t1] = $this->aprovada();
        $t2 = CampaignTarget::create([
            'campaign_id' => $camp->id, 'contact_id' => $c2->id,
            'status' => 'pending', 'scheduled_at' => now()->subMinute(),
        ]);

        $this->runJob($t1);
        Http::assertSent(fn ($r) => $r['text'] === "Bom dia, Cliente! Ainda tem interesse?\n\n" . self::FOOTER_RENDERED_PARAR);

        // ...troca a palavra nas settings: SEM tocar a campanha, o 2o target ja
        // sai com a palavra NOVA — prova do lookup no envio (nada congelou o valor).
        AutoReplySetting::where('account_id', $this->account->id)->update(['proactive_optout_word' => 'SAIR']);

        $this->runJob($t2);
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'responda SAIR.')
            && str_contains((string) $r['text'], 'Contato 0002'));
        $this->assertSame('sent', $t2->fresh()->status);
    }

    // ---- validacao: aprovar exige a instrucao de saida ------------------------------

    public function test_aprovacao_bloqueada_sem_rodape_ou_sem_instrucao_de_saida(): void
    {
        // Rodape VAZIO (escrita direta, simulando dado fora do fluxo do form).
        $vazia = ProactiveCampaign::create([
            'account_id' => $this->account->id, 'name' => 'Sem rodape', 'message' => 'oi',
            'optout_footer' => '', 'audience_type' => 'contatos',
            'audience_config' => ['contact_ids' => [$this->contact->id]], 'status' => 'previewed',
        ]);
        Livewire::test(Campanhas::class)
            ->call('openPreview', $vazia->id)
            ->call('askApprove', $vazia->id)
            ->call('approveConfirmed')
            ->assertDispatched('toast', fn ($n, $p) => str_contains((string) ($p['message'] ?? ''), 'Nao aprovada'));
        $this->assertSame('previewed', $vazia->fresh()->status);
        $this->assertDatabaseCount('campaign_targets', 0);

        // Rodape sem {palavra_sair} E sem a palavra literal: nem SALVA (form).
        Livewire::test(Campanhas::class)
            ->call('novo')
            ->set('cName', 'Ruim')->set('cMessage', 'Oi!')
            ->set('cFooter', 'Qualquer coisa sem instrucao de saida')
            ->set('cAudienceType', 'contatos')->set('cContactIds', [$this->contact->id])
            ->call('save')
            ->assertHasErrors('cFooter');
        $this->assertDatabaseMissing('proactive_campaigns', ['name' => 'Ruim']);

        // E se um draft antigo tiver ficado invalido, o gate da APROVACAO segura.
        $semPalavra = ProactiveCampaign::create([
            'account_id' => $this->account->id, 'name' => 'Draft antigo', 'message' => 'oi',
            'optout_footer' => 'Texto sem a instrucao', 'audience_type' => 'contatos',
            'audience_config' => ['contact_ids' => [$this->contact->id]], 'status' => 'previewed',
        ]);
        Livewire::test(Campanhas::class)
            ->call('openPreview', $semPalavra->id)
            ->call('askApprove', $semPalavra->id)
            ->call('approveConfirmed');
        $this->assertSame('previewed', $semPalavra->fresh()->status);
    }

    public function test_palavra_literal_no_rodape_salva_com_aviso_recomendando_a_variavel(): void
    {
        Livewire::test(Campanhas::class)
            ->call('novo')
            ->set('cName', 'Literal')->set('cMessage', 'Oi, {nome}!')
            ->set('cFooter', 'Sem interesse? Responda PARAR.')
            ->set('cAudienceType', 'contatos')->set('cContactIds', [$this->contact->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($n, $p) => str_contains((string) ($p['message'] ?? ''), '{palavra_sair}')
                && str_contains((string) ($p['message'] ?? ''), 'literal'));

        $this->assertDatabaseHas('proactive_campaigns', ['name' => 'Literal', 'optout_footer' => 'Sem interesse? Responda PARAR.']);
    }

    // ---- disparo: texto final e guarda de segredo sobre o CONJUNTO --------------------

    public function test_senha_no_rodape_e_bloqueada_pela_guarda_no_disparo(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredo123');

        // Form: nem salva ({senha:} no rodape).
        Livewire::test(Campanhas::class)
            ->call('novo')
            ->set('cName', 'Vaza')->set('cMessage', 'Oi!')
            ->set('cFooter', 'Saia com {palavra_sair} ou {senha:wifi}')
            ->set('cAudienceType', 'contatos')->set('cContactIds', [$this->contact->id])
            ->call('save')
            ->assertHasErrors('cFooter');

        // Escrita direta (simulando bypass): a JAULA segura no disparo — o texto
        // avaliado e o CONJUNTO mensagem + rodape.
        [, $target] = $this->aprovada(null, ['optout_footer' => 'Saia: {palavra_sair}. Chave: {senha:wifi}']);
        $this->runJob($target);

        Http::assertNothingSent();
        $target->refresh();
        $this->assertSame('skipped', $target->status);
        $this->assertSame('contem_senha', $target->skip_reason);
    }

    public function test_variavel_custom_no_rodape_renderiza_no_disparo(): void
    {
        app(VariableWriter::class)->save($this->account->id, [
            'name' => 'assinatura', 'type' => 'static',
            'config' => ['valor' => 'Equipe Engepecas'], 'active' => true,
        ]);
        [, $target] = $this->aprovada(null, [
            'optout_footer' => 'Sem interesse? Responda {palavra_sair}. — {assinatura}',
        ]);

        $this->runJob($target);

        Http::assertSent(fn ($r) => $r['text'] === "Bom dia, Cliente! Ainda tem interesse?\n\nSem interesse? Responda PARAR. — Equipe Engepecas");
    }

    public function test_campanha_antiga_sem_coluna_preenchida_ainda_sai_com_rodape(): void
    {
        // Pre-backfill (optout_footer NULL): a OBRIGACAO vale — fallback no
        // padrao da conta. Nenhuma proativa sai sem instrucao de saida.
        [, $target] = $this->aprovada(null, ['optout_footer' => null]);

        $this->runJob($target);

        Http::assertSent(fn ($r) => str_ends_with((string) $r['text'], "\n\n" . self::FOOTER_RENDERED_PARAR));
        $this->assertSame('sent', $target->fresh()->status);
    }

    // ---- default da conta: pre-preenche NOVA, nao toca existentes ----------------------

    public function test_default_editado_reflete_em_campanha_nova_sem_tocar_existentes(): void
    {
        // Draft e aprovada existentes com o rodape proprio.
        $draft = ProactiveCampaign::create([
            'account_id' => $this->account->id, 'name' => 'Draft', 'message' => 'oi',
            'optout_footer' => 'RODAPE-DO-DRAFT {palavra_sair}', 'audience_type' => 'contatos',
            'audience_config' => ['contact_ids' => [$this->contact->id]], 'status' => 'draft',
        ]);
        [$aprovada] = $this->aprovada();

        // Edita o default da conta pelo card Proativas (valido, com a variavel).
        Livewire::test(Configuracoes::class)
            ->set('proactive_optout_footer', 'NOVO PADRAO: responda {palavra_sair} pra sair.')
            ->call('saveProactive')
            ->assertHasNoErrors();
        $this->assertSame('NOVO PADRAO: responda {palavra_sair} pra sair.',
            AutoReplySetting::where('account_id', $this->account->id)->value('proactive_optout_footer'));

        // Campanha NOVA abre PRE-PREENCHIDA com o novo padrao...
        Livewire::test(Campanhas::class)->call('novo')
            ->assertSet('cFooter', 'NOVO PADRAO: responda {palavra_sair} pra sair.');

        // ...e as existentes mantem CADA UMA o seu (nada reescrito).
        $this->assertSame('RODAPE-DO-DRAFT {palavra_sair}', $draft->fresh()->optout_footer);
        $this->assertSame(OptoutFooterGuard::DEFAULT, $aprovada->fresh()->optout_footer);
    }

    public function test_card_configuracoes_valida_rodape_e_avisa_literal(): void
    {
        // Sem instrucao de saida: erro no campo (nada salvo).
        Livewire::test(Configuracoes::class)
            ->set('proactive_optout_footer', 'rodape sem nada')
            ->call('saveProactive')
            ->assertHasErrors('proactive_optout_footer');
        $this->assertSame(OptoutFooterGuard::DEFAULT,
            AutoReplySetting::where('account_id', $this->account->id)->value('proactive_optout_footer'));

        // {senha:} no rodape padrao: erro.
        Livewire::test(Configuracoes::class)
            ->set('proactive_optout_footer', 'saia com {senha:wifi}')
            ->call('saveProactive')
            ->assertHasErrors('proactive_optout_footer');

        // Literal da palavra NOVA (salvas juntas): salva com AVISO.
        Livewire::test(Configuracoes::class)
            ->set('proactive_optout_word', 'SAIR')
            ->set('proactive_optout_footer', 'Pra sair, mande SAIR.')
            ->call('saveProactive')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($n, $p) => str_contains((string) ($p['message'] ?? ''), 'literal'));
        $this->assertSame('Pra sair, mande SAIR.',
            AutoReplySetting::where('account_id', $this->account->id)->value('proactive_optout_footer'));
    }

    // ---- preview: o que se aprova e EXATAMENTE o que sai --------------------------------

    public function test_preview_mostra_mensagem_e_rodape_renderizados(): void
    {
        $camp = ProactiveCampaign::create([
            'account_id' => $this->account->id, 'name' => 'Preview', 'message' => '{saudacao}, {nome}!',
            'optout_footer' => OptoutFooterGuard::DEFAULT, 'audience_type' => 'contatos',
            'audience_config' => ['contact_ids' => [$this->contact->id]], 'status' => 'draft',
        ]);

        Livewire::test(Campanhas::class)
            ->call('openPreview', $camp->id)
            ->assertSee('Bom dia, Cliente!')                    // mensagem renderizada
            ->assertSee(self::FOOTER_RENDERED_PARAR);           // rodape renderizado JUNTO
    }

    // ---- {palavra_sair}: nativa em /variaveis e nome reservado ---------------------------

    public function test_palavra_sair_listada_como_nativa_e_reservada_contra_custom(): void
    {
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op@x.local', 'password' => Hash::make('senha-forte')]));
        $this->get('/variaveis')->assertOk()
            ->assertSee('{palavra_sair}')
            ->assertSee('hoje: "PARAR"', false);

        // Reservada: custom com esse nome nao nasce.
        $res = app(VariableWriter::class)->save($this->account->id, [
            'name' => 'palavra_sair', 'type' => 'static', 'config' => ['valor' => 'x'], 'active' => true,
        ]);
        $this->assertArrayHasKey('name', $res['errors']);
        $this->assertDatabaseMissing('variables', ['name' => 'palavra_sair']);
    }

    // ---- robo reativo intocado -----------------------------------------------------------

    public function test_rodape_nao_vaza_pro_caminho_reativo(): void
    {
        // Regra reativa comum: resposta sai EXATAMENTE como cadastrada (sem rodape).
        $r = \App\Models\AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains',
            'match_value' => 'oi', 'response_text' => 'Ola, {nome}!', 'enabled' => true,
        ]);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'oi']);
        $r->responses()->create(['response_text' => 'Ola, {nome}!']);

        (new \App\Jobs\ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'R1', 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => 'oi'], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );

        Http::assertSent(fn ($req) => $req['text'] === 'Ola, Cliente!'); // SEM rodape
    }
}
