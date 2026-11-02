<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiLocalizationTest extends TestCase
{
    public function test_api_validation_messages_use_the_selected_language(): void
    {
        $this->withHeader('Accept-Language', 'fr')
            ->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Le champ email est obligatoire.');
    }

    public function test_api_unauthenticated_message_uses_arabic(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('message', 'لم يتم تسجيل الدخول.');
    }

}
