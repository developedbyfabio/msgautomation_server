<?php

namespace Tests\Feature;

use App\Livewire\Campanhas;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\CampaignTarget;
use App\Models\Card;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ProactiveCampaign;
use App\Models\Tag;
use App\Whatsapp\Proactive\AgendaBuilder;
use App\Whatsapp\Proactive\AudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proativas P-2 — campanhas com gate humano. NADA dispara (Http::assertNothingSent
 * em todos os fluxos). Publico SO com opt-in (filtro estrutural), snapshot congela
 * na aprovacao, agenda com jitter dentro da janela com transbordo.
 */
class CampanhasTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta 10h SP (dentro da janela proativa default 09-18).
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);
        $this->board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contato(string $sufixo, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'account_id' => $this->account->id,
            'remote_jid' => "55419999{$sufixo}@s.whatsapp.net",
            'push_name' => "Contato {$sufixo}",
            'auto_reply_mode' => 'on',
            'proactive_opt_in' => true,
        ], $extra));
    }

    private function campanha(array $extra = []): ProactiveCampaign
    {
        return ProactiveCampaign::create(array_merge([
            'account_id' => $this->account->id,
            'name' => 'Follow-up',
            'message' => '{saudacao}, {nome}! Ainda tem interesse?',
            'audience_type' => 'contatos',
            'audience_config' => ['contact_ids' => []],
            'status' => 'draft',
        ], $extra));
    }

    // ---- resolucao de publico (filtro ESTRUTURAL) --------------------------------

    public function test_publico_por_contatos_exclui_sem_opt_in_off_e_grupo_com_motivo(): void
    {
        $ok = $this->contato('0001');
        $semOptIn = $this->contato('0002', ['proactive_opt_in' => false]);
        $off = $this->contato('0003', ['auto_reply_mode' => 'off']);
        $grupo = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '123456@g.us', 'proactive_opt_in' => true]);

        $res = app(AudienceResolver::class)->resolve($this->account->id, 'contatos', [
            'contact_ids' => [$ok->id, $semOptIn->id, $off->id, $grupo->id],
        ]);

        $this->assertSame([$ok->id], $res['eligiveis']->pluck('id')->all());
        $motivos = collect($res['excluidos'])->mapWithKeys(fn ($e) => [$e['contact']->id => $e['motivo']]);
        $this->assertSame('sem_opt_in', $motivos[$semOptIn->id]);
        $this->assertSame('off', $motivos[$off->id]);
        $this->assertSame('grupo', $motivos[$grupo->id]);
    }

    public function test_publico_por_tags_qualquer_uma(): void
    {
        $vip = Tag::create(['account_id' => $this->account->id, 'name' => 'vip']);
        $lead = Tag::create(['account_id' => $this->account->id, 'name' => 'lead']);
        $c1 = $this->contato('0001');
        $c2 = $this->contato('0002');
        $fora = $this->contato('0003');
        $c1->tags()->attach($vip->id, ['origin' => 'manual']);
        $c2->tags()->attach($lead->id, ['origin' => 'manual']);

        $res = app(AudienceResolver::class)->resolve($this->account->id, 'tags', ['tag_ids' => [$vip->id, $lead->id]]);

        $this->assertEqualsCanonicalizing([$c1->id, $c2->id], $res['eligiveis']->pluck('id')->all());
    }

    public function test_publico_por_coluna_do_kanban_e_retrato_atual(): void
    {
        $novo = $this->board->columns()->where('slug', 'novo')->first();
        $c1 = $this->contato('0001');
        $c2 = $this->contato('0002');
        Card::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'contact_id' => $c1->id, 'column_id' => $novo->id]);

        $res = app(AudienceResolver::class)->resolve($this->account->id, 'coluna_kanban', ['column_id' => $novo->id]);

        $this->assertSame([$c1->id], $res['eligiveis']->pluck('id')->all()); // c2 sem card na coluna
    }

    // ---- estados: draft -> preview -> approved (snapshot) -----------------------------

    public function test_fluxo_draft_preview_aprovar_congela_snapshot(): void
    {
        $c1 = $this->contato('0001');
        $c2 = $this->contato('0002');
        $camp = $this->campanha(['audience_config' => ['contact_ids' => [$c1->id, $c2->id]]]);

        Livewire::test(Campanhas::class)
            ->call('openPreview', $camp->id)
            ->call('askApprove', $camp->id)
            ->call('approveConfirmed');

        $camp->refresh();
        $this->assertSame('approved', $camp->status);
        $this->assertNotNull($camp->approved_at);
        $this->assertSame(2, CampaignTarget::where('campaign_id', $camp->id)->where('status', 'pending')->count());
        Http::assertNothingSent(); // aprovar NAO dispara nada

        // Snapshot CONGELADO: contato novo com opt-in depois nao entra.
        $this->contato('0009');
        $this->assertSame(2, CampaignTarget::where('campaign_id', $camp->id)->count());
    }

    public function test_aprovada_trava_edicao_e_desaprovar_libera(): void
    {
        $c1 = $this->contato('0001');
        $camp = $this->campanha(['audience_config' => ['contact_ids' => [$c1->id]]]);
        Livewire::test(Campanhas::class)
            ->call('openPreview', $camp->id)->call('askApprove', $camp->id)->call('approveConfirmed');

        // Editar aprovada: bloqueado (form nem abre).
        Livewire::test(Campanhas::class)
            ->call('edit', $camp->id)
            ->assertSet('showForm', false);

        // Des-aprovar: apaga pendentes e volta pra draft editavel.
        Livewire::test(Campanhas::class)
            ->call('askUnapprove', $camp->id)
            ->call('unapproveConfirmed');

        $camp->refresh();
        $this->assertSame('draft', $camp->status);
        $this->assertNull($camp->approved_at);
        $this->assertSame(0, CampaignTarget::where('campaign_id', $camp->id)->count());
        Livewire::test(Campanhas::class)->call('edit', $camp->id)->assertSet('showForm', true);
    }

    public function test_cancelar_marca_pendentes_como_skipped(): void
    {
        $c1 = $this->contato('0001');
        $camp = $this->campanha(['audience_config' => ['contact_ids' => [$c1->id]]]);
        Livewire::test(Campanhas::class)
            ->call('openPreview', $camp->id)->call('askApprove', $camp->id)->call('approveConfirmed');

        Livewire::test(Campanhas::class)
            ->call('askCancel', $camp->id)
            ->call('cancelConfirmed');

        $this->assertSame('cancelled', $camp->fresh()->status);
        $this->assertDatabaseHas('campaign_targets', ['campaign_id' => $camp->id, 'status' => 'skipped', 'skip_reason' => 'cancelada']);
        $this->assertSame(0, CampaignTarget::where('campaign_id', $camp->id)->where('status', 'pending')->count());
        Http::assertNothingSent();
    }

    public function test_preview_e_retrato_re_resolvido(): void
    {
        $c1 = $this->contato('0001');
        $camp = $this->campanha(['audience_config' => ['contact_ids' => [$c1->id]]]);

        // Abre preview: elegivel. Contato revoga opt-in -> preview re-resolve: excluido.
        $comp = Livewire::test(Campanhas::class)->call('openPreview', $camp->id);
        $comp->assertSee('Contato 0001');
        $c1->update(['proactive_opt_in' => false]);
        $comp = Livewire::test(Campanhas::class)->call('openPreview', $camp->id);
        $comp->assertSee('sem opt-in');
    }

    // ---- agenda: janela + jitter + transbordo ------------------------------------------

    public function test_agenda_dentro_da_janela_com_jitter_na_faixa(): void
    {
        $settings = AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->first();
        $horarios = app(AgendaBuilder::class)->build($settings, null, 10);

        $tz = config('app.display_timezone');
        $anterior = null;
        foreach ($horarios as $h) {
            $local = $h->copy()->setTimezone($tz);
            // SEMPRE dentro da janela (09-18 default).
            $this->assertGreaterThanOrEqual('09:00:00', $local->format('H:i:s'));
            $this->assertLessThanOrEqual('18:00:00', $local->format('H:i:s'));
            if ($anterior !== null && $local->isSameDay($anterior)) {
                // Jitter na faixa (3-15min default).
                $delta = $anterior->diffInMinutes($local);
                $this->assertGreaterThanOrEqual(3, $delta);
                $this->assertLessThanOrEqual(15, $delta);
            }
            $anterior = $local;
        }
    }

    public function test_agenda_transborda_pro_dia_seguinte(): void
    {
        // 17:55 SP: so cabe ~1 slot hoje; o resto vai pra amanha 09:00+.
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 17, 55, 0, 'America/Sao_Paulo'));
        $settings = AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->first();

        $horarios = app(AgendaBuilder::class)->build($settings, null, 5);

        $tz = config('app.display_timezone');
        $dias = collect($horarios)->map(fn ($h) => $h->copy()->setTimezone($tz)->format('Y-m-d'))->unique();
        $this->assertContains('2026-07-02', $dias->all()); // transbordou
        foreach ($horarios as $h) {
            $local = $h->copy()->setTimezone($tz)->format('H:i:s');
            $this->assertGreaterThanOrEqual('09:00:00', $local);
            $this->assertLessThanOrEqual('18:00:00', $local);
        }
    }

    public function test_agenda_antes_da_janela_comeca_na_abertura(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 6, 0, 0, 'America/Sao_Paulo'));
        $settings = AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->first();

        $horarios = app(AgendaBuilder::class)->build($settings, null, 1);

        $this->assertSame('09:00:00', $horarios[0]->copy()->setTimezone(config('app.display_timezone'))->format('H:i:s'));
    }

    public function test_unique_de_target_por_contato(): void
    {
        $c1 = $this->contato('0001');
        $camp = $this->campanha();
        CampaignTarget::create(['campaign_id' => $camp->id, 'contact_id' => $c1->id, 'status' => 'pending']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        CampaignTarget::create(['campaign_id' => $camp->id, 'contact_id' => $c1->id, 'status' => 'pending']);
    }

    // ---- guarda de segredo ----------------------------------------------------------------

    public function test_segredo_na_mensagem_bloqueado_no_save(): void
    {
        Livewire::test(Campanhas::class)
            ->call('novo')
            ->set('cName', 'Teste')
            ->set('cMessage', 'Sua senha: {senha:wifi}')
            ->set('cAudienceType', 'contatos')
            ->set('cContactIds', [$this->contato('0001')->id])
            ->call('save')
            ->assertHasErrors('cMessage');

        $this->assertDatabaseCount('proactive_campaigns', 0);
    }

    public function test_crud_draft_e_validacao_de_publico_vazio(): void
    {
        Livewire::test(Campanhas::class)
            ->call('novo')
            ->set('cName', 'Sem publico')
            ->set('cMessage', 'Oi!')
            ->set('cAudienceType', 'tags')
            ->set('cTagIds', [])
            ->call('save')
            ->assertHasErrors('cAudienceType');

        $tag = Tag::create(['account_id' => $this->account->id, 'name' => 'vip']);
        Livewire::test(Campanhas::class)
            ->call('novo')
            ->set('cName', 'Com publico')
            ->set('cMessage', 'Oi, {nome}!')
            ->set('cAudienceType', 'tags')
            ->set('cTagIds', [$tag->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('proactive_campaigns', ['name' => 'Com publico', 'status' => 'draft']);
        Http::assertNothingSent();
    }
}
