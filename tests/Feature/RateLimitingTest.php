<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Brute-force protection: after 6 wrong login attempts in a minute,
     * the 7th attempt must be blocked (HTTP 429), even with correct credentials.
     */
    public function test_login_is_rate_limited_after_six_attempts(): void
    {
        $user = User::create([
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'owner',
        ]);

        // 6 wrong attempts should each return 401 (invalid credentials), not 429 yet.
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(401);
        }

        // The 7th attempt (even with the CORRECT password) must be throttled.
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(429);
    }

    /**
     * Prevents scripted mass account creation via /auth/register.
     */
    public function test_register_is_rate_limited_after_six_attempts(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/auth/register', [
                'name' => "Bot User {$i}",
                'email' => "bot{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bot User 7',
            'email' => 'bot7@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(429);
    }
}
