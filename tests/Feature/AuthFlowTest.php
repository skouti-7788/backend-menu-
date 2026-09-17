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
        $response->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'restaurant_id'], 'token']);
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
}