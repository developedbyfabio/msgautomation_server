<?php

namespace Tests\Feature;

use App\Ai\KnowledgeWriter;
use App\Jobs\ClassifyWithAi;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Jobs\SendProactiveMessage;
use App\Livewire\Campanhas;
use App\Livewire\Fluxos;
use App\Livewire\Revisao;
use App\Livewire\Variaveis;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\CampaignTarget;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Models\PendingApproval;
use App\Models\ProactiveCampaign;
use App\Models\User;
use App\Models\Variable;
use App\Tenancy\AccountContext;
use App\Variables\VariableProvisioner;
use App\Variables\VariableWriter;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\AutoReply\RuleWriter;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\Proactive\AgendaBuilder;
use App\Whatsapp\Proactive\ProactiveGuard;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * V-1 — variaveis configuraveis. Provas centrais:
 *  - {saudacao} virou variavel de SISTEMA com default IDENTICO ao match()
 *    historico (bordas exatas), editavel mas nunca renomeavel/exclui-vel/desativavel;
 *  - custom static | horario (faixas podem cruzar meia-noite; fallback OBRIGATORIO)
 *    | dia_semana (fallback OBRIGATORIO), resolvidas em fuso SP, SO no envio;
 *  - guarda anti-bypass do S5: segredo JAMAIS em valor de variavel (writer E UI);
 *    sem variavel dentro de variavel; nomes reservados; slug; unicidade por conta;
 *  - referencia desconhecida/inativa sai INTACTA (comportamento historico) e os
 *    writers/telas AVISAM (nunca bloqueiam);
 *  - variavel NUNCA e expandida antes do modelo de IA;
 *  - cache por conta invalidado em qualquer escrita.
 * HTTP sempre mockado (nunca envio real).
 */
class VariaveisTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;
    private Channel $channel;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        // Quinta 02/07/2026 10:00 SP.
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']); // provisiona a {saudacao} de sistema
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
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

    // ---- helpers ----------------------------------------------------------------

    private function salvaVar(string $name, string $type, array $config, bool $active = true, ?int $id = null): array
    {
        return app(VariableWriter::class)->save($this->account->id, [
            'name' => $name, 'type' => $type, 'config' => $config, 'active' => $active,
        ], $id);
    }

    private function criaVar(string $name, string $type, array $config): Variable
    {
        $res = $this->salvaVar($name, $type, $config);
        $this->assertSame([], $res['errors'], "criaVar({$name}) falhou: " . json_encode($res['errors']));

        return $res['variable'];
    }

    private function render(string $template, array $context = []): string
    {
        return app(AccountContext::class)->runAs(
            $this->account->id,
            fn () => app(RuleResponder::class)->render($template, $context),
        );
    }

    /** Instante SP de julho/2026 (02 = quinta, 04 = sabado, 05 = domingo). */
    private function em(int $dia, int $hora, int $min = 0): Carbon
    {
        return Carbon::create(2026, 7, $dia, $hora, $min, 0, 'America/Sao_Paulo');
    }

    private function webhook(string $texto, string $id): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(AntiBanGuard::class),
        );
    }

    private function regra(string $gatilho, string $resposta): AutoReplyRule
    {
        $r = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains',
            'match_value' => $gatilho, 'response_text' => $resposta, 'enabled' => true,
        ]);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => $gatilho]);
        $r->responses()->create(['response_text' => $resposta]);

        return $r;
    }

    // ---- renderizacao: static ------------------------------------------------------

    public function test_static_resolve_no_template_e_case_insensitive(): void
    {
        $this->criaVar('empresa', 'static', ['valor' => 'Engepecas']);

        $this->assertSame('Oi, aqui e a Engepecas!', $this->render('Oi, aqui e a {empresa}!'));
        // Chave case-insensitive, como as nativas.
        $this->assertSame('Engepecas', $this->render('{EMPRESA}'));
    }

    // ---- renderizacao: horario (bordas, fora, meia-noite) ----------------------------

    public function test_horario_dentro_fora_bordas_exatas_e_cruzando_meia_noite(): void
    {
        $this->criaVar('expediente', 'horario', [
            'faixas' => [['inicio' => '08:00', 'fim' => '11:59', 'valor' => 'MANHA']],
            'valor_padrao' => 'FORA',
        ]);

        // Bordas EXATAS incluidas; 1 minuto depois da borda, fora.
        $this->assertSame('MANHA', $this->render('{expediente}', ['now' => $this->em(2, 8, 0)]));
        $this->assertSame('MANHA', $this->render('{expediente}', ['now' => $this->em(2, 11, 59)]));
        $this->assertSame('FORA', $this->render('{expediente}', ['now' => $this->em(2, 12, 0)]));
        $this->assertSame('FORA', $this->render('{expediente}', ['now' => $this->em(2, 7, 59)]));

        // Faixa que CRUZA meia-noite: 22:00-05:59 cobre 23:30 E 03:00.
        $this->criaVar('plantao', 'horario', [
            'faixas' => [['inicio' => '22:00', 'fim' => '05:59', 'valor' => 'PLANTAO']],
            'valor_padrao' => 'COMERCIAL',
        ]);
        $this->assertSame('PLANTAO', $this->render('{plantao}', ['now' => $this->em(2, 23, 30)]));
        $this->assertSame('PLANTAO', $this->render('{plantao}', ['now' => $this->em(2, 3, 0)]));
        $this->assertSame('COMERCIAL', $this->render('{plantao}', ['now' => $this->em(2, 12, 0)]));
    }

    public function test_horario_resolve_no_fuso_de_sao_paulo(): void
    {
        $this->criaVar('turno', 'horario', [
            'faixas' => [['inicio' => '22:00', 'fim' => '23:59', 'valor' => 'NOITE-SP']],
            'valor_padrao' => 'DIA',
        ]);

        // 01:30 UTC = 22:30 SP (UTC-3): resolve pela hora DE SP, nao pela UTC.
        $utc = Carbon::create(2026, 7, 3, 1, 30, 0, 'UTC');
        $this->assertSame('NOITE-SP', $this->render('{turno}', ['now' => $utc]));
        $this->assertSame('22:30', $this->render('{hora}', ['now' => $utc]));
    }

    // ---- renderizacao: dia_semana ------------------------------------------------------

    public function test_dia_semana_dia_coberto_e_padrao_nos_demais(): void
    {
        $this->criaVar('agenda', 'dia_semana', [
            'sab' => 'SO ATE MEIO-DIA', 'dom' => 'FECHADO',
            'valor_padrao' => 'DIA NORMAL',
        ]);

        $this->assertSame('SO ATE MEIO-DIA', $this->render('{agenda}', ['now' => $this->em(4, 10)])); // sabado
        $this->assertSame('FECHADO', $this->render('{agenda}', ['now' => $this->em(5, 10)]));         // domingo
        $this->assertSame('DIA NORMAL', $this->render('{agenda}', ['now' => $this->em(2, 10)]));      // quinta (nao coberto)
    }

    // ---- desconhecida / inativa: INTACTA (comportamento historico) ------------------------

    public function test_referencia_desconhecida_e_variavel_inativa_saem_intactas(): void
    {
        $this->assertSame('Oi {inexistente}!', $this->render('Oi {inexistente}!'));

        $v = $this->criaVar('promo', 'static', ['valor' => 'PROMO!']);
        $this->assertSame('PROMO!', $this->render('{promo}'));

        // Desativa: a referencia volta a sair CRUA (nunca string vazia).
        $this->salvaVar('promo', 'static', ['valor' => 'PROMO!'], false, $v->id);
        $this->assertSame('{promo}', $this->render('{promo}'));
    }

    public function test_cache_por_conta_invalida_em_qualquer_escrita(): void
    {
        $v = $this->criaVar('empresa', 'static', ['valor' => 'V1']);
        $this->assertSame('V1', $this->render('{empresa}')); // prime do cache

        // Escrita pelo writer: proximo render ja ve o valor novo.
        $this->salvaVar('empresa', 'static', ['valor' => 'V2'], true, $v->id);
        $this->assertSame('V2', $this->render('{empresa}'));

        // Escrita DIRETA no model (seed/teste): o observer tambem invalida.
        Variable::withoutAccountScope()->whereKey($v->id)->first()->update(['config' => ['valor' => 'V3']]);
        $this->assertSame('V3', $this->render('{empresa}'));
    }

    // ---- {saudacao}: variavel de SISTEMA, default IDENTICO ao historico ---------------------

    public function test_saudacao_de_sistema_provisionada_com_default_identico_ao_historico(): void
    {
        $s = Variable::withoutAccountScope()
            ->where('account_id', $this->account->id)->where('name', 'saudacao')->firstOrFail();
        $this->assertTrue((bool) $s->is_system);
        $this->assertTrue((bool) $s->active);
        $this->assertSame('horario', $s->type);
        $this->assertSame(VariableProvisioner::SAUDACAO_DEFAULT, $s->config);

        // ESPELHO do match() historico, incluindo as BORDAS exatas:
        // 05-11h "Bom dia" / 12-17h "Boa tarde" / resto "Boa noite".
        foreach ([
            [5, 0, 'Bom dia'], [11, 59, 'Bom dia'],
            [12, 0, 'Boa tarde'], [17, 59, 'Boa tarde'],
            [18, 0, 'Boa noite'], [4, 59, 'Boa noite'], [0, 0, 'Boa noite'],
        ] as [$h, $m, $esperado]) {
            $this->assertSame($esperado, $this->render('{saudacao}', ['now' => $this->em(2, $h, $m)]), "saudacao as {$h}:{$m}");
        }
    }

    public function test_saudacao_editada_muda_a_saida_mas_nome_tipo_e_ativa_ficam_travados(): void
    {
        $s = Variable::withoutAccountScope()
            ->where('account_id', $this->account->id)->where('name', 'saudacao')->firstOrFail();

        // Tenta renomear, mudar tipo e desativar junto com a edicao dos textos:
        // so os textos/faixas valem.
        $res = $this->salvaVar('outro_nome', 'static', [
            'faixas' => [['inicio' => '05:00', 'fim' => '11:59', 'valor' => 'Oieee, bom dia']],
            'valor_padrao' => 'Boa noite!',
        ], false, $s->id);

        $this->assertSame([], $res['errors']);
        $s->refresh();
        $this->assertSame('saudacao', $s->name);   // nome TRAVADO
        $this->assertSame('horario', $s->type);    // tipo TRAVADO
        $this->assertTrue((bool) $s->active);      // NUNCA desativa
        $this->assertSame('Oieee, bom dia', $this->render('{saudacao}', ['now' => $this->em(2, 10)]));
        $this->assertSame('Boa noite!', $this->render('{saudacao}', ['now' => $this->em(2, 14)])); // 14h fora das faixas editadas -> padrao
    }

    public function test_saudacao_sistema_nao_exclui_e_ui_nao_desativa_nem_abre_exclusao(): void
    {
        $s = Variable::withoutAccountScope()
            ->where('account_id', $this->account->id)->where('name', 'saudacao')->firstOrFail();

        $this->assertFalse(app(VariableWriter::class)->delete($this->account->id, $s->id));
        $this->assertDatabaseHas('variables', ['id' => $s->id, 'name' => 'saudacao']);

        Livewire::test(Variaveis::class)
            ->call('toggle', $s->id)      // no-op em sistema
            ->call('confirmDelete', $s->id)
            ->assertSet('confirmingDeleteId', null);
        $this->assertTrue((bool) $s->fresh()->active);
    }

    // ---- GUARDAS do writer -------------------------------------------------------------

    public function test_guarda_anti_bypass_do_s5_segredo_jamais_em_valor_de_variavel(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredo123');

        // static, faixa de horario, padrao de dia_semana e variacao de CAIXA: tudo bloqueado.
        $casos = [
            $this->salvaVar('vaza1', 'static', ['valor' => 'A senha e {senha:wifi}']),
            $this->salvaVar('vaza2', 'horario', [
                'faixas' => [['inicio' => '08:00', 'fim' => '18:00', 'valor' => '{senha:wifi}']],
                'valor_padrao' => 'x',
            ]),
            $this->salvaVar('vaza3', 'dia_semana', ['seg' => 'ok', 'valor_padrao' => 'pegue {senha:wifi}']),
            $this->salvaVar('vaza4', 'static', ['valor' => '{SENHA:wifi}']),
            // Mesmo sem a senha existir no cofre, a SINTAXE ja e bloqueada.
            $this->salvaVar('vaza5', 'static', ['valor' => '{senha:nao_existe}']),
        ];
        foreach ($casos as $i => $res) {
            $this->assertNull($res['variable'], "caso {$i} deveria bloquear");
            $this->assertArrayHasKey('config', $res['errors'], "caso {$i}");
        }
        $this->assertSame(0, Variable::withoutAccountScope()->where('name', 'like', 'vaza%')->count());

        // E na UI: o erro chega no campo do formulario.
        Livewire::test(Variaveis::class)
            ->call('novo')
            ->set('vName', 'vaza_ui')->set('vType', 'static')
            ->set('cValor', 'senha: {senha:wifi}')
            ->call('save')
            ->assertHasErrors('cValor');
        $this->assertDatabaseMissing('variables', ['name' => 'vaza_ui']);
    }

    public function test_sem_variavel_dentro_de_variavel(): void
    {
        // Um nivel so: valor com {qualquer_placeholder} e bloqueado (sem recursao).
        $res = $this->salvaVar('composta', 'static', ['valor' => 'Oi {nome}, tudo bem?']);
        $this->assertArrayHasKey('config', $res['errors']);

        $res = $this->salvaVar('composta2', 'horario', [
            'faixas' => [['inicio' => '08:00', 'fim' => '18:00', 'valor' => '{outra}']],
            'valor_padrao' => 'x',
        ]);
        $this->assertArrayHasKey('config', $res['errors']);
        $this->assertSame(0, Variable::withoutAccountScope()->where('name', 'like', 'composta%')->count());
    }

    public function test_nomes_reservados_slug_invalido_e_duplicado_bloqueados(): void
    {
        // Reservados (com variacao de caixa; acento cai na regra do slug antes).
        foreach (['nome', 'saudacao', 'data', 'hora', 'senha'] as $reservado) {
            $res = $this->salvaVar($reservado, 'static', ['valor' => 'x']);
            $this->assertArrayHasKey('name', $res['errors'], "reservado: {$reservado}");
        }
        $res = $this->salvaVar('NOME', 'static', ['valor' => 'x']); // caixa e normalizada -> reservado
        $this->assertArrayHasKey('name', $res['errors']);

        // Slug invalido (maiuscula/hifen/acento/vazio).
        foreach (['Meu-Nome', 'saudação', 'com espaco', ''] as $ruim) {
            $res = $this->salvaVar($ruim, 'static', ['valor' => 'x']);
            $this->assertArrayHasKey('name', $res['errors'], "slug: {$ruim}");
        }

        // Duplicado NA CONTA.
        $this->criaVar('empresa', 'static', ['valor' => 'x']);
        $res = $this->salvaVar('empresa', 'static', ['valor' => 'y']);
        $this->assertArrayHasKey('name', $res['errors']);
    }

    public function test_horario_e_dia_semana_exigem_valor_padrao_e_faixas_validas(): void
    {
        // horario sem padrao: bloqueado (o fallback e a rede de seguranca).
        $res = $this->salvaVar('h1', 'horario', [
            'faixas' => [['inicio' => '08:00', 'fim' => '18:00', 'valor' => 'x']],
            'valor_padrao' => '',
        ]);
        $this->assertArrayHasKey('config', $res['errors']);

        // faixa com horario fora de HH:MM: bloqueada.
        $res = $this->salvaVar('h2', 'horario', [
            'faixas' => [['inicio' => '8h', 'fim' => '18:00', 'valor' => 'x']],
            'valor_padrao' => 'y',
        ]);
        $this->assertArrayHasKey('config', $res['errors']);

        // dia_semana sem padrao: bloqueado (dias parciais permitidos, fallback nao).
        $res = $this->salvaVar('d1', 'dia_semana', ['sab' => 'x', 'valor_padrao' => '']);
        $this->assertArrayHasKey('config', $res['errors']);
    }

    public function test_sobreposicao_de_faixas_avisa_mas_salva(): void
    {
        $res = $this->salvaVar('sobre', 'horario', [
            'faixas' => [
                ['inicio' => '08:00', 'fim' => '12:00', 'valor' => 'A'],
                ['inicio' => '11:00', 'fim' => '14:00', 'valor' => 'B'],
            ],
            'valor_padrao' => 'C',
        ]);

        $this->assertSame([], $res['errors']); // salva
        $this->assertNotSame([], $res['warnings']); // mas avisa
        // Na sobreposicao (11:30), a PRIMEIRA faixa vence.
        $this->assertSame('A', $this->render('{sobre}', ['now' => $this->em(2, 11, 30)]));
    }

    // ---- AVISOS de referencia desconhecida nos writers/telas ------------------------------

    public function test_rule_writer_e_knowledge_writer_avisam_referencia_desconhecida(): void
    {
        $this->criaVar('empresa', 'static', ['valor' => 'Engepecas']);

        $regra = fn (string $resposta) => app(RuleWriter::class)->save($this->account->id, [
            'triggers' => [['type' => 'contains', 'value' => 'oi']],
            'responses' => [$resposta], 'enabled' => true,
            'cooldown_mode' => 'global', 'cooldown_minutes' => null,
            'scope' => 'global', 'contact_ids' => [],
            'ai_match_enabled' => false, 'ai_examples' => [],
        ]);

        // Desconhecida: salva COM aviso (nunca bloqueia — pode ser criada depois).
        $res = $regra('Oi {fantasma}, tudo bem?');
        $this->assertSame([], $res['errors']);
        $this->assertNotNull($res['rule']);
        $this->assertStringContainsString('{fantasma}', $res['warnings'][0] ?? '');

        // Nativas + variavel ATIVA: sem aviso.
        $res = $regra('{saudacao}, {nome}! Aqui e a {empresa}. Hoje: {data} {hora}.');
        $this->assertSame([], $res['warnings']);

        // {senha:...} e sintaxe do COFRE (dois pontos nao casa \w+): nunca vira "ref desconhecida".
        $this->assertSame([], Variable::unknownRefs($this->account->id, 'Chave: {senha:wifi}'));

        // KnowledgeWriter: mesmo contrato.
        $res = app(KnowledgeWriter::class)->save($this->account->id, [
            'title' => 'Endereco', 'content' => 'Estamos na {rua_x}',
            'sensitivity' => 'low', 'active' => true, 'contact_ids' => [],
        ]);
        $this->assertSame([], $res['errors']);
        $this->assertStringContainsString('{rua_x}', $res['warnings'][0] ?? '');
    }

    public function test_telas_avisam_campanha_no_de_fluxo_e_pendencia_editada(): void
    {
        // Campanha: salva (rascunho) e avisa via toast.
        $optIn = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => '5541888880000@s.whatsapp.net',
            'auto_reply_mode' => 'on', 'proactive_opt_in' => true,
        ]);
        Livewire::test(Campanhas::class)
            ->call('novo')
            ->set('cName', 'Promo')->set('cMessage', 'Oi {ghost_campanha}!')
            ->set('cAudienceType', 'contatos')->set('cContactIds', [$optIn->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($n, $p) => str_contains((string) ($p['message'] ?? ''), '{ghost_campanha}'));
        $this->assertDatabaseHas('proactive_campaigns', ['name' => 'Promo', 'status' => 'draft']);

        // No de fluxo: salva e avisa.
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false, 'timeout_seconds' => 600]);
        $node = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'x']);
        $flow->update(['root_node_id' => $node->id]);
        Livewire::test(Fluxos::class)
            ->call('editar', $flow->id)
            ->set("nodeMsg.{$node->id}", 'Tchau {ghost_fluxo}')
            ->call('salvarNo', $node->id)
            ->assertDispatched('toast', fn ($n, $p) => str_contains((string) ($p['message'] ?? ''), '{ghost_fluxo}'));
        $this->assertSame('Tchau {ghost_fluxo}', $node->fresh()->message);

        // Pendencia editada: AVISA mas ENVIA assim mesmo (decisao humana), cru.
        $im = IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => 'fabio-pessoal', 'evolution_message_id' => 'VP1',
            'remote_jid' => self::JID, 'from_me' => false, 'push_name' => 'Cliente',
            'type' => 'conversation', 'text' => 'preco?', 'raw_payload' => [], 'received_at' => now(),
        ]);
        $p = PendingApproval::create([
            'account_id' => $this->account->id, 'contact_id' => $this->contact->id,
            'incoming_message_id' => $im->id, 'remote_jid' => self::JID,
            'suggested_response' => 'Custa 100.', 'origin' => 'regra',
            'reason' => 'baixa_confianca', 'confidence' => 0.5, 'status' => 'pending',
        ]);
        Livewire::test(Revisao::class)
            ->call('startEdit', $p->id)
            ->set('editText', 'Custa 100. {ghost_pendencia}')
            ->call('confirmEdit')
            ->assertDispatched('toast', fn ($n, $p2) => str_contains((string) ($p2['message'] ?? ''), '{ghost_pendencia}'));
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], '{ghost_pendencia}')); // cru, intacto
    }

    // ---- ponta a ponta: envio reativo, campanha e IA ---------------------------------------

    public function test_variavel_custom_renderiza_no_envio_reativo_e_desconhecida_sai_crua(): void
    {
        $this->criaVar('expediente', 'horario', [
            'faixas' => [['inicio' => '08:00', 'fim' => '17:59', 'valor' => 'das 8h as 18h']],
            'valor_padrao' => 'so amanha a partir das 8h',
        ]);
        $this->regra('horario', 'Atendemos {expediente}.');
        $this->regra('cupom', 'Use o cupom {cupom_do_mes}!'); // variavel NAO existe

        $this->webhook('qual o horario?', 'V1'); // 10h -> dentro da faixa
        Http::assertSent(fn ($r) => $r['text'] === 'Atendemos das 8h as 18h.');

        $this->webhook('tem cupom?', 'V2');
        Http::assertSent(fn ($r) => $r['text'] === 'Use o cupom {cupom_do_mes}!'); // INTACTA no envio real
    }

    public function test_campanha_com_variavel_custom_renderiza_no_disparo(): void
    {
        AutoReplySetting::where('account_id', $this->account->id)->update(['proactive_enabled' => true]); // MOCK de teste
        $this->contact->update(['proactive_opt_in' => true]);
        $this->criaVar('oferta', 'static', ['valor' => 'frete gratis ate sexta']);

        $camp = ProactiveCampaign::create([
            'account_id' => $this->account->id, 'name' => 'Follow-up',
            'message' => '{saudacao}, {nome}! Temos {oferta}.',
            'audience_type' => 'contatos', 'audience_config' => ['contact_ids' => [$this->contact->id]],
            'status' => 'approved', 'approved_at' => now(),
        ]);
        $target = CampaignTarget::create([
            'campaign_id' => $camp->id, 'contact_id' => $this->contact->id,
            'status' => 'pending', 'scheduled_at' => now()->subMinute(),
        ]);

        (new SendProactiveMessage((int) $target->id, (int) $this->account->id))->handle(
            app(ProactiveGuard::class), app(Sender::class),
            app(RuleResponder::class), app(AgendaBuilder::class),
        );

        // 10h de quinta: saudacao da variavel de sistema + custom, tudo SO no envio.
        // (P-4: rodape de saida obrigatorio anexado ao final.)
        Http::assertSent(fn ($r) => $r['text'] === "Bom dia, Cliente! Temos frete gratis ate sexta.\n\nPara nao receber mais mensagens assim, responda PARAR.");
        $this->assertSame('sent', $target->fresh()->status);
    }

    public function test_variavel_custom_nunca_e_expandida_antes_do_modelo_de_ia(): void
    {
        AutoReplySetting::where('account_id', $this->account->id)->update(['ai_enabled' => true]);
        $this->contact->update(['ai_enabled' => true, 'ai_mode' => 'conhecimento']);
        $this->criaVar('endereco_loja', 'static', ['valor' => 'Rua das Pecas, 123']);
        Knowledge::create([
            'account_id' => $this->account->id, 'title' => 'Endereco',
            'content' => 'Nosso endereco: {endereco_loja}', 'sensitivity' => 'low', 'active' => true,
        ]);
        $im = IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => 'fabio-pessoal', 'evolution_message_id' => 'AI-V1',
            'remote_jid' => self::JID, 'from_me' => false, 'push_name' => 'Cliente',
            'type' => 'conversation', 'text' => 'qual o endereco?', 'raw_payload' => [], 'received_at' => now(),
        ]);

        $fake = new FakeVarAi();
        (new ClassifyWithAi($im->id, $this->account->id))->handle(
            $fake, app(AntiBanGuard::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(SecretVault::class), app(RuleResponder::class),
        );

        // MINIMIZACAO: o payload do modelo leva o placeholder CRU, nunca o valor.
        $payload = json_encode($fake->lastAnswerRequest?->entries ?? []);
        $this->assertStringContainsString('{endereco_loja}', $payload);
        $this->assertStringNotContainsString('Rua das Pecas', $payload);
    }

    // ---- UI /variaveis ------------------------------------------------------------------

    public function test_pagina_variaveis_crud_preview_e_exclusao_com_uso(): void
    {
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op@x.local', 'password' => Hash::make('senha-forte')]));
        $this->get('/variaveis')->assertOk()->assertSee('saudacao')->assertSee('{nome}');

        // Cria pela tela; o PREVIEW mostra o valor resolvido AGORA (mesmo renderizador).
        Livewire::test(Variaveis::class)
            ->call('novo')
            ->set('vName', 'frete')->set('vType', 'static')->set('cValor', 'Frete gratis PR')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Frete gratis PR');
        $this->assertDatabaseHas('variables', ['account_id' => $this->account->id, 'name' => 'frete', 'is_system' => false]);
        $frete = Variable::withoutAccountScope()->where('name', 'frete')->firstOrFail();

        // Usa a variavel em regra e no de fluxo; o modal de exclusao mostra o USO.
        $this->regra('frete', 'Temos {frete}!');
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false, 'timeout_seconds' => 600]);
        FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'E ai: {frete}']);

        Livewire::test(Variaveis::class)
            ->call('confirmDelete', $frete->id)
            ->assertSee('1 resposta(s) de regra')
            ->assertSee('1 no(s) de fluxo')
            ->assertSee('INTACTA')
            ->call('deleteConfirmed');

        $this->assertDatabaseMissing('variables', ['id' => $frete->id]);
        // Os textos que a usavam ficam INTACTOS (sairao crus ate ajustar).
        $this->assertDatabaseHas('rule_responses', ['response_text' => 'Temos {frete}!']);
    }
}

/** Driver falso: "nenhuma regra casou" -> answer nao-fundamentado; captura o request. */
class FakeVarAi implements \App\Contracts\AiClassifier
{
    public ?\App\Ai\AiAnswerRequest $lastAnswerRequest = null;

    public function classify(\App\Ai\AiClassificationRequest $request): \App\Ai\AiClassification
    {
        return new \App\Ai\AiClassification('', 0.9, null, false, false, 'nenhuma');
    }

    public function answer(\App\Ai\AiAnswerRequest $request): \App\Ai\AiAnswer
    {
        $this->lastAnswerRequest = $request;

        return new \App\Ai\AiAnswer('', false, 0.1, false, [], 'nao achei', null);
    }
}
