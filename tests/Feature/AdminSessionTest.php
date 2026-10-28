<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSessionTest extends TestCase
{
    public function test_me_hands_a_guest_the_current_sessions_csrf_token(): void
    {
        $this->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('user', null)
            ->assertJsonStructure(['csrf_token']);
    }
}
