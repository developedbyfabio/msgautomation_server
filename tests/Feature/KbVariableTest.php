<?php

namespace Tests\Feature;

use App\Ai\KnowledgeWriter;
use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Knowledge;
use App\Models\Variable;
use App\Tenancy\AccountContext;
use App\Jobs\ProcessIncomingWhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 15 — conhecimento como variavel: token {kb:slug} resolvido no
 * renderizador UNICO (RuleResponder) com escopo da conta do ENVIO. Slug
 * ESTAVEL (gerado na criacao, imutavel no rename, unico por conta). Orfao/
 * sensivel/restrito/com-senha = vazio + warning (token nunca vaza, envio nunca
 * quebra). Conteudo inserido e LITERAL (sem recursao). Dropdown no editor so
 * lista referenciaveis. IA intocada.
 */
class KbVariableTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541977776666@s.whatsapp.net';

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 6, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receber(string $texto, string $id, string $instance = 'inst-a'): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => $instance,
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    private function kb(string $title, string $content, string $sensitivity = 'low', ?int $accountId = null): Knowledge
    {
        return Knowledge::create([
            'account_id' => $accountId ?? $this->account->id,
            'title' => $title, 'content' => $content, 'sensitivity' => $sensitivity, 'active' => true,
        ]);
    }

    private function fluxoComMensagem(string $mensagem): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'info']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => $mensagem]);
        $flow->update(['root_node_id' => $root->id]);
    }

    // ---- Slug estavel ---------------------------------------------------------

    public function test_slug_gerado_na_criacao_imutavel_no_rename_e_unico_por_conta(): void
    {
        // Via caminho oficial (KnowledgeWriter).
        $res = app(KnowledgeWriter::class)->save($this->account->id, [
            'title' => 'Horário de atendimento', 'content' => 'Seg a Sex, 8h-18h.',
            'sensitivity' => 'low', 'active' => true, 'contact_ids' => [],
        ]);
        $k = $res['knowledge'];
        $this->assertSame('horario-de-atendimento', $k->slug); // slugify com acentos

        // RENAME nao muda o slug (referencias nunca quebram).
        app(KnowledgeWriter::class)->save($this->account->id, [
            'title' => 'Horários (novo título)', 'content' => 'Seg a Sex, 8h-18h.',
            'sensitivity' => 'low', 'active' => true, 'contact_ids' => [],
        ], $k->id);
        $this->assertSame('horario-de-atendimento', $k->fresh()->slug);
        $this->assertSame('Horários (novo título)', $k->fresh()->title);

        // Colisao na MESMA conta sufixa; criacao DIRETA (caminho do seed) tambem
        // ganha slug (hook do model e o choke point).
        $k2 = $this->kb('Horário de atendimento', 'Outro conteudo.');
        $this->assertSame('horario-de-atendimento-2', $k2->slug);

        // Mesmo titulo em OUTRA conta: slug base livre (unicidade e por conta).
        $b = Account::create(['name' => 'B']);
        $kb = $this->kb('Horário de atendimento', 'Da B.', accountId: $b->id);
        $this->assertSame('horario-de-atendimento', $kb->slug);
    }

    // ---- Resolucao no envio (caminho de FLUXO e de REGRA — renderizador unico) --

    public function test_no_de_fluxo_com_kb_envia_o_conteudo_do_conhecimento(): void
    {
        $this->kb('Horários', 'Atendemos de segunda a sexta, das 8h às 18h.');
        $this->fluxoComMensagem('Claro! {kb:horarios}');

        $this->receber('info', 'K1');

        $log = AutoReplyLog::withoutAccountScope()->where('status', 'sent')->firstOrFail();
        $this->assertSame('Claro! Atendemos de segunda a sexta, das 8h às 18h.', $log->response_text);
    }

    public function test_resposta_de_regra_tambem_resolve_kb_renderizador_central(): void
    {
        $this->kb('Endereço', 'Rua das Flores, 123 — Centro.');
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'endereco',
            'response_text' => '{kb:endereco}', 'enabled' => true, 'priority' => 0,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'endereco']);
        $rule->responses()->create(['response_text' => 'Estamos na {kb:endereco}']);

        $this->receber('qual o endereco?', 'K2');

        $log = AutoReplyLog::withoutAccountScope()->where('status', 'sent')->firstOrFail();
        $this->assertSame('Estamos na Rua das Flores, 123 — Centro.', $log->response_text);
    }

    // ---- Orfao / sensivel / segredo: vazio + log, envio nunca quebra ------------

    public function test_token_orfao_sai_vazio_sem_vazar_token_e_loga_warning(): void
    {
        Log::spy();
        $this->fluxoComMensagem('Nossos horários: {kb:nao-existe} obrigado!');

        $this->receber('info', 'K3');

        $log = AutoReplyLog::withoutAccountScope()->where('status', 'sent')->firstOrFail();
        $this->assertSame('Nossos horários:  obrigado!', $log->response_text); // vazio, NAO o token
        $this->assertStringNotContainsString('{kb:', $log->response_text);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg) => str_contains((string) $msg, '{kb:nao-existe}'))
            ->atLeast()->once();
    }

    public function test_sensivel_restrito_e_com_senha_sao_tratados_como_orfaos(): void
    {
        // medium (vai no maximo ao MODELO da IA, nunca direto pro contato).
        $this->kb('Interno', 'CONTEUDO-SENSIVEL-NAO-VAZA', 'medium');
        // low mas RESTRITO a contatos.
        $restrito = $this->kb('Restrito', 'CONTEUDO-RESTRITO-NAO-VAZA');
        $restrito->contacts()->attach(Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541900000000@s.whatsapp.net'])->id);
        // low mas com {senha:} no conteudo (o Sender resolveria DEPOIS do render).
        $this->kb('Com segredo', 'A senha e {senha:wifi}');

        $this->fluxoComMensagem('A:{kb:interno} B:{kb:restrito} C:{kb:com-segredo} fim');

        $this->receber('info', 'K4');

        $log = AutoReplyLog::withoutAccountScope()->where('status', 'sent')->firstOrFail();
        $this->assertSame('A: B: C: fim', $log->response_text); // nada vazou
        $this->assertStringNotContainsString('SENSIVEL', $log->response_text);
        $this->assertStringNotContainsString('RESTRITO', $log->response_text);
        $this->assertStringNotContainsString('senha', $log->response_text);
    }

    // ---- Literal (sem recursao) --------------------------------------------------

    public function test_conteudo_inserido_e_literal_refs_dentro_nao_resolvem(): void
    {
        // VariableWriter PROIBE {ref} em variavel; KB pode conter — mas o passe
        // {kb:} roda DEPOIS do principal: o conteudo entra LITERAL (sem recursao),
        // mesma filosofia um-nivel-so das variaveis.
        Variable::create(['account_id' => $this->account->id, 'name' => 'empresa', 'type' => 'static', 'config' => ['valor' => 'ACME'], 'active' => true, 'is_system' => false]);
        $this->kb('Sobre', 'Somos a {empresa} desde 1990.');

        $this->fluxoComMensagem('{kb:sobre}');
        $this->receber('info', 'K5');

        $log = AutoReplyLog::withoutAccountScope()->where('status', 'sent')->firstOrFail();
        $this->assertSame('Somos a {empresa} desde 1990.', $log->response_text); // LITERAL
    }

    // ---- Isolamento (critico): mesmo slug em A e B ------------------------------

    public function test_isolamento_mesmo_slug_resolve_o_conteudo_da_conta_do_envio(): void
    {
        $b = Account::create(['name' => 'B']);
        $this->kb('Horários', 'CONTEUDO-DA-A');
        $this->kb('Horários', 'CONTEUDO-DA-B', accountId: $b->id);
        $this->assertSame('horarios', Knowledge::withoutAccountScope()->where('account_id', $b->id)->first()->slug);

        $this->fluxoComMensagem('{kb:horarios}');
        $this->receber('info', 'K6'); // envio pela conta A (canal inst-a)

        $log = AutoReplyLog::withoutAccountScope()->where('status', 'sent')->firstOrFail();
        $this->assertSame('CONTEUDO-DA-A', $log->response_text);
        $this->assertStringNotContainsString('DA-B', $log->response_text);
    }

    // ---- Detector + editor -------------------------------------------------------

    public function test_detector_avisa_kb_desconhecido_e_aceita_valido(): void
    {
        $this->kb('Horários', 'Seg a Sex.');
        $this->kb('Interno', 'X', 'medium'); // nao-referenciavel: tambem avisa

        $refs = Variable::unknownRefs($this->account->id, 'Veja {kb:horarios} e {kb:nao-existe} e {kb:interno}');

        $this->assertContains('kb:nao-existe', $refs);
        $this->assertContains('kb:interno', $refs);
        $this->assertNotContains('kb:horarios', $refs);
    }

    public function test_dropdown_do_editor_lista_so_referenciaveis_e_insere_o_token(): void
    {
        $this->kb('Horários Público', 'Seg a Sex.');
        $this->kb('Dossie Sensivel', 'X', 'medium');

        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false, 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'MENU']);
        $flow->update(['root_node_id' => $root->id]);

        $tela = Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->assertSee('Horários Público')      // referenciavel: no dropdown
            ->assertDontSee('Dossie Sensivel');  // sensivel: fora

        // Inserir anexa {kb:slug} no FIM do campo (padrao do inserirSenhaNo).
        $tela->call('inserirConhecimentoNo', $root->id, 'horarios-publico')
            ->assertSet("nodeMsg.{$root->id}", 'MENU {kb:horarios-publico}');

        // Slug inelegivel (sensivel): a acao forjada NAO insere.
        $tela->call('inserirConhecimentoNo', $root->id, 'dossie-sensivel')
            ->assertSet("nodeMsg.{$root->id}", 'MENU {kb:horarios-publico}');
    }
}
