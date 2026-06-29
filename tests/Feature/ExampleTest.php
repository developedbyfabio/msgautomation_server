<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // S2: '/' fica atras de auth. Guest -> login; autenticado -> conversas.
        $this->get('/')->assertRedirect(route('login'));

        $this->actingAs(\App\Models\User::factory()->create());
        $this->get('/')->assertRedirect('/conversas');
    }
}
