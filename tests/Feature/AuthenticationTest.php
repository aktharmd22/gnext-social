<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in');
    }

    public function test_a_user_can_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'writer@example.test']);

        $this->post('/login', [
            'email' => 'writer@example.test',
            'password' => 'password',
        // The dashboard, not the calendar: signing in must land on the same
        // screen the first sidebar item points at.
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_signing_in_records_the_moment(): void
    {
        $user = User::factory()->create(['email' => 'writer@example.test']);

        $this->assertNull($user->last_login_at);

        $this->post('/login', [
            'email' => 'writer@example.test',
            'password' => 'password',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /**
     * Users are deactivated rather than deleted, so the row survives. This is
     * what actually stops them getting back in.
     */
    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        User::factory()->inactive()->create(['email' => 'gone@example.test']);

        $this->post('/login', [
            'email' => 'gone@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_refused(): void
    {
        User::factory()->create(['email' => 'writer@example.test']);

        $this->post('/login', [
            'email' => 'writer@example.test',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Five attempts per minute per email and IP pair. The sixth is refused by
     * the throttle middleware itself, which answers 429 rather than handing
     * back a validation error.
     */
    public function test_sign_in_attempts_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'writer@example.test']);

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', [
                'email' => 'writer@example.test',
                'password' => 'wrong',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => 'writer@example.test',
            'password' => 'wrong',
        ])->assertStatus(429);

        // Even the correct password is refused while the lockout stands.
        $this->post('/login', [
            'email' => 'writer@example.test',
            'password' => 'password',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    /**
     * This is a closed team tool. Accounts exist because an admin created them.
     */
    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_a_signed_in_user_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get('/calendar')->assertRedirect('/login');
    }
}
