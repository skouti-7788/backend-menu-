<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use Illuminate\Http\Client\ConnectionException;
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
        Http::assertSent(fn (Request $request) => $request['langpair'] === 'autodetect|en' && $request['mt'] === '1' && is_string($request['q'])
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
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
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

    public function test_it_supports_all_six_english_french_and_arabic_directions(): void
    {
        $directions = [
            ['ar', 'en'],
            ['ar', 'fr'],
            ['en', 'ar'],
            ['en', 'fr'],
            ['fr', 'ar'],
            ['fr', 'en'],
        ];
        $sequence = Http::sequence();
        foreach ($directions as [$source, $target]) {
            $sequence->push([
                'responseStatus' => 200,
                'responseData' => [
                    'translatedText' => 'translated to '.$target,
                    'detectedLanguage' => $source,
                ],
            ]);
        }
        Http::fake(['api.mymemory.translated.net/get*' => $sequence]);

        foreach ($directions as $index => [$source, $target]) {
            $result = app(TranslationService::class)->translateFields([
                'name' => 'direction-specific text '.$index,
            ], $target);

            $this->assertSame('translated to '.$target, $result['name']);
        }

        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request) => in_array(
            $request['langpair'],
            ['autodetect|en', 'autodetect|fr', 'autodetect|ar'],
            true
        ));
    }

    public function test_it_preserves_whitespace_null_numeric_and_non_linguistic_values(): void
    {
        Http::fake();
        $source = [
            'whitespace' => " \t ",
            'null' => null,
            'quantity' => 42,
            'text' => '   ',
        ];

        $this->assertSame(
            $source,
            app(TranslationService::class)->translateFields($source, 'ar')
        );
        Http::assertNothingSent();
    }

    public function test_it_caches_separately_by_entity_and_field(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::sequence()
            ->push([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => 'Restaurant name', 'detectedLanguage' => 'en'],
            ])
            ->push([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => 'Meal name', 'detectedLanguage' => 'en'],
            ])]);

        $translator = app(TranslationService::class);
        $restaurant = $translator->translateFields(['name' => 'Shared label'], 'fr', 'restaurants:1');
        $meal = $translator->translateFields(['name' => 'Shared label'], 'fr', 'meals:1');
        $cached = $translator->translateFields(['name' => 'Shared label'], 'fr', 'restaurants:1');

        $this->assertSame('Restaurant name', $restaurant['name']);
        $this->assertSame('Meal name', $meal['name']);
        $this->assertSame($restaurant, $cached);
        Http::assertSentCount(2);
    }

    public function test_successful_translation_cache_expires_after_thirty_days(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::sequence()
            ->push([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => 'First translation', 'detectedLanguage' => 'en'],
            ])
            ->push([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => 'Second translation', 'detectedLanguage' => 'en'],
            ])]);

        $translator = app(TranslationService::class);
        $source = ['name' => 'Cache expiry fixture'];
        $this->assertSame('First translation', $translator->translateFields($source, 'fr')['name']);

        $this->travel(31)->days();
        $this->assertSame('Second translation', $translator->translateFields($source, 'fr')['name']);

        Http::assertSentCount(2);
        $this->travelBack();
    }

    public function test_invalid_json_and_empty_provider_responses_fall_back_to_original_text(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::sequence()
            ->push('not-json', 200)
            ->push([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => '  ', 'detectedLanguage' => 'ar'],
            ])]);

        $translator = app(TranslationService::class);
        $this->assertSame(
            ['name' => 'Invalid JSON fixture'],
            $translator->translateFields(['name' => 'Invalid JSON fixture'], 'fr')
        );
        $this->assertSame(
            ['name' => 'Empty response fixture'],
            $translator->translateFields(['name' => 'Empty response fixture'], 'ar')
        );
    }

    public function test_transient_http_errors_are_retried_but_provider_failure_is_cached(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::sequence()
            ->push(['responseStatus' => '503'], 503)
            ->push(['responseStatus' => '503'], 503)
            ->push([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => 'Retried translation', 'detectedLanguage' => 'en'],
            ])
            ->push(['responseStatus' => '403'], 200)]);

        $translator = app(TranslationService::class);
        $this->assertSame(
            ['name' => 'Retried translation'],
            $translator->translateFields(['name' => 'Retry fixture'], 'fr')
        );
        $failure = ['name' => 'Provider failure fixture'];
        $this->assertSame($failure, $translator->translateFields($failure, 'fr'));
        $this->assertSame($failure, $translator->translateFields($failure, 'fr'));

        Http::assertSentCount(4);
    }

    public function test_connection_timeouts_return_original_text_and_are_cached(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Connection timed out.');
        });

        $translator = app(TranslationService::class);
        $source = ['name' => 'Timeout fixture'];

        $this->assertSame($source, $translator->translateFields($source, 'ar'));
        $this->assertSame($source, $translator->translateFields($source, 'ar'));
        $this->assertSame(3, $attempts);
    }

    public function test_failed_translation_cache_expires_after_five_minutes(): void
    {
        Http::fake(['api.mymemory.translated.net/get*' => Http::sequence()
            ->push(['responseStatus' => '403'], 200)
            ->push([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => 'Recovered translation', 'detectedLanguage' => 'en'],
            ])]);

        $translator = app(TranslationService::class);
        $source = ['name' => 'Failure cache expiry fixture'];

        $this->assertSame($source, $translator->translateFields($source, 'fr'));
        $this->assertSame($source, $translator->translateFields($source, 'fr'));
        Http::assertSentCount(1);

        $this->travel(6)->minutes();
        $this->assertSame(
            ['name' => 'Recovered translation'],
            $translator->translateFields($source, 'fr')
        );
        Http::assertSentCount(2);
        $this->travelBack();
    }

    public function test_arabic_diacritics_mixed_language_text_and_symbols_are_sent_unchanged(): void
    {
        $text = 'مَرْحَبًا café! — 42 #🍽️';
        Http::fake(['api.mymemory.translated.net/get*' => Http::response([
            'responseStatus' => 200,
            'responseData' => [
                'translatedText' => 'Hello cafe! — 42 #🍽️',
                'detectedLanguage' => 'ar',
            ],
        ])]);

        $result = app(TranslationService::class)->translateFields(['description' => $text], 'en');

        $this->assertSame('Hello cafe! — 42 #🍽️', $result['description']);
        Http::assertSent(fn (Request $request) => $request['q'] === $text);
    }
}
