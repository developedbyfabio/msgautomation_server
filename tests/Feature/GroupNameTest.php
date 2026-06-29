<?php

namespace Tests\Feature;

use App\Jobs\ResolveGroupName;
use App\Livewire\Conversas;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Group;
use App\Models\IncomingMessage;
use App\Whatsapp\Groups\GroupNameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S4 — nome do grupo (cache + job em background). Sem buscar a cada mensagem.
 */
class GroupNameTest extends TestCase
{
    use RefreshDatabase;

    private const GJID = '120363347674888716@g.us';

    public function test_name_for_le_do_cache_db(): void
    {
        $a = Account::create(['name' => 'T']);
        Group::create(['account_id' => $a->id, 'remote_jid' => self::GJID, 'subject' => 'Familia']);

        $this->assertSame('Familia', app(GroupNameResolver::class)->nameFor($a->id, self::GJID));
        $this->assertNull(app(GroupNameResolver::class)->nameFor($a->id, 'outro@g.us'));
    }

    public function test_ensure_dispara_job_uma_vez_e_nao_repete(): void
    {
        Queue::fake();
        $a = Account::create(['name' => 'T']);
        $r = app(GroupNameResolver::class);

        $r->ensure($a->id, self::GJID);
        $r->ensure($a->id, self::GJID); // dedupe (5 min)

        Queue::assertPushed(ResolveGroupName::class, 1);
    }

    public function test_ensure_nao_dispara_se_ja_cacheado(): void
    {
        Queue::fake();
        $a = Account::create(['name' => 'T']);
        Group::create(['account_id' => $a->id, 'remote_jid' => self::GJID, 'subject' => 'Ja tem']);

        app(GroupNameResolver::class)->ensure($a->id, self::GJID);

        Queue::assertNotPushed(ResolveGroupName::class);
    }

    public function test_job_busca_e_grava_subject(): void
    {
        Http::fake(['*group/findGroupInfos*' => Http::response(['subject' => 'Churras do Predio'], 200)]);
        $a = Account::create(['name' => 'T']);

        (new ResolveGroupName($a->id, self::GJID))->handle(app(GroupNameResolver::class));

        $this->assertDatabaseHas('groups', ['remote_jid' => self::GJID, 'subject' => 'Churras do Predio']);
    }

    public function test_atualizar_nome_sob_demanda_rebusca_e_grava(): void
    {
        $a = Account::create(['name' => 'T']);
        Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        // Nome antigo em cache.
        Group::create(['account_id' => $a->id, 'remote_jid' => self::GJID, 'subject' => 'Nome Antigo']);
        // Evolution agora retorna o nome novo.
        Http::fake(['*group/findGroupInfos*' => Http::response(['subject' => 'Nome Novo'], 200)]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::GJID)
            ->call('atualizarNomeGrupo');

        $this->assertSame('Nome Novo', app(GroupNameResolver::class)->nameFor($a->id, self::GJID));
        $this->assertDatabaseHas('groups', ['remote_jid' => self::GJID, 'subject' => 'Nome Novo']);
    }

    public function test_resolve_now_sobrescreve_cache(): void
    {
        Http::fake(['*group/findGroupInfos*' => Http::response(['subject' => 'Atualizado'], 200)]);
        $a = Account::create(['name' => 'T']);
        Group::create(['account_id' => $a->id, 'remote_jid' => self::GJID, 'subject' => 'Velho']);

        $nome = app(GroupNameResolver::class)->resolveNow($a->id, self::GJID);

        $this->assertSame('Atualizado', $nome);
        $this->assertSame(1, Group::where('remote_jid', self::GJID)->count()); // updateOrCreate, nao duplica
    }

    public function test_lista_mostra_nome_do_grupo_em_vez_do_jid(): void
    {
        $a = Account::create(['name' => 'T']);
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        Group::create(['account_id' => $a->id, 'remote_jid' => self::GJID, 'subject' => 'Vizinhanca']);
        IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => 'fabio-pessoal',
            'evolution_message_id' => 'G1', 'remote_jid' => self::GJID, 'from_me' => false,
            'type' => 'conversation', 'text' => 'oi galera', 'raw_payload' => ['x' => 1],
            'received_at' => Carbon::create(2026, 6, 29, 13, 0, 0, 'UTC'),
        ]);

        // Cacheado -> nao dispara HTTP. Mostra o NOME do grupo como rotulo da conversa.
        // (O JID ainda aparece em wire:key/wire:click — atributos, nao o rotulo visivel.)
        Livewire::test(Conversas::class)->assertSee('Vizinhanca');
    }
}
