<?php

namespace Tests\Feature;

use App\Livewire\ContactTags;
use App\Livewire\Contatos;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Tag;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 12 — tags STANDALONE: criar/renomear/excluir pelo modal "Gerenciar tags"
 * (botao Tags em /contatos), sem passar pela atribuicao a um contato. A criacao
 * inline no painel do contato PERMANECE como atalho. Unicidade por conta segue o
 * padrao unico ja existente (LOWER(name), case-insensitive; no MySQL de producao
 * a collation ci tambem casa sem acento — sqlite dos testes nao prova acento).
 * Posse server-side em toda acao por id; exclusao desanexa SO o pivo da tag.
 */
class TagsStandaloneTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    private function contato(string $jid, ?int $accountId = null): Contact
    {
        return Contact::create(['account_id' => $accountId ?? $this->account->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'on']);
    }

    public function test_criar_standalone_persiste_sem_contato_e_fica_disponivel_na_atribuicao(): void
    {
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->set('newTagName', 'VIP')
            ->set('newTagColor', 'indigo')
            ->call('createTag')
            ->assertHasNoErrors();

        // Persistiu na conta ativa, com a cor, e SEM nenhum pivo (nasce solta).
        $tag = Tag::withoutAccountScope()->where('name', 'VIP')->first();
        $this->assertNotNull($tag);
        $this->assertSame($this->account->id, (int) $tag->account_id);
        $this->assertSame('indigo', $tag->color);
        $this->assertSame(0, DB::table('contact_tag')->count());

        // Disponivel na ATRIBUICAO: o addTag do painel REUSA a mesma tag (ate com
        // outra caixa) em vez de criar duplicata; origem manual rastreada no pivo.
        $c = $this->contato('5541999990001@s.whatsapp.net');
        Livewire::test(ContactTags::class, ['contactId' => $c->id])
            ->set('tagInput', 'vip')
            ->call('addTag');

        $this->assertSame(1, Tag::withoutAccountScope()->count()); // nenhuma tag nova
        $this->assertDatabaseHas('contact_tag', ['contact_id' => $c->id, 'tag_id' => $tag->id, 'origin' => 'manual']);
    }

    public function test_unicidade_case_insensitive_na_conta_e_mesma_string_em_outra_conta_permitida(): void
    {
        Tag::create(['name' => 'Cliente-Vip', 'color' => 'zinc']); // conta A (escopo preenche)

        // Duplicata (case diferente) na MESMA conta: rejeitada, nada criado.
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->set('newTagName', 'cliente-vip')
            ->call('createTag')
            ->assertHasErrors('newTagName');
        $this->assertSame(1, Tag::withoutAccountScope()->count());

        // MESMA string em OUTRA conta: permitida (unicidade e por conta).
        $b = Account::create(['name' => 'B']);
        app(AccountContext::class)->set($b->id);
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->set('newTagName', 'Cliente-Vip')
            ->call('createTag')
            ->assertHasNoErrors();

        $this->assertSame(1, Tag::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(2, Tag::withoutAccountScope()->count());
    }

    public function test_renomear_reflete_nos_contatos_e_duplicata_e_rejeitada(): void
    {
        $tag = Tag::create(['name' => 'antiga', 'color' => 'zinc']);
        Tag::create(['name' => 'reservada', 'color' => 'zinc']);
        $c = $this->contato('5541999990002@s.whatsapp.net');
        $c->tags()->attach($tag->id, ['origin' => 'manual']);

        // Renomear: reflete em quem ja tem a tag (mesma linha).
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->set('tagNames.' . $tag->id, 'nova')
            ->call('saveTags')
            ->assertHasNoErrors();
        $this->assertSame('nova', $c->tags()->first()->name);

        // Renomear pra nome de OUTRA tag da conta: rejeitado, nada muda.
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->set('tagNames.' . $tag->id, 'RESERVADA')
            ->call('saveTags')
            ->assertHasErrors('tagNames.' . $tag->id);
        $this->assertSame('nova', $tag->fresh()->name);
    }

    public function test_excluir_desanexa_so_o_pivo_daquela_tag_e_contatos_ficam_intactos(): void
    {
        $some = Tag::create(['name' => 'some', 'color' => 'zinc']);
        $fica = Tag::create(['name' => 'fica', 'color' => 'zinc']);
        $c1 = $this->contato('5541999990003@s.whatsapp.net');
        $c2 = $this->contato('5541999990004@s.whatsapp.net');
        $c1->tags()->attach($some->id, ['origin' => 'manual']);
        $c2->tags()->attach($some->id, ['origin' => 'manual']);
        $c1->tags()->attach($fica->id, ['origin' => 'manual']);

        // Confirmacao mostra o USO ("2 contato(s)") antes de excluir.
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->call('confirmDeleteTag', $some->id)
            ->assertSee('2 contato(s)')
            ->call('deleteTagConfirmed');

        // A tag sumiu e o pivo DELA foi limpo; a outra tag e seus pivos, intactos.
        $this->assertDatabaseMissing('tags', ['id' => $some->id]);
        $this->assertSame(0, DB::table('contact_tag')->where('tag_id', $some->id)->count());
        $this->assertDatabaseHas('contact_tag', ['contact_id' => $c1->id, 'tag_id' => $fica->id]);
        // Contatos em si permanecem.
        $this->assertDatabaseHas('contacts', ['id' => $c1->id]);
        $this->assertDatabaseHas('contacts', ['id' => $c2->id]);
    }

    public function test_excluir_tag_sem_uso_funciona_com_confirmacao_simples(): void
    {
        $tag = Tag::create(['name' => 'orfa', 'color' => 'zinc']);

        Livewire::test(Contatos::class)
            ->call('openTags')
            ->call('confirmDeleteTag', $tag->id)
            ->assertSee('0 contato(s)')
            ->call('deleteTagConfirmed');

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_posse_renomear_ou_excluir_tag_de_outra_conta_e_noop(): void
    {
        $b = Account::create(['name' => 'B']);
        $tagB = Tag::create(['account_id' => $b->id, 'name' => 'da-b', 'color' => 'zinc']);

        // Contexto = conta A. Excluir por id forjado da B: no-op (escopo por conta).
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->call('confirmDeleteTag', $tagB->id)
            ->call('deleteTagConfirmed');
        $this->assertDatabaseHas('tags', ['id' => $tagB->id, 'name' => 'da-b']);

        // Renomear com chave forjada no mapa: saveTags so itera tags DA CONTA — ignorado.
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->set('tagNames.' . $tagB->id, 'invadida')
            ->call('saveTags');
        $this->assertSame('da-b', $tagB->fresh()->name);
    }

    public function test_lista_do_modal_mostra_contagem_de_uso(): void
    {
        $tag = Tag::create(['name' => 'popular', 'color' => 'zinc']);
        $this->contato('5541999990005@s.whatsapp.net')->tags()->attach($tag->id, ['origin' => 'manual']);
        $this->contato('5541999990006@s.whatsapp.net')->tags()->attach($tag->id, ['origin' => 'manual']);

        Livewire::test(Contatos::class)
            ->call('openTags')
            ->assertSee('popular')
            ->assertSee('2 contato(s)');
    }
}
