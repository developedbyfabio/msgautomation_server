<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\AutoReply\RuleTester;
use App\Whatsapp\Flows\FlowEngine;
use App\Whatsapp\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MATCH-1 — normalizador UNICO nos dois lados de TODO ponto de casamento.
 * Provas: tabela de casos do normalizador; o caso REAL do "Que horas são?";
 * fluxos (opcoes "1."/"1️⃣"/" 1 ", sair, gatilho de entrada); opt-out unificado;
 * regex CRUA; S5 estrita = exato sobre normalizado; coluna persistida +
 * observer; testador transparente (formas normalizadas + aviso de fluxo ativo).
 */
class MatchNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;
    private Channel $channel;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected', 'webhook_token' => 'tok-match']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
        $this->contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'push_name' => 'Cliente',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function regra(array $triggers, string $resposta = 'OK!'): AutoReplyRule
    {
        $r = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => $triggers[0]['match_type'],
            'match_value' => $triggers[0]['match_value'], 'response_text' => $resposta, 'enabled' => true,
        ]);
        foreach ($triggers as $t) {
            $r->triggers()->create($t);
        }
        $r->responses()->create(['response_text' => $resposta]);

        return $r;
    }

    private function match(string $texto): ?AutoReplyRule
    {
        return app(RuleMatcher::class)->match($this->account->id, $this->channel->id, $texto, self::JID);
    }

    // ---- o normalizador (tabela de casos) -------------------------------------------

    public function test_normalizador_tabela_de_casos(): void
    {
        $casos = [
            // [entrada, esperado]
            ['Que horas são ?', 'que horas sao'],
            ['Que horas são?', 'que horas sao'],
            ['QUE  HORAS   SÃO!!!', 'que horas sao'],
            ["que\u{00A0}horas sao", 'que horas sao'],     // nbsp
            ["hor\u{200B}as", 'horas'],                     // zero-width
            ['“aspas curvas”', 'aspas curvas'],
            ['wi-fi', 'wifi'],
            ['Wi-Fi!', 'wifi'],
            ['horas?!', 'horas'],
            [' 1 ', '1'],
            ['1.', '1'],
            ['1)', '1'],
            ["1\u{FE0F}\u{20E3}", '1'],                     // 1️⃣ (keycap)
            ['você', 'voce'],
            ['OLÁ 😀', 'ola'],
            ['  espacos    multiplos  ', 'espacos multiplos'],
        ];

        foreach ($casos as [$entrada, $esperado]) {
            $this->assertSame($esperado, TextNormalizer::normalize($entrada), 'caso: ' . json_encode($entrada));
        }
    }

    // ---- o caso real ------------------------------------------------------------------

    public function test_caso_real_gatilho_com_espaco_antes_da_interrogacao_casa_as_variacoes(): void
    {
        // O gatilho EXATAMENTE como estava em producao no dia do bug.
        $r = $this->regra([[
            'match_type' => 'contains', 'match_value' => 'Que horas são ?', 'precision' => 'exato',
        ]]);

        foreach (['Que horas são?', 'que horas sao', 'QUE  HORAS SÃO!!!', 'Que horas são ?'] as $msg) {
            $this->assertSame($r->id, $this->match($msg)?->id, "contains nao casou: {$msg}");
        }

        // exact (frase inteira normalizada) e tolerante tambem.
        $r->triggers()->delete();
        $r->triggers()->create(['match_type' => 'exact', 'match_value' => 'Que horas são ?', 'precision' => 'exato']);
        $this->assertSame($r->id, $this->match('que horas sao!!')?->id);
        $this->assertNull($this->match('que horas sao amanha')); // exact segue frase INTEIRA

        $r->triggers()->delete();
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'Que horas são ?', 'precision' => 'tolerante', 'fuzzy_level' => 'media']);
        $this->assertSame($r->id, $this->match('que hors sao?')?->id); // typo + pontuacao
    }

    public function test_replica_da_regra_3_de_producao_segue_casando_tudo(): void
    {
        // Banda-aid preservado: contains "que horas" tolerante + "Horas ?" exato.
        $r = $this->regra([
            ['match_type' => 'contains', 'match_value' => 'que horas', 'precision' => 'tolerante', 'fuzzy_level' => 'media'],
            ['match_type' => 'contains', 'match_value' => 'Horas ?', 'precision' => 'exato'],
        ]);

        foreach (['Que horas são?', 'que horas sao', 'Horas ?', 'horas', 'HORAS?!'] as $msg) {
            $this->assertSame($r->id, $this->match($msg)?->id, "nao casou: {$msg}");
        }
    }

    // ---- persistencia (observer + backfill) ----------------------------------------------

    public function test_normalized_text_persistido_no_save_e_regex_fica_null(): void
    {
        $r = $this->regra([
            ['match_type' => 'contains', 'match_value' => 'Oi, TUDO bem?!'],
            ['match_type' => 'regex', 'match_value' => '^pedido \d+$'],
        ]);

        $this->assertDatabaseHas('rule_triggers', ['auto_reply_rule_id' => $r->id, 'normalized_text' => 'oi tudo bem']);
        $this->assertDatabaseHas('rule_triggers', ['auto_reply_rule_id' => $r->id, 'match_type' => 'regex', 'normalized_text' => null]);
    }

    // ---- regex: crua, intocada -------------------------------------------------------------

    public function test_regex_casa_contra_o_texto_cru_sem_normalizacao(): void
    {
        $r = $this->regra([[
            'match_type' => 'regex', 'match_value' => 'são \?$', // exige acento E "?" com espaco antes
        ]]);

        $this->assertSame($r->id, $this->match('Que horas são ?')?->id); // cru casa
        $this->assertNull($this->match('Que horas sao ?'));  // sem acento: regex NAO perdoa
        $this->assertNull($this->match('Que horas são?'));   // sem o espaco: idem
    }

    // ---- S5: estrito = EXATO sobre o normalizado --------------------------------------------

    public function test_s5_regra_de_senha_estrita_casa_normalizado_mas_nunca_tolerante(): void
    {
        app(\App\Whatsapp\Secrets\SecretVault::class)->put($this->account->id, 'wifi', 'segredo123');

        // Caminho oficial: senha exige escopo contatos + gatilho ESTRITO.
        $res = app(\App\Whatsapp\AutoReply\RuleWriter::class)->save($this->account->id, [
            'triggers' => [['type' => 'exact', 'value' => 'senha do wifi', 'precision' => 'exato']],
            'responses' => ['Senha: {senha:wifi}'],
            'enabled' => true, 'cooldown_mode' => 'global', 'cooldown_minutes' => null,
            'scope' => 'contatos', 'contact_ids' => [$this->contact->id],
            'ai_match_enabled' => false, 'ai_examples' => [],
        ]);
        $this->assertSame([], $res['errors']);

        // Estrito AGORA significa: exato sobre o NORMALIZADO (pontuacao/caixa perdoadas)...
        $this->assertNotNull($this->match('Senha do WiFi?'));
        // ...mas frase diferente NAO casa (continua exato) e tolerante segue BLOQUEADO.
        $this->assertNull($this->match('qual a senha do wifi por favor'));

        $res = app(\App\Whatsapp\AutoReply\RuleWriter::class)->save($this->account->id, [
            'triggers' => [['type' => 'exact', 'value' => 'senha do wifi', 'precision' => 'tolerante']],
            'responses' => ['Senha: {senha:wifi}'],
            'enabled' => true, 'cooldown_mode' => 'global', 'cooldown_minutes' => null,
            'scope' => 'contatos', 'contact_ids' => [$this->contact->id],
            'ai_match_enabled' => false, 'ai_examples' => [],
        ]);
        $this->assertArrayHasKey('triggers', $res['errors']); // guarda S5 inalterada
    }

    // ---- fluxos: opcoes, sair e gatilho de entrada --------------------------------------------

    public function test_fluxo_opcoes_e_sair_normalizados(): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'Menu!']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'Escolha: 1 - Suporte']);
        $fim = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'FINAL suporte']);
        $root->options()->create(['input' => '1', 'label' => '1 - Suporte', 'next_node_id' => $fim->id]);
        $flow->update(['root_node_id' => $root->id]);
        $engine = app(FlowEngine::class);

        // Gatilho de entrada normalizado ("MENU?!" casa "Menu!").
        $this->assertNotNull($engine->entryFlow($this->account->id, 'MENU?!', self::JID));

        // Opcao "1" casa " 1 ", "1.", "1)" e o emoji keycap "1️⃣".
        foreach ([' 1 ', '1.', '1)', "1\u{FE0F}\u{20E3}"] as $entrada) {
            $sessao = $engine->start($this->account->id, $flow, self::JID);
            $res = $engine->advance($sessao['session'], $entrada);
            $this->assertSame('FINAL suporte', $res['text'], 'opcao nao casou: ' . json_encode($entrada));
            FlowSession::withoutAccountScope()->delete(); // proxima iteracao limpa
        }

        // Sair/cancelar normalizados ("SAIR!!" e "Saír" encerram).
        foreach (['SAIR!!', 'Saír'] as $sair) {
            $sessao = $engine->start($this->account->id, $flow, self::JID);
            $res = $engine->advance($sessao['session'], $sair);
            $this->assertSame('cancelled', $res['status'], 'sair nao casou: ' . $sair);
            FlowSession::withoutAccountScope()->delete();
        }
    }

    // ---- opt-out: unificado no mesmo normalizador ------------------------------------------------

    public function test_opt_out_por_palavra_via_normalizador_unico(): void
    {
        $this->contact->update(['proactive_opt_in' => true]);

        (new \App\Jobs\ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'OPT1', 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => 'Párar!'], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );

        // Acento + pontuacao perdoados; match segue EXATO (palavra inteira, so ela).
        $this->assertFalse((bool) $this->contact->fresh()->proactive_opt_in);
    }

    // ---- testador: transparencia -------------------------------------------------------------------

    public function test_testador_mostra_formas_normalizadas_e_avisa_sessao_de_fluxo_ativa(): void
    {
        $this->regra([['match_type' => 'contains', 'match_value' => 'Que horas são ?']], 'Agora sao {hora}!');
        $tester = app(RuleTester::class);

        $res = $tester->test($this->account->id, $this->channel->id, 'QUE HORAS SÃO?!', $this->contact->id);
        $this->assertTrue($res['matched']);
        $this->assertSame('que horas sao', $res['norm_mensagem']);
        $this->assertSame('que horas sao', $res['norm_gatilho']);
        $this->assertFalse($res['fluxo_ativo']);

        // Sessao de fluxo ATIVA: o aviso aparece (fluxo intercepta antes das regras).
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        FlowSession::create([
            'account_id' => $this->account->id, 'flow_id' => $flow->id, 'remote_jid' => self::JID,
            'current_node_id' => null, 'status' => 'active',
            'last_activity_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);
        $res = $tester->test($this->account->id, $this->channel->id, 'QUE HORAS SÃO?!', $this->contact->id);
        $this->assertTrue($res['fluxo_ativo']);
    }
}
