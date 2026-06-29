<?php

namespace Database\Seeders;

use App\Models\Account;
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
    }
}
