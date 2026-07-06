<?php

namespace Tests\Feature;

use App\Livewire\Campanhas;
use App\Models\Account;
use App\Models\CampaignTarget;
use App\Models\Contact;
use App\Models\ProactiveCampaign;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 13 — duplicar campanha como RASCUNHO LIMPO: copia conteudo + publico
 * (name/message/optout_footer/audience_type/audience_config), forca draft e
 * ZERA execucao (start_at/approved_at/approved_by null; targets nao copiados).
 * NUNCA dispara nada. Original intacta (qualquer status). Posse por conta.
 */
class DuplicateCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    private function campanhaAprovada(): ProactiveCampaign
    {
        $aprovador = \App\Models\User::create(['name' => 'Op', 'email' => 'op@x.local', 'password' => \Illuminate\Support\Facades\Hash::make('senha-forte-123')]);
        $c = ProactiveCampaign::create([
            'account_id' => $this->account->id,
            'name' => 'Promo Julho',
            'message' => 'Oferta especial pra voce!',
            'optout_footer' => 'Responda {palavra_sair} para nao receber mais.',
            'audience_type' => 'tags',
            'audience_config' => ['tag_ids' => [1, 2]],
            'status' => 'approved',
            'start_at' => now()->addDay(),
            'approved_at' => now(),
            'approved_by' => $aprovador->id,
        ]);
        $c1 = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541999990010@s.whatsapp.net', 'auto_reply_mode' => 'on']);
        $c2 = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541999990011@s.whatsapp.net', 'auto_reply_mode' => 'on']);
        CampaignTarget::create(['campaign_id' => $c->id, 'contact_id' => $c1->id, 'status' => 'pending']);
        CampaignTarget::create(['campaign_id' => $c->id, 'contact_id' => $c2->id, 'status' => 'sent']);

        return $c;
    }

    public function test_copia_nasce_draft_com_conteudo_igual_e_execucao_zerada(): void
    {
        $original = $this->campanhaAprovada(); // APROVADA (nem e draft) — duplica mesmo assim

        $tela = Livewire::test(Campanhas::class)->call('duplicate', $original->id);

        $copia = ProactiveCampaign::query()->where('name', 'Promo Julho (copia)')->firstOrFail();
        // Conteudo + publico copiados.
        $this->assertSame($original->message, $copia->message);
        $this->assertSame($original->optout_footer, $copia->optout_footer);
        $this->assertSame($original->audience_type, $copia->audience_type);
        $this->assertSame($original->audience_config, $copia->audience_config);
        // Execucao ZERADA.
        $this->assertSame('draft', $copia->status);
        $this->assertNull($copia->start_at);
        $this->assertNull($copia->approved_at);
        $this->assertNull($copia->approved_by);
        $this->assertSame(0, CampaignTarget::query()->where('campaign_id', $copia->id)->count()); // snapshot NAO copiado

        // Original INTACTA (status, agenda, aprovacao, targets).
        $original->refresh();
        $this->assertSame('approved', $original->status);
        $this->assertNotNull($original->start_at);
        $this->assertNotNull($original->approved_at);
        $this->assertSame(2, CampaignTarget::query()->where('campaign_id', $original->id)->count());

        // Copia abre no form de edicao (a "tela de edicao" existente).
        $tela->assertSet('showForm', true)->assertSet('editingId', $copia->id);
    }

    public function test_duplicar_duas_vezes_sufixa_incremental(): void
    {
        $original = $this->campanhaAprovada();

        Livewire::test(Campanhas::class)->call('duplicate', $original->id);
        Livewire::test(Campanhas::class)->call('duplicate', $original->id);

        $this->assertNotNull(ProactiveCampaign::query()->where('name', 'Promo Julho (copia)')->first());
        $this->assertNotNull(ProactiveCampaign::query()->where('name', 'Promo Julho (copia) (2)')->first());
    }

    public function test_duplicar_nao_despacha_nenhum_job(): void
    {
        $original = $this->campanhaAprovada();
        Queue::fake();

        Livewire::test(Campanhas::class)->call('duplicate', $original->id);

        Queue::assertNothingPushed(); // duplicar NUNCA envia/agenda
    }

    public function test_posse_e_isolamento_duplicar_de_outra_conta_e_noop(): void
    {
        $b = Account::create(['name' => 'B']);
        $campB = ProactiveCampaign::create([
            'account_id' => $b->id, 'name' => 'Da-B', 'message' => 'x',
            'audience_type' => 'tags', 'audience_config' => ['tag_ids' => [9]], 'status' => 'draft',
        ]);
        $antes = ProactiveCampaign::withoutAccountScope()->count();

        // Contexto = conta A: id forjado da B -> find escopado falha, nada criado.
        Livewire::test(Campanhas::class)->call('duplicate', $campB->id);

        $this->assertSame($antes, ProactiveCampaign::withoutAccountScope()->count());
        $this->assertSame(1, ProactiveCampaign::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(0, ProactiveCampaign::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }
}
