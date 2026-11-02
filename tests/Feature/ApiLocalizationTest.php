<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiLocalizationTest extends TestCase
{
    public function test_api_not_found_message_uses_arabic_header(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/__localization_test_missing')
            ->assertStatus(404)
            ->assertJsonPath('message', 'المورد غير موجود.');
    }

    public function test_api_not_found_message_uses_french_header(): void
    {
        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->getJson('/api/__localization_test_missing')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Ressource introuvable.');
    }

    public function test_api_not_found_message_defaults_to_english(): void
    {
        $this->getJson('/api/__localization_test_missing')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Resource not found.');
    }
    public function test_api_validation_messages_use_the_selected_language(): void
    {
        $this->withHeader('Accept-Language', 'fr')
            ->postJson('/api/login', [])
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
