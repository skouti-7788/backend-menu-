<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_creates_an_owner_with_a_restaurant_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alice Owner',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'role', 'restaurant_id'],
            'token',
        ]);
        $response->assertJsonPath('user.role', 'owner');

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'role' => 'owner',
        ]);

        // Password must never be stored in plain text.
        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotEquals('password123', $user->password);

        $this->assertDatabaseHas('restaurants', [
            'user_id' => $user->id,
        ]);
    }

    public function test_cannot_register_twice_with_the_same_email(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'taken@example.com',
            'password' => 'whatever123',
            'role' => 'owner',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Guy',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'correct-password',
            'role' => 'owner',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::create([
            'name' => 'Bob',
            'email' => 'bob2@example.com',
            'password' => 'correct-password',
            'role' => 'owner',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_registration_forces_owner_role_even_if_client_sends_a_different_role(): void
    {
        // A malicious client tries to self-promote to admin at registration.
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.role', 'owner');
    }

    public function test_login_fails_with_unknown_email_without_revealing_account_existence(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'does-not-exist@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Invalid credentials.',
        ]);
    }

    public function test_registration_validates_optional_restaurant_contact_fields(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Invalid Contact',
            'email' => 'invalid-contact@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'address' => ['invalid'],
            'phone' => ['invalid'],
        ]);

        $response->assertStatus(422);

        $response->assertJsonValidationErrors([
            'address',
            'phone',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'invalid-contact@example.com',
        ]);
    }

    public function test_unauthenticated_user_cannot_access_user_endpoint(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_valid_token_can_access_user_endpoint(): void
    {
        $user = User::create([
            'name' => 'Token User',
            'email' => 'token-user@example.com',
            'password' => 'password123',
            'role' => 'owner',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/user');

        $response->assertOk();

        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.email', $user->email);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::create([
            'name' => 'Logout User',
            'email' => 'logout@example.com',
            'password' => 'password123',
            'role' => 'owner',
        ]);

        $token = $user->createToken('logout-test')->plainTextToken;

        $logoutResponse = $this
            ->withToken($token)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $response = $this
            ->withToken($token)
            ->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_new_login_revokes_previous_tokens(): void
    {
        $user = User::create([
            'name' => 'Rotation User',
            'email' => 'rotation@example.com',
            'password' => 'password123',
            'role' => 'owner',
        ]);

        $oldToken = $user->createToken('old-token')->plainTextToken;

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $loginResponse->assertOk();

        $newToken = $loginResponse->json('token');

        $this->assertNotEmpty($newToken);
        $this->assertNotSame($oldToken, $newToken);

        $oldTokenResponse = $this
            ->withToken($oldToken)
            ->getJson('/api/user');

        $oldTokenResponse->assertStatus(401);

        $newTokenResponse = $this
            ->withToken($newToken)
            ->getJson('/api/user');

        $newTokenResponse->assertOk();
        $newTokenResponse->assertJsonPath('user.id', $user->id);
    }
}   