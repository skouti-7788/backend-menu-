<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

    public function test_it_keeps_text_unchanged_when_detected_language_matches_target(): void
    {
        config(['services.translation.url' => 'http://translation.test/translate']);
        config(['services.translation.key' => null]);

        Http::fake([
            'translation.test/translate' => Http::response([
                'translatedText' => 'Un texte qui ne devrait pas remplacer la source',
                'detectedLanguage' => ['language' => 'fr'],
            ]),
        ]);

        $source = ['name' => 'Bonjour le restaurant', 'description' => ''];
        $result = app(TranslationService::class)->translateFields($source, 'fr');

        $this->assertSame($source, $result);
        Http::assertSentCount(1);
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

    public function test_it_caches_successful_translations_between_requests(): void
    {
        Cache::flush();
        config(['services.translation.url' => 'http://translation.test/translate']);
        Http::fake([
            'translation.test/translate' => Http::response([
                'translatedText' => 'Chicken tagine',
                'detectedLanguage' => ['language' => 'ar'],
            ]),
        ]);

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
        Cache::flush();
        config(['services.translation.url' => 'http://translation.test/translate']);
        Http::fake([
            'translation.test/translate' => Http::response(['message' => 'Unavailable'], 503),
        ]);

        $translator = app(TranslationService::class);
        $source = ['name' => 'نص فريد لاختبار الفشل 2026', 'description' => ''];

        $first = $translator->translateFields($source, 'fr');
        $second = $translator->translateFields($source, 'fr');

        $this->assertSame($source, $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_successful_translations_are_cached_for_thirty_days(): void
    {
        Cache::flush();
        config(['services.translation.url' => 'http://translation.test/translate']);
        Http::fake([
            'translation.test/translate' => Http::response([
                'translatedText' => 'Chicken tagine',
                'detectedLanguage' => ['language' => 'ar'],
            ]),
        ]);

        $source = ['name' => 'طبق مدة التخزين 2026', 'description' => ''];
        $translator = app(TranslationService::class);

        $translator->translateFields($source, 'en');

        $cacheKey = 'menu_translation:'.hash('sha256', implode('|', [
            'http://translation.test/translate',
            'en',
            $source['name'],
        ]));

        $this->assertSame('Chicken tagine', Cache::get($cacheKey));

        // The translation remains cached before its 30-day TTL expires.
        $this->travel(29)->days();
        $this->assertSame('Chicken tagine', Cache::get($cacheKey));

        // After expiry, the next request must call the provider again.
        $translator->translateFields($source, 'en');
        Http::assertSentCount(1);

        $this->travel(2)->days();
        $this->assertNull(Cache::get($cacheKey));

        $translator->translateFields($source, 'en');
        Http::assertSentCount(2);
    }
}
