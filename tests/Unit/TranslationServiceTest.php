<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.translation.url' => 'https://api.mymemory.translated.net/get',
            'services.translation.contact_email' => null,
        ]);
        Cache::flush();
    }

    public function test_it_detects_the_source_language_for_each_field_independently(): void
    {
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::sequence()
                ->push(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Chicken tagine', 'detectedLanguage' => 'ar']])
                ->push(['responseStatus' => '200', 'responseData' => ['translatedText' => 'Cooked with olives and lemon', 'detectedLanguage' => 'fr']]),
        ]);

        $result = app(TranslationService::class)->translateFields([
            'name' => 'طاجين الدجاج',
            'description' => 'Cuit avec des olives et du citron',
        ], 'en');

        $this->assertSame('Chicken tagine', $result['name']);
        $this->assertSame('Cooked with olives and lemon', $result['description']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) =>
            $request['langpair'] === 'autodetect|en' && $request['mt'] === '1' && is_string($request['q'])
        );
    }

    public function test_it_keeps_text_unchanged_when_detected_language_matches_target(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'Un texte différent', 'detectedLanguage' => 'fr'],
        ])]);

        $source = ['name' => 'Bonjour le restaurant', 'description' => ''];
        $this->assertSame($source, app(TranslationService::class)->translateFields($source, 'fr'));
        Http::assertSentCount(1);
    }

    public function test_it_skips_empty_fields_without_calling_the_provider(): void
    {
        Http::fake();
        $source = ['name' => '   ', 'description' => ''];
        $this->assertSame($source, app(TranslationService::class)->translateFields($source, 'fr'));
        Http::assertNothingSent();
    }

    public function test_it_keeps_original_text_when_provider_returns_an_api_error(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::response([
            'responseStatus' => '403',
            'responseDetails' => 'Quota exceeded',
            'responseData' => ['translatedText' => 'QUOTA EXCEEDED'],
        ])]);

        $source = ['name' => 'طاجين الدجاج', 'description' => ''];
        $this->assertSame($source, app(TranslationService::class)->translateFields($source, 'fr'));
        Http::assertSentCount(1);
    }

    public function test_it_does_not_call_provider_when_no_url_is_configured(): void
    {
        config(['services.translation.url' => null]);
        Http::fake();
        $source = ['name' => 'Chicken Tagine', 'description' => ''];
        $this->assertSame($source, app(TranslationService::class)->translateFields($source, 'ar'));
        Http::assertNothingSent();
    }

    public function test_it_rejects_unsupported_target_languages(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(TranslationService::class)->translateFields(['name' => 'Meal'], 'es');
    }

    public function test_it_splits_long_text_into_requests_below_the_provider_byte_limit(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'translated segment', 'detectedLanguage' => 'en'],
        ])]);

        $text = str_repeat('A sentence with enough words to split safely. ', 20);
        app(TranslationService::class)->translateFields(['description' => $text], 'fr');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => strlen((string) $request['q']) <= 450);
    }

    public function test_it_caches_successful_translations_between_requests(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'Chicken tagine', 'detectedLanguage' => 'ar'],
        ])]);

        $translator = app(TranslationService::class);
        $source = ['name' => 'طبق اختبار التخزين المؤقت 2026', 'description' => ''];
        $first = $translator->translateFields($source, 'en');
        $second = $translator->translateFields($source, 'en');

        $this->assertSame('Chicken tagine', $first['name']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_it_temporarily_caches_original_text_when_provider_is_unavailable(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::response([
            'responseStatus' => '403',
            'responseData' => ['translatedText' => 'ERROR'],
        ])]);

        $translator = app(TranslationService::class);
        $source = ['name' => 'نص فريد لاختبار الفشل 2026', 'description' => ''];
        $first = $translator->translateFields($source, 'fr');
        $second = $translator->translateFields($source, 'fr');

        $this->assertSame($source, $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_it_uses_libretranslate_post_payload_and_detected_language(): void
    {
        config([
            'services.translation.provider' => 'libretranslate',
            'services.translation.url' => 'http://translation.internal:5000/translate',
            'services.translation.key' => 'test-secret',
        ]);
        Http::fake(fn () => Http::response([
            'translatedText' => 'Chicken tagine',
            'detectedLanguage' => ['confidence' => 0.99, 'language' => 'ar'],
        ]));

        $result = app(TranslationService::class)->translateFields([
            'name' => 'طاجين الدجاج',
            'description' => '',
        ], 'en');

        $this->assertSame('Chicken tagine', $result['name']);
        Http::assertSent(fn (Request $request) =>
            $request->method() === 'POST'
            && $request->url() === 'http://translation.internal:5000/translate'
            && $request['q'] === 'طاجين الدجاج'
            && $request['source'] === 'auto'
            && $request['target'] === 'en'
            && $request['format'] === 'text'
            && $request['api_key'] === 'test-secret'
        );
    }

    public function test_it_uses_libretranslate_detected_language_to_preserve_same_language_text(): void
    {
        config([
            'services.translation.provider' => 'libretranslate',
            'services.translation.url' => 'http://translation.internal:5000/translate',
            'services.translation.key' => null,
        ]);
        Http::fake(fn () => Http::response([
            'translatedText' => 'Different wording',
            'detectedLanguage' => ['confidence' => 0.99, 'language' => 'fr'],
        ]));

        $source = ['name' => 'Bonjour le restaurant', 'description' => ''];
        $this->assertSame($source, app(TranslationService::class)->translateFields($source, 'fr'));
    }
}
