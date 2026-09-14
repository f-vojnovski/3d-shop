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
            ->assertJsonPath('user.name', 'Filip');

        $this->assertAuthenticated();
    }

    /**
     * A token in the body ends up in storage, which is where it was.
     */
    public function test_signing_in_hands_back_no_token(): void
    {
        $body = $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => 'password123'])
            ->assertSuccessful()
            ->json();

        $this->assertSame(['user'], array_keys($body));
        $this->assertArrayNotHasKey('token', $body);
        $this->assertSame(0, $this->user()->tokens()->count());
    }

    /** A session id handed out before signing in must not be the one that ends up signed in. */
    public function test_the_session_id_changes_on_the_way_in(): void
    {
        $this->get('/api/products');
        $before = session()->getId();

        $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => 'password123'])
            ->assertSuccessful();

        $this->assertNotSame($before, session()->getId());
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
        ])->assertStatus(201)->assertJsonStructure(['user' => ['id', 'name']]);

        $this->assertAuthenticated();
    }

    public function test_logging_out_ends_the_session(): void
    {
        $this->postJson('/api/auth/login', ['name' => 'Filip', 'password' => 'password123'])
            ->assertSuccessful();

        $this->assertAuthenticated();

        $this->postJson('/api/auth/logout')->assertSuccessful();

        // The guard resolves once per process, not once per request as it would
        // over HTTP, so it has to be cleared to see the session end.
        $this->app['auth']->forgetGuards();

        $this->assertGuest();
    }

    /**
     * Deliberately not `getJson`. Every other test here sets an Accept header,
     * which is what hid this: without one the auth middleware looked for a
     * `login` route to redirect to, found none, and answered 500.
     */
    public function test_a_missing_credential_is_refused_rather_than_a_server_error(): void
    {
        $response = $this->get('/api/current-user-products');

        $this->assertSame(401, $response->status(), 'A signed-out caller got '.$response->status().'.');
    }

    private function user(): User
    {
        return User::firstWhere('name', 'Filip');
    }
}
