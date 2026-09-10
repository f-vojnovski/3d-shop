<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = 'Those credentials do not match our records.';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');

        User::create([
            'name' => 'Filip',
            'email' => 'filip@example.com',
            'password' => 'password123',
        ]);
    }

    public function test_a_correct_password_signs_the_user_in(): void
    {
        $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => 'password123'])
            ->assertSuccessful()
            ->assertJsonPath('user.name', 'Filip')
            ->assertJsonStructure(['token']);
    }

    /**
     * A different message for an unknown name than for a wrong password tells
     * an attacker which half to keep guessing.
     */
    public function test_an_unknown_name_and_a_wrong_password_are_refused_alike(): void
    {
        $unknown = $this->postJson('/api/auth/login', ['name' => 'nobody', 'password' => 'password123']);
        $wrong = $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => 'not-it']);

        $unknown->assertStatus(401)->assertJsonPath('message', self::REFUSAL);
        $wrong->assertStatus(401)->assertJsonPath('message', self::REFUSAL);
    }

    public function test_the_refusal_names_neither_the_account_nor_the_field(): void
    {
        $body = $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => 'not-it'])
            ->assertStatus(401)
            ->json();

        $this->assertSame(['message'], array_keys($body));
        $this->assertStringNotContainsStringIgnoringCase('password', $body['message']);
        $this->assertStringNotContainsStringIgnoringCase('username', $body['message']);
        $this->assertStringNotContainsStringIgnoringCase('Filip', $body['message']);
    }

    public function test_guessing_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => "guess-{$attempt}"])
                ->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => 'password123'])
            ->assertStatus(429);
    }

    public function test_registering_signs_the_new_user_in(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Newcomer',
            'email' => 'newcomer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201)->assertJsonStructure(['token', 'user' => ['id', 'name']]);
    }

    public function test_logging_out_revokes_only_the_token_that_was_used(): void
    {
        $user = User::firstWhere('name', 'Filip');
        $phone = $user->createToken('phone')->plainTextToken;
        $laptop = $user->createToken('laptop')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$laptop}")
            ->postJson('/api/auth/logout')
            ->assertSuccessful();

        // The guard resolves once per process, not once per request as it would
        // over HTTP, so it has to be cleared to see the revocation.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$laptop}")->getJson('/api/user')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$phone}")->getJson('/api/user')->assertSuccessful();
    }
}
