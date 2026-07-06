<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Card;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Models\FlowSession;
use App\Tenancy\AccountContext;
use App\Whatsapp\Flows\FlowTemplateCatalog;
use App\Whatsapp\Flows\InstantiateFlowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 7 — templates de fluxo de atendimento: catalogo em codigo (clinica, salao,
 * comercio) + instanciacao como fluxo REAL da conta (editavel, handoff funcional).
 * Cobre: integridade de TODO o catalogo, shape instanciado, abre no editor (5b),
 * handoff executa os efeitos (motor da 5), isolamento por conta, multiplas
 * instancias (sufixo) e default_flow_id INTACTO.
 */
class FlowTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']); // booted() provisiona o board default
        app(AccountContext::class)->set($this->account->id);
    }

    private function instanciar(string $key): Flow
    {
        return app(InstantiateFlowTemplate::class)->handle($key, $this->account->id);
    }

    // ---- Integridade do catalogo (guarda contra template malformado) ----------

    public function test_todos_os_templates_do_catalogo_instanciam_fluxos_validos(): void
    {
        $catalog = app(FlowTemplateCatalog::class);
        $this->assertCount(3, $catalog->all()); // clinica, salao, comercio

        foreach (array_keys($catalog->all()) as $key) {
            $flow = $this->instanciar($key)->fresh();

            // Fluxo ligado, com gatilho de entrada e raiz MENU com opcoes (Fatia 4:
            // menu sem opcao vira saudacao de um tiro — vetado em template).
            $this->assertTrue((bool) $flow->enabled, "template {$key}: enabled");
            $this->assertGreaterThan(0, $flow->triggers()->count(), "template {$key}: sem gatilho");
            $root = $flow->rootNode();
            $this->assertNotNull($root, "template {$key}: sem raiz");
            $this->assertSame('menu', $root->kind, "template {$key}: raiz nao e menu");
            $this->assertGreaterThan(0, $root->options()->count(), "template {$key}: raiz sem opcoes");

            $nodes = $flow->nodes()->with('options')->get();
            $ids = $nodes->pluck('id')->all();
            $destinos = [];
            foreach ($nodes as $node) {
                // Todo handoff e terminal e tem message (invariantes do editor 5b).
                if ($node->kind === 'handoff') {
                    $this->assertSame(0, $node->options->count(), "template {$key}: handoff #{$node->id} com opcoes");
                    $this->assertNotSame('', trim((string) $node->message), "template {$key}: handoff #{$node->id} sem message");
                }
                if ($node->kind === 'final') {
                    $this->assertSame(0, $node->options->count(), "template {$key}: final #{$node->id} com opcoes");
                }
                foreach ($node->options as $opt) {
                    // Todo destino de opcao resolve pra no do MESMO fluxo.
                    $this->assertNotNull($opt->next_node_id, "template {$key}: opcao \"{$opt->input}\" sem destino");
                    $this->assertContains((int) $opt->next_node_id, $ids, "template {$key}: destino fora do fluxo");
                    $this->assertNotSame('', trim((string) $opt->label), "template {$key}: opcao \"{$opt->input}\" sem rotulo");
                    $destinos[] = (int) $opt->next_node_id;
                }
                // Menu com opcoes e "Falar com atendente" -> handoff em algum lugar.
            }
            // Nenhum no orfao: todo no fora a raiz e destino de alguma opcao.
            foreach ($nodes as $node) {
                if ((int) $node->id !== (int) $flow->root_node_id) {
                    $this->assertContains((int) $node->id, $destinos, "template {$key}: no #{$node->id} orfao");
                }
            }
            // Todo template tem ao menos um handoff (caminho pro humano).
            $this->assertGreaterThan(0, $nodes->where('kind', 'handoff')->count(), "template {$key}: sem handoff");
        }
    }

    // ---- Shape da instanciacao (exemplar: clinica) -----------------------------

    public function test_instanciar_clinica_cria_o_shape_esperado_na_conta_ativa(): void
    {
        $flow = $this->instanciar('clinica')->fresh();

        $this->assertSame($this->account->id, (int) $flow->account_id);
        $this->assertTrue((bool) $flow->enabled);
        $this->assertSame('Clínica / consultório', $flow->name);
        $this->assertNotNull($flow->invalid_message);

        // raiz menu + 4 filhos (2 handoff, 2 final), 4 opcoes ligadas.
        $nodes = $flow->nodes()->get();
        $this->assertCount(5, $nodes);
        $this->assertSame(2, $nodes->where('kind', 'handoff')->count());
        $this->assertSame(2, $nodes->where('kind', 'final')->count());
        $this->assertSame(1, $nodes->where('kind', 'menu')->count());

        $root = $flow->rootNode();
        $opts = $root->options()->get();
        $this->assertSame(['1', '2', '3', '4'], $opts->pluck('input')->all());
        $this->assertSame('handoff', FlowNode::find($opts->firstWhere('input', '1')->next_node_id)->kind); // agendar -> humano
        $this->assertSame('final', FlowNode::find($opts->firstWhere('input', '2')->next_node_id)->kind);
        $this->assertSame('final', FlowNode::find($opts->firstWhere('input', '3')->next_node_id)->kind);
        $this->assertSame('handoff', FlowNode::find($opts->firstWhere('input', '4')->next_node_id)->kind); // atendente

        $this->assertStringContainsString('bem-vindo(a) à nossa clínica', $root->message);
    }

    // ---- Abre no editor (5b) sem quebrar ---------------------------------------

    public function test_fluxo_instanciado_abre_no_editor_sem_quebrar(): void
    {
        $flow = $this->instanciar('clinica');
        $handoff = FlowNode::where('flow_id', $flow->id)->where('kind', 'handoff')->orderBy('id')->first();

        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->assertSee('Agendar consulta')
            ->assertSet("nodeKind.{$handoff->id}", 'handoff')
            ->assertSet("nodeMsg.{$handoff->id}", $handoff->message);
    }

    // ---- UI: usar template instancia e redireciona pro editor -------------------

    public function test_usar_template_na_ui_instancia_e_abre_o_editor(): void
    {
        $c = Livewire::test(Fluxos::class)
            ->assertSee('Comecar com um modelo')
            ->assertSee('Clínica / consultório')
            ->call('usarTemplate', 'clinica');

        $flow = Flow::where('account_id', $this->account->id)->first();
        $this->assertNotNull($flow);
        $c->assertSet('editingFlowId', $flow->id)   // "redirect": abriu o editor do novo fluxo
            ->assertSet('name', $flow->name)
            ->assertSee('Agendar consulta');
    }

    public function test_usar_template_desconhecido_nao_cria_nada(): void
    {
        Livewire::test(Fluxos::class)->call('usarTemplate', 'nao-existe')
            ->assertSet('editingFlowId', null);

        $this->assertSame(0, Flow::where('account_id', $this->account->id)->count());
    }

    // ---- Handoff do fluxo instanciado FUNCIONA no motor (Fatia 5) ---------------

    public function test_handoff_do_fluxo_instanciado_executa_os_efeitos(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'operation_mode' => OperationMode::Auto,
            'window_start' => '00:00:00', 'window_end' => '23:59:59',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);

        $flow = $this->instanciar('clinica');

        $this->receber('menu', 'T1');   // gatilho do template abre o menu
        $this->receber('1', 'T2');      // "Agendar consulta" -> handoff

        // mensagem do handoff enviada + robo pausado + card em AGUARDANDO (fatia 11:
        // handoff = pendencia humana) + sessao terminal.
        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'sent', 'response_text' => 'Perfeito! Vou te transferir para um atendente que finaliza seu agendamento. Só um instante.']);
        $this->assertSame('off', Contact::withoutAccountScope()->where('remote_jid', self::JID)->first()->auto_reply_mode);
        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->first();
        $col = BoardColumn::query()->where('board_id', $board->id)->where('slug', 'aguardando')->first();
        $card = Card::withoutAccountScope()->where('board_id', $board->id)->first();
        $this->assertNotNull($card);
        $this->assertSame((int) $col->id, (int) $card->column_id);
        $sessao = FlowSession::withoutAccountScope()->where('remote_jid', self::JID)->latest('id')->first();
        $this->assertSame('handed_off', $sessao->status);
        $this->assertSame($flow->id, (int) $sessao->flow_id);
    }

    // ---- Isolamento por conta ----------------------------------------------------

    public function test_isolamento_instanciar_na_conta_a_nao_toca_a_conta_b(): void
    {
        $b = Account::create(['name' => 'B']);
        $flowsB = Flow::withoutAccountScope()->where('account_id', $b->id)->count();

        $flow = $this->instanciar('clinica');

        $this->assertSame($this->account->id, (int) $flow->account_id);
        $this->assertSame($flowsB, Flow::withoutAccountScope()->where('account_id', $b->id)->count()); // B inalterada
        // todos os nos/opcoes pendurados no fluxo da A (filhas sao escopadas pela FK).
        $this->assertSame(5, FlowNode::where('flow_id', $flow->id)->count());
        $this->assertSame(0, Flow::withoutAccountScope()->where('account_id', $b->id)->whereIn('id', [$flow->id])->count());
    }

    // ---- Multiplas instancias (sufixo em colisao) ---------------------------------

    public function test_instanciar_o_mesmo_template_duas_vezes_sufixa_o_nome(): void
    {
        $a = $this->instanciar('clinica');
        $b = $this->instanciar('clinica');

        $this->assertSame('Clínica / consultório', $a->name);
        $this->assertSame('Clínica / consultório (2)', $b->name);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(5, FlowNode::where('flow_id', $b->id)->count()); // segunda instancia integra
    }

    // ---- NAO seta default_flow_id --------------------------------------------------

    public function test_instanciar_nao_seta_default_flow_id(): void
    {
        $settings = AutoReplySetting::create(['account_id' => $this->account->id, 'enabled' => true]);
        $this->assertNull($settings->default_flow_id);

        $this->instanciar('clinica');
        $this->assertNull($settings->fresh()->default_flow_id); // continua sem padrao

        // e se JA havia um padrao, ele permanece o que era.
        $outro = Flow::create(['account_id' => $this->account->id, 'name' => 'Meu padrao', 'enabled' => true, 'timeout_seconds' => 600]);
        $settings->update(['default_flow_id' => $outro->id]);
        $this->instanciar('salao');
        $this->assertSame($outro->id, (int) $settings->fresh()->default_flow_id);
    }

    // ---- Recursao: blueprint com sub-menu (profundidade > 1) -----------------------

    public function test_blueprint_com_sub_menu_instancia_em_profundidade(): void
    {
        $catalog = new class extends FlowTemplateCatalog
        {
            public function all(): array
            {
                return ['deep' => [
                    'key' => 'deep', 'name' => 'Deep', 'description' => 'sub-menu',
                    'triggers' => [['type' => 'contains', 'value' => 'deep']],
                    'root' => ['kind' => 'menu', 'message' => 'M1', 'options' => [
                        ['input' => '1', 'label' => 'Sub', 'node' => [
                            'kind' => 'menu', 'message' => 'M2', 'options' => [
                                ['input' => '1', 'label' => 'Humano', 'node' => ['kind' => 'handoff', 'message' => 'Aguarde.']],
                            ],
                        ]],
                    ]],
                ]];
            }
        };

        $flow = (new InstantiateFlowTemplate($catalog))->handle('deep', $this->account->id);

        $sub = FlowNode::where('flow_id', $flow->id)->where('message', 'M2')->first();
        $handoff = FlowNode::where('flow_id', $flow->id)->where('kind', 'handoff')->first();
        $this->assertSame((int) $flow->rootNode()->id, (int) $sub->parent_node_id);
        $this->assertSame((int) $sub->id, (int) $handoff->parent_node_id);
        $this->assertSame((int) $handoff->id, (int) FlowOption::where('flow_node_id', $sub->id)->first()->next_node_id);
    }

    // ---- helper do pipeline (mesmo caminho da FlowHandoffTest) ---------------------

    private function receber(string $texto, string $id): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'inst-a',
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
}
