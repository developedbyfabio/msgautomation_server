<?php

namespace Tests\Feature;

use App\Livewire\Campanhas;
use App\Livewire\Conhecimento;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Knowledge;
use App\Models\ProactiveCampaign;
use App\Models\User;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 23 — view-only do operador (Campanhas + Conhecimento): a rota abre
 * (mudanca deliberada vs Fatia 22), mas TODA escrita e rejeitada server-side
 * mesmo forjada (authorizeEditAction). Regras/Fluxos/Variaveis seguem
 * owner-only. Navegacao reagrupada com rotulos de negocio — mesmas ROTAS/URLs.
 */
class ViewOnlyNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private User $owner;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'provider' => 'evolution', 'webhook_token' => 'tok-a', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);

        $this->owner = User::create(['name' => 'Dono', 'email' => 'dono@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->operador = User::create(['name' => 'Sec', 'email' => 'sec@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->operador->accounts()->attach($this->account->id, ['role' => 'operador']);

        app(AccountContext::class)->set($this->account->id);
    }

    private function campanha(string $status = 'draft'): ProactiveCampaign
    {
        return ProactiveCampaign::create([
            'account_id' => $this->account->id, 'name' => 'Camp-X', 'message' => 'Oi!',
            'optout_footer' => 'Responda {palavra_sair} pra sair.',
            'audience_type' => 'tags', 'audience_config' => ['tag_ids' => []], 'status' => $status,
        ]);
    }

    private function kb(): Knowledge
    {
        return Knowledge::create(['account_id' => $this->account->id, 'title' => 'Horario', 'content' => 'Seg-Sex 8h-18h.', 'sensitivity' => 'low', 'active' => true]);
    }

    // ---- Operador VE (rota liberada — mudanca deliberada vs Fatia 22) -----------

    public function test_operador_ve_campanhas_e_conhecimento_em_modo_leitura(): void
    {
        $this->campanha();
        $this->kb();

        // Rota abre e a UI esconde a escrita (cosmetico; o gate e a barreira real).
        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('campanhas'))->assertOk()
            ->assertSee('Camp-X')
            ->assertDontSee('Nova campanha')
            ->assertDontSee('Comecar com um modelo');

        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('conhecimento'))->assertOk()
            ->assertSee('Horario')
            ->assertDontSee('Nova entrada');
    }

    // ---- Operador NAO escreve (forjado = rejeitado server-side) ------------------

    public function test_escrita_forjada_de_campanha_por_operador_e_rejeitada(): void
    {
        $c = $this->campanha('previewed');
        $this->actingAs($this->operador);

        Livewire::test(Campanhas::class)->set('cName', 'Invadida')->call('save')->assertForbidden();
        Livewire::test(Campanhas::class)->call('duplicate', $c->id)->assertForbidden();
        Livewire::test(Campanhas::class)->call('usarTemplate', 'promocao')->assertForbidden();
        Livewire::test(Campanhas::class)->call('openPreview', $c->id)->assertForbidden();
        Livewire::test(Campanhas::class)->set('confirmingApproveId', $c->id)->call('approveConfirmed')->assertForbidden();

        $this->assertSame(1, ProactiveCampaign::withoutAccountScope()->count()); // nada criado
        $this->assertSame('previewed', $c->fresh()->status);                     // nada mudou
    }

    public function test_escrita_forjada_de_conhecimento_por_operador_e_rejeitada(): void
    {
        $k = $this->kb();
        $this->actingAs($this->operador);

        Livewire::test(Conhecimento::class)->set('title', 'X')->set('content', 'Y')->call('save')->assertForbidden();
        Livewire::test(Conhecimento::class)->call('toggle', $k->id)->assertForbidden();
        Livewire::test(Conhecimento::class)->call('usarTemplate', 'horario')->assertForbidden();
        Livewire::test(Conhecimento::class)->set('confirmingDeleteId', $k->id)->call('deleteConfirmed')->assertForbidden();

        $k->refresh();
        $this->assertTrue((bool) $k->active);                                   // toggle nao agiu
        $this->assertSame(1, Knowledge::withoutAccountScope()->count());        // nada criado/excluido
    }

    public function test_owner_mantem_ver_e_editar(): void
    {
        $k = $this->kb();
        $this->actingAs($this->owner);

        Livewire::test(Conhecimento::class)->call('toggle', $k->id); // sem 403
        $this->assertFalse((bool) $k->fresh()->active);

        Livewire::test(Campanhas::class)->call('usarTemplate', 'promocao');
        $this->assertSame(1, ProactiveCampaign::withoutAccountScope()->count());
    }

    public function test_estruturais_seguem_owner_only_para_operador(): void
    {
        foreach (['regras', 'fluxos', 'variaveis'] as $rota) {
            $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
                ->get(route($rota))->assertForbidden();
        }
    }

    // ---- Navegacao: rotulos de negocio, MESMAS rotas ------------------------------

    public function test_urls_nao_mudaram_com_o_reagrupamento(): void
    {
        foreach ([
            'painel' => '/painel', 'conversas' => '/conversas', 'kanban' => '/kanban',
            'contatos' => '/contatos', 'campanhas' => '/campanhas', 'regras' => '/regras',
            'fluxos' => '/fluxos', 'conhecimento' => '/conhecimento', 'variaveis' => '/variaveis',
            'revisao' => '/revisao', 'senhas' => '/senhas', 'logs' => '/logs',
            'configuracoes' => '/configuracoes', 'perfil' => '/perfil',
        ] as $nome => $path) {
            $this->assertSame(url($path), route($nome), "rota {$nome} mudou de URL");
        }
    }

    public function test_menu_reagrupado_owner_ve_automacao_e_admin_ve_empresas(): void
    {
        $resp = $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('kanban'))->assertOk();
        $resp->assertSee('Automacao');               // heading do grupo
        $resp->assertSee('Menus de atendimento');    // rotulo novo (rota /fluxos identica)
        $resp->assertDontSee('>Empresas<', false);   // owner de conta nao ve admin

        $admin = User::create(['name' => 'Root', 'email' => 'root@x.local', 'password' => Hash::make('senha-forte-123')]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $admin->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->actingAs($admin)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('kanban'))->assertOk()->assertSee('Empresas');
    }

    public function test_cabecalho_renomeado_e_telefone_amigavel(): void
    {
        \App\Models\Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541999887766@s.whatsapp.net', 'saved' => true, 'auto_reply_mode' => 'on']);

        $resp = $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('contatos'))->assertOk();
        $resp->assertSee('Clientes');                        // h1 de negocio
        $resp->assertSee('+55 (41) 99988-7766');             // identificador amigavel (view-only)
        $resp->assertDontSee('5541999887766@s.whatsapp.net'); // jid tecnico fora da lista
    }
}
