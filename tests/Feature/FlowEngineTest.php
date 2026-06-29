<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Whatsapp\Flows\FlowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fatia A — motor de fluxos, testado HEADLESS (sem enviar). Cobre o ponto de risco:
 * isolamento de sessao por contato, expiracao no timeout, opcao invalida nao avanca.
 */
class FlowEngineTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private Flow $flow;
    private FlowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'T']);
        $this->engine = app(FlowEngine::class);
        $this->flow = $this->buildFlow();
    }

    /** Arvore: root(menu) 1->Suporte(final) 2->Vendas(menu) [1->Carros(final), 2->Motos(final)] */
    private function buildFlow(): Flow
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'Atendimento', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);

        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'Bem-vindo! 1 - Suporte / 2 - Vendas']);
        $suporte = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'Suporte: abra um chamado em ...']);
        $vendas = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'Vendas: 1 - Carros / 2 - Motos']);
        $carros = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'Carros: falar com Joao']);
        $motos = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'Motos: falar com Maria']);

        $root->options()->create(['input' => '1', 'label' => '1 - Suporte', 'next_node_id' => $suporte->id, 'ordem' => 1]);
        $root->options()->create(['input' => '2', 'label' => '2 - Vendas', 'next_node_id' => $vendas->id, 'ordem' => 2]);
        $vendas->options()->create(['input' => '1', 'label' => '1 - Carros', 'next_node_id' => $carros->id, 'ordem' => 1]);
        $vendas->options()->create(['input' => '2', 'label' => '2 - Motos', 'next_node_id' => $motos->id, 'ordem' => 2]);

        $flow->update(['root_node_id' => $root->id]);

        return $flow->fresh();
    }

    private function start(string $jid): FlowSession
    {
        $r = $this->engine->start($this->account->id, $this->flow, $jid);

        return $r['session'];
    }

    public function test_entry_detecta_e_inicia_no_root(): void
    {
        $f = $this->engine->entryFlow($this->account->id, 'quero ver o menu', 'a@s.whatsapp.net');
        $this->assertNotNull($f);

        $r = $this->engine->start($this->account->id, $f, 'a@s.whatsapp.net');
        $this->assertStringContainsString('Bem-vindo', $r['text']);
        $this->assertSame('active', $r['status']);
    }

    public function test_avanca_para_resposta_final(): void
    {
        $s = $this->start('a@s.whatsapp.net');
        $r = $this->engine->advance($s, '1');
        $this->assertStringContainsString('Suporte', $r['text']);
        $this->assertSame('completed', $r['status']);
    }

    public function test_navegacao_multinivel(): void
    {
        $s = $this->start('a@s.whatsapp.net');
        $r1 = $this->engine->advance($s, '2'); // -> Vendas (menu)
        $this->assertStringContainsString('Vendas', $r1['text']);
        $this->assertSame('active', $r1['status']);

        $r2 = $this->engine->advance($r1['session'], '1'); // -> Carros (final)
        $this->assertStringContainsString('Carros', $r2['text']);
        $this->assertSame('completed', $r2['status']);
    }

    public function test_opcao_invalida_nao_avanca(): void
    {
        $s = $this->start('a@s.whatsapp.net');
        $r = $this->engine->advance($s, '9'); // nao existe
        $this->assertSame('active', $r['status']);
        $this->assertStringContainsString('invalida', mb_strtolower($r['text']));
        // continua no root (current_node nao mudou)
        $this->assertSame($this->flow->root_node_id, (int) $r['session']->current_node_id);
    }

    public function test_input_tolera_pontuacao(): void
    {
        $s = $this->start('a@s.whatsapp.net');
        $r = $this->engine->advance($s, '1.'); // "1." casa opcao "1"
        $this->assertSame('completed', $r['status']);
    }

    public function test_sessao_isola_por_contato(): void
    {
        $a = $this->start('a@s.whatsapp.net');
        $this->engine->advance($a, '2'); // A vai pra Vendas

        // B ainda nao tem sessao.
        $this->assertNull($this->engine->activeSession($this->account->id, 'b@s.whatsapp.net'));

        $b = $this->start('b@s.whatsapp.net'); // B comeca do root
        $this->assertSame($this->flow->root_node_id, (int) $b->current_node_id);

        // A continua na Vendas, independente do B.
        $aAtual = $this->engine->activeSession($this->account->id, 'a@s.whatsapp.net');
        $this->assertNotSame($this->flow->root_node_id, (int) $aAtual->current_node_id);
    }

    public function test_expira_no_timeout(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo'));
        $this->start('a@s.whatsapp.net'); // timeout 600s
        $this->assertNotNull($this->engine->activeSession($this->account->id, 'a@s.whatsapp.net'));

        // 11 min depois -> expirou.
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertNull($this->engine->activeSession($this->account->id, 'a@s.whatsapp.net'));
        $this->assertDatabaseHas('flow_sessions', ['remote_jid' => 'a@s.whatsapp.net', 'status' => 'expired']);
        Carbon::setTestNow();
    }

    public function test_reentrada_reinicia_na_raiz(): void
    {
        $s = $this->start('a@s.whatsapp.net');
        $r1 = $this->engine->advance($s, '2'); // Vendas
        $r2 = $this->engine->advance($r1['session'], 'menu'); // gatilho de entrada -> reinicia
        $this->assertStringContainsString('Bem-vindo', $r2['text']);
        $this->assertSame($this->flow->root_node_id, (int) $r2['session']->current_node_id);
    }

    public function test_cancelar_encerra(): void
    {
        $s = $this->start('a@s.whatsapp.net');
        $r = $this->engine->advance($s, 'sair');
        $this->assertSame('cancelled', $r['status']);
        $this->assertNull($this->engine->activeSession($this->account->id, 'a@s.whatsapp.net'));
    }

    public function test_reinicia_apos_final(): void
    {
        // Decisao 5: depois de uma resposta final, o gatilho comeca do zero.
        $s = $this->start('a@s.whatsapp.net');
        $this->engine->advance($s, '1'); // final -> completed
        $this->assertNull($this->engine->activeSession($this->account->id, 'a@s.whatsapp.net'));

        $f = $this->engine->entryFlow($this->account->id, 'menu', 'a@s.whatsapp.net');
        $novo = $this->engine->start($this->account->id, $f, 'a@s.whatsapp.net');
        $this->assertSame('active', $novo['status']);
        $this->assertSame($this->flow->root_node_id, (int) $novo['session']->current_node_id);
    }
}
