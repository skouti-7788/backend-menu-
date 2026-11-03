<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    public function test_it_detects_the_source_language_for_each_field_independently(): void
    {
        config(['services.translation.url' => 'http://translation.test/translate']);
        config(['services.translation.key' => null]);

        Http::fake([
            'translation.test/translate' => Http::sequence()
                ->push([
                    'translatedText' => 'Chicken tagine',
                    'detectedLanguage' => ['language' => 'ar'],
                ])
                ->push([
                    'translatedText' => 'Cooked with olives and lemon',
                    'detectedLanguage' => ['language' => 'fr'],
                ]),
        ]);

        $result = app(TranslationService::class)->translateFields([
            'name' => 'طاجين الدجاج',
            'description' => 'Cuit avec des olives et du citron',
        ], 'en');

        $this->assertSame('Chicken tagine', $result['name']);
        $this->assertSame('Cooked with olives and lemon', $result['description']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) =>
            $request['source'] === 'auto'
            && $request['target'] === 'en'
            && is_string($request['q'])
        );
    }

    public function test_it_keeps_the_original_text_when_the_provider_fails(): void
    {
        config(['services.translation.url' => 'http://translation.test/translate']);
        Http::fake([
            'translation.test/translate' => Http::response(['message' => 'Unavailable'], 503),
        ]);

        $source = ['name' => 'طاجين الدجاج', 'description' => ''];
        $result = app(TranslationService::class)->translateFields($source, 'fr');

        $this->assertSame($source, $result);
        Http::assertSentCount(1);
    }

    public function test_it_does_not_call_the_provider_when_no_url_is_configured(): void
    {
        config(['services.translation.url' => null]);
        Http::fake();

        $source = ['name' => 'Chicken Tagine', 'description' => ''];
        $result = app(TranslationService::class)->translateFields($source, 'ar');

        $this->assertSame($source, $result);
        Http::assertNothingSent();
    }

    public function test_it_rejects_unsupported_target_languages(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(TranslationService::class)->translateFields(['name' => 'Meal'], 'es');
    }
}
