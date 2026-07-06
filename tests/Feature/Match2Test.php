<?php

namespace Tests\Feature;

use App\Livewire\Regras;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\RuleTrigger;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 19 — MATCH-2: matriz de typos pt-BR (transposicao, falta de letra,
 * foneticos, repeticao expressiva), anti-falso-positivo, simetria/idempotencia,
 * geracoes de backfill indistinguiveis, ranking inalterado e o fix de layout.
 * A suite de matching PRE-existente e o contrato — roda sem nenhuma alteracao.
 */
class Match2Test extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private RuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(\App\Tenancy\AccountContext::class)->set($this->account->id);
        $this->matcher = app(RuleMatcher::class);
    }

    /** Regra com UM gatilho. Retorna a regra. */
    private function regra(string $valor, string $tipo = 'contains', string $precisao = 'tolerante', string $nivel = 'media'): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => $tipo, 'match_value' => $valor,
            'response_text' => "r-{$valor}", 'enabled' => true,
        ]);
        $rule->responses()->create(['response_text' => "r-{$valor}"]);
        $rule->triggers()->create([
            'match_type' => $tipo, 'match_value' => $valor,
            'precision' => $precisao, 'fuzzy_level' => $precisao === 'tolerante' ? $nivel : null,
        ]);

        return $rule;
    }

    private function match(?string $text): ?AutoReplyRule
    {
        return $this->matcher->match($this->account->id, null, $text);
    }

    // ---- Matriz de typos (tolerante, nivel media salvo indicacao) --------------

    public function test_transposicao_adjacente_conta_1(): void
    {
        $pix = $this->regra('pix');
        $this->assertSame($pix->id, $this->match('quero pagar por pxi')?->id, '"pxi" (transposicao) casa "pix"');

        $que = $this->regra('que horas');
        $this->assertSame($que->id, $this->match('qeu horas abre?')?->id, '"qeu horas" casa "que horas"');
    }

    public function test_falta_de_letra(): void
    {
        $senha = $this->regra('senha');
        $this->assertSame($senha->id, $this->match('qual a snha do wifi?')?->id);

        $endereco = $this->regra('endereço');
        $this->assertSame($endereco->id, $this->match('me passa o endereo')?->id);
    }

    public function test_colapsos_foneticos_pt_br(): void
    {
        $preco = $this->regra('preço');
        $this->assertSame($preco->id, $this->match('qual o presso?')?->id, 'presso ≈ preço');
        $this->assertSame($preco->id, $this->match('qual o presu?')?->id, 'presu ≈ preço');

        $horario = $this->regra('horário');
        $this->assertSame($horario->id, $this->match('qual o orario?')?->id, 'orario ≈ horário');

        $mesa = $this->regra('mesa');
        $this->assertSame($mesa->id, $this->match('quero reservar uma meza')?->id, 'meza ≈ mesa');

        $chave = $this->regra('chave');
        $this->assertSame($chave->id, $this->match('perdi a xave')?->id, 'xave ≈ chave');
    }

    public function test_repeticao_expressiva_na_base_casa_ate_no_exato(): void
    {
        $oi = $this->regra('oi', 'exact', 'exato');
        $this->assertSame($oi->id, $this->match('oiii')?->id, 'run de 3+ colapsa na BASE');
        $this->assertNull($this->match('oii'), 'run de 2 NAO colapsa na base (contrato estrito)');

        $sim = $this->regra('sim', 'exact', 'exato');
        $this->assertSame($sim->id, $this->match('simmm')?->id);
    }

    public function test_run_de_2_casa_no_tolerante_via_fonetica(): void
    {
        $oi = $this->regra('oi', 'contains', 'tolerante');
        $this->assertSame($oi->id, $this->match('oii')?->id, 'dedup fonetico: oii ≈ oi no tolerante');
    }

    // ---- Anti-falso-positivo ---------------------------------------------------

    public function test_palavra_curta_nao_tolera_edicao_so_transposicao(): void
    {
        $this->regra('pix');
        $this->assertNull($this->match('meu pai chegou'), '"pai" (2 substituicoes) NAO casa "pix"');

        $ola = $this->regra('ola', 'contains', 'exato');
        $this->assertNull($this->match('quero cola branca'), '"ola" estrito nao casa dentro/perto de "cola"');
    }

    public function test_pares_proximos_que_nao_casam_no_nivel_media(): void
    {
        $this->regra('sonho');
        $this->assertNull($this->match('minha senha sumiu'), 'senha != sonho');

        $this->regra('prato');
        $this->assertNull($this->match('qual o preco?'), 'preco != prato');

        $this->regra('armario');
        $this->assertNull($this->match('qual o horario?'), 'horario != armario');

        $this->regra('bolo');
        $this->assertNull($this->match('paguei o boleto'), 'boleto != bolo');

        $this->regra('entrada');
        $this->assertNull($this->match('cade minha entrega?'), 'entrega != entrada');
    }

    public function test_intensidades_distintas_produzem_resultados_distintos(): void
    {
        // "presu" x "preço": distancia fonetica 1. baixa (len5 -> folga 0) NAO casa;
        // media (folga 1) casa — o mapa nivel->limiar e observavel.
        $baixa = $this->regra('preço', 'contains', 'tolerante', 'baixa');
        $this->assertNull($this->match('qual o presu?'), 'baixa nao tolera 1 edicao em token de 5');

        $baixa->triggers()->first()->update(['fuzzy_level' => 'media']);
        $this->assertSame($baixa->id, $this->match('qual o presu?')?->id, 'media tolera');
    }

    // ---- Simetria / idempotencia -------------------------------------------------

    public function test_normalizacao_e_idempotente_nas_duas_camadas(): void
    {
        $amostras = [
            'Que horas São?!', 'oiii', 'preço', 'presso', 'horário', 'CHAVE', 'meza',
            'wi-fi', 'endereço completo', 'quero qeijo', 'ha ha', 'ação çç', 'oii',
        ];
        foreach ($amostras as $s) {
            $n = TextNormalizer::normalize($s);
            $this->assertSame($n, TextNormalizer::normalize($n), "normalize nao-idempotente pra '{$s}'");
            $p = TextNormalizer::phonetic($s);
            $this->assertSame($p, TextNormalizer::phonetic($p), "phonetic nao-idempotente pra '{$s}'");
        }
    }

    public function test_pos_backfill_geracoes_antiga_e_nova_sao_indistinguiveis(): void
    {
        // Gatilho "antigo": criado agora, mas com as colunas derivadas REGREDIDAS
        // pra pipeline velha (sem squeeze, sem fonetica) — como estava em producao.
        $velha = $this->regra('oi mooço', 'contains', 'tolerante');
        DB::table('rule_triggers')->where('auto_reply_rule_id', $velha->id)->update([
            'normalized_text' => 'oi mooco',    // pipeline VELHA (sem squeeze de 3+... aqui run de 2 mantido)
            'normalized_phonetic' => null,       // coluna nova nao existia
        ]);

        // Re-normaliza (o backfill oficial) e cria um gatilho NOVO identico.
        $this->artisan('msg:renormalize-triggers')->assertSuccessful();
        $nova = $this->regra('oi mooço', 'contains', 'tolerante');

        $tVelha = RuleTrigger::where('auto_reply_rule_id', $velha->id)->first();
        $tNova = RuleTrigger::where('auto_reply_rule_id', $nova->id)->first();
        $this->assertSame($tNova->normalized_text, $tVelha->normalized_text);
        $this->assertSame($tNova->normalized_phonetic, $tVelha->normalized_phonetic);

        // E o matching e identico nas duas geracoes (a mensagem tem typo).
        $ids = array_map(fn ($r) => $r->id, $this->matcher->allMatching($this->account->id, null, 'oi moso'));
        $this->assertContains($velha->id, $ids);
        $this->assertContains($nova->id, $ids);
    }

    public function test_gatilho_de_fluxo_tambem_usa_a_fonetica(): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'horário', 'precision' => 'tolerante', 'fuzzy_level' => 'media']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'MENU']);
        $flow->update(['root_node_id' => $root->id]);

        $this->assertTrue($this->matcher->listMatches($flow->fresh()->triggerList(), 'qual o orario?'));
        $this->assertFalse($this->matcher->listMatches($flow->fresh()->triggerList(), 'qual o armario?'));
    }

    // ---- Ranking inalterado -------------------------------------------------------

    public function test_dois_tolerantes_competindo_vence_o_mais_longo(): void
    {
        $curto = $this->regra('senha');
        $longo = $this->regra('senha wifi');

        // Typo casa os dois; vence o gatilho mais longo (especificidade atual).
        $this->assertSame($longo->id, $this->match('qual a snha wifi?')?->id);
        // So o curto casa quando "wifi" nao esta presente.
        $this->assertSame($curto->id, $this->match('qual a snha?')?->id);
    }

    // ---- Parte D: layout do dropdown de intensidade --------------------------------

    public function test_layout_intensidade_na_linha_do_gatilho(): void
    {
        Livewire::test(Regras::class)
            ->call('novo')
            ->set('triggers.0.precision', 'tolerante')
            ->assertSeeHtml('title="Intensidade da tolerancia"') // select na linha de controles
            ->assertSee('sons parecidos');                       // hint abaixo da linha inteira
    }
}
