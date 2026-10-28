<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_with_the_chosen_password(): void
    {
        $this->artisan('obs:admin', ['email' => 'Ops@Example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password (leave empty to generate one)', 'correct-horse-battery')
            ->assertSuccessful();

        $user = User::query()->where('email', 'ops@example.com')->sole();
        $this->assertSame('Ops', $user->name);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    public function test_it_resets_an_existing_users_password(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->artisan('obs:admin', ['email' => 'ops@example.com'])
            ->expectsQuestion('Password (leave empty to generate one)', 'a-brand-new-passphrase')
            ->expectsOutputToContain('Updated dashboard user')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->fresh()->password));
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('obs:admin', ['email' => 'ops@example.com'])
            ->expectsQuestion('Password (leave empty to generate one)', 'short')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('obs:admin', ['email' => 'not-an-email'])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_an_empty_password_generates_one_that_signs_in(): void
    {
        $this->artisan('obs:admin', ['email' => 'ops@example.com'])
            ->expectsQuestion('Password (leave empty to generate one)', '')
            ->expectsOutputToContain('Password')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('ops', $user->name);
        $this->assertFalse(Hash::check('', $user->password));
    }
}
