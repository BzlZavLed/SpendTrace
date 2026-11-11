<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_ledger_redirects_guests_to_sign_in(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
