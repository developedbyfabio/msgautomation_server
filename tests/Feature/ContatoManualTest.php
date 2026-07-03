<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Contatos;
use App\Livewire\Conversas;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 19 — adicionar contato manual (saved=true) + listagem so "meus contatos"
 * (saved=true). Numero canonicalizado com BrWaId (mesma regra do inbound/envio),
 * dedup POR CONTA (adota o auto existente). Auto nunca-nomeado (saved=false)
 * some SO da pagina de Contatos; segue vivo/visivel no resto do sistema.
 */
class ContatoManualTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome = 'A'): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    private function auto(Account $a, string $jid, bool $saved = false, ?string $nome = null): Contact
    {
        return Contact::create([
            'account_id' => $a->id, 'remote_jid' => $jid,
            'auto_reply_mode' => 'default', 'saved' => $saved, 'push_name' => $nome,
        ]);
    }

    public function test_1_numero_inedito_cria_saved_e_aparece(): void
    {
        $a = $this->conta();

        Livewire::test(Contatos::class)
            ->call('openAdd')
            ->set('newName', 'Joao Novo')->set('newNumber', '41 98765-4321')
            ->call('saveNew')
            ->assertSee('Joao Novo');

        $c = Contact::withoutAccountScope()->where('account_id', $a->id)->first();
        $this->assertSame('5541987654321@s.whatsapp.net', $c->remote_jid); // com 9 (BrWaId)
        $this->assertTrue($c->saved);
    }

    public function test_2_numero_ja_auto_nao_duplica_e_vira_saved(): void
    {
        $a = $this->conta();
        $this->auto($a, '5541987654321@s.whatsapp.net', saved: false, nome: 'Push Antigo');

        Livewire::test(Contatos::class)
            ->call('openAdd')
            ->set('newName', 'Nome Manual')->set('newNumber', '(41) 98765-4321')
            ->call('saveNew');

        $todos = Contact::withoutAccountScope()->where('account_id', $a->id)->get();
        $this->assertCount(1, $todos); // NAO duplicou
        $this->assertTrue($todos->first()->saved);
        $this->assertSame('Nome Manual', $todos->first()->push_name); // nome atualizado
    }

    public function test_3_formato_solto_casa_variante_sem_nono_digito(): void
    {
        // Auto gravado SEM o 9 (como a Cloud API pode entregar); usuario digita COM o 9.
        $a = $this->conta();
        $this->auto($a, '554187654321@s.whatsapp.net', saved: false, nome: 'Auto Sem9');

        Livewire::test(Contatos::class)
            ->call('openAdd')
            ->set('newName', 'Manual Com9')->set('newNumber', '+55 41 98765-4321')
            ->call('saveNew');

        $todos = Contact::withoutAccountScope()->where('account_id', $a->id)->get();
        $this->assertCount(1, $todos); // adotou a variante sem-9, nao duplicou
        $this->assertTrue($todos->first()->saved);
        $this->assertSame('554187654321@s.whatsapp.net', $todos->first()->remote_jid);
    }

    public function test_4_auto_nunca_nomeado_nao_aparece_na_listagem(): void
    {
        $a = $this->conta();
        $this->auto($a, '5541900000001@s.whatsapp.net', saved: false, nome: 'AutoInvisivel');

        Livewire::test(Contatos::class)->assertDontSee('AutoInvisivel');
    }

    public function test_5_auto_nomeado_aparece_na_listagem(): void
    {
        $a = $this->conta();
        $this->auto($a, '5541900000002@s.whatsapp.net', saved: true, nome: 'AutoNomeado');

        Livewire::test(Contatos::class)->assertSee('AutoNomeado');
    }

    public function test_6_gate_saved_false_visivel_fora_da_pagina_de_contatos(): void
    {
        // Contato auto (saved=false) com mensagem recebida DEVE aparecer nas Conversas.
        $a = $this->conta();
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'connected']);
        $jid = '5541900000003@s.whatsapp.net';
        $this->auto($a, $jid, saved: false, nome: 'ContatoAuto');
        \App\Models\IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => 'fabio-pessoal',
            'evolution_message_id' => 'MSG6', 'remote_jid' => $jid, 'from_me' => false,
            'type' => 'conversation', 'text' => 'oi', 'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);

        // Nao aparece em Contatos (saved=false)...
        Livewire::test(Contatos::class)->assertDontSee('ContatoAuto');
        // ...mas aparece nas Conversas (segue vivo/visivel no resto do sistema).
        Livewire::test(Conversas::class)->assertSee('ContatoAuto');
    }

    public function test_7_ciclo_manual_depois_mensagem_um_unico_contato(): void
    {
        $a = $this->conta();
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'connected']);

        // 1) cria manual
        Livewire::test(Contatos::class)
            ->call('openAdd')->set('newName', 'Fulano')->set('newNumber', '41 98765-4321')->call('saveNew');

        // 2) recebe mensagem desse numero (com 9, como Evolution entrega)
        ProcessIncomingWhatsappMessage::dispatchSync([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal', 'data' => [
                'key' => ['id' => 'MSG7', 'remoteJid' => '5541987654321@s.whatsapp.net', 'fromMe' => false],
                'messageType' => 'conversation', 'message' => ['conversation' => 'ola'],
                'messageTimestamp' => now()->timestamp,
            ],
        ], $c->id);

        $todos = Contact::withoutAccountScope()->where('account_id', $a->id)
            ->where('remote_jid', '5541987654321@s.whatsapp.net')->get();
        $this->assertCount(1, $todos); // UM unico contato (o manual, agora com a msg)
        $this->assertTrue($todos->first()->saved);
    }

    public function test_8_dedup_respeita_escopo_da_conta(): void
    {
        $mesmoJid = '5541987654321@s.whatsapp.net';
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        // Conta A ja tem o numero (auto); conta B nao.
        Contact::create(['account_id' => $a->id, 'remote_jid' => $mesmoJid, 'auto_reply_mode' => 'default', 'saved' => false]);

        // Adiciona o MESMO numero na conta B.
        app(AccountContext::class)->set($b->id);
        Livewire::test(Contatos::class)
            ->call('openAdd')->set('newName', 'B Contato')->set('newNumber', '41 98765-4321')->call('saveNew');

        // B ganhou o SEU contato (novo), A intacto — cada conta com o seu.
        $this->assertSame(1, Contact::withoutAccountScope()->where('account_id', $b->id)->where('remote_jid', $mesmoJid)->count());
        $this->assertSame(1, Contact::withoutAccountScope()->where('account_id', $a->id)->where('remote_jid', $mesmoJid)->count());
        $this->assertFalse(Contact::withoutAccountScope()->where('account_id', $a->id)->first()->saved); // A nao foi tocado
        $this->assertTrue(Contact::withoutAccountScope()->where('account_id', $b->id)->first()->saved);
    }
}
