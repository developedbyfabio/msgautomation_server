<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Conta-ancora (single-user na Camada 1, multi-tenant em mente).
        $account = Account::firstOrCreate(['name' => config('app.name', 'msgautomation')]);

        // Canal correspondente a instancia da Evolution.
        Channel::firstOrCreate(
            ['instance' => config('services.evolution.instance', 'fabio-pessoal')],
            ['account_id' => $account->id, 'status' => 'disconnected'],
        );

        // Settings do autoresponder com os defaults aprovados (kill switch OFF).
        // Os defaults das colunas cobrem o resto; criamos a linha-ancora por account.
        AutoReplySetting::firstOrCreate(['account_id' => $account->id]);

        // S2 — usuario unico da UI (credenciais no .env).
        $this->call(SingleUserSeeder::class);
    }
}
