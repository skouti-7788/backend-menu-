<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealTranslation;
use App\Models\MenuCategory;
use App\Models\MenuCategoryTranslation;
use App\Models\Restaurant;
use App\Models\RestaurantTranslation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class TranslationService
{
    private const LANGUAGES = ['en', 'fr', 'ar'];

    public function translateRestaurant(Restaurant $restaurant, string $language): RestaurantTranslation
    {
        return $this->translateEntity($restaurant, [
            'name' => $restaurant->name,
            'description' => $restaurant->description ?? '',
        ], $language);
    }

    public function translateCategory(MenuCategory $category, string $language): MenuCategoryTranslation
    {
        return $this->translateEntity($category, [
            'name' => $category->name,
            'description' => $category->description ?? '',
        ], $language);
    }

    public function translateMeal(Meal $meal, string $language): MealTranslation
    {
        return $this->translateEntity($meal, [
            'name' => $meal->name,
            'description' => $meal->description ?? '',
        ], $language);
    }

    public function translateFields(array $fields, string $targetLanguage, string $cacheScope = 'fields'): array
    {
        return $this->translateFieldsWithStatus($fields, $targetLanguage, $cacheScope)[0];
    }

    public function sourceHash(array $source): string
    {
        return hash('sha256', json_encode(
            $source,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function translateEntity(EloquentModel $entity, array $source, string $language): EloquentModel
    {
        $language = $this->normalizeLanguage($language);
        $sourceHash = $this->sourceHash($source);
        $translations = $entity->translations();
        $translation = $entity->relationLoaded('translations')
            ? $entity->translations->firstWhere('language', $language)
            : $translations->where('language', $language)->first();

        if ($translation && ($translation->is_manual || $translation->source_hash === $sourceHash)) {
            return $translation;
        }

        $cacheScope = $entity->getTable().':'.$entity->getKey();
        [$translated, $allFieldsTranslated] = $this->translateFieldsWithStatus(
            $source,
            $language,
            $cacheScope
        );

        if (! $allFieldsTranslated || ! $entity->exists) {
            return $translations->make([
                'language' => $language,
                ...$translated,
            ]);
        }

        return $this->storeAutomaticTranslation($entity, $language, $translated, $sourceHash);
    }

    private function translateFieldsWithStatus(array $fields, string $targetLanguage, string $cacheScope): array
    {
        $targetLanguage = $this->normalizeLanguage($targetLanguage);
        $provider = strtolower((string) config('services.translation.provider', 'mymemory'));
        $serviceUrl = config('services.translation.url');
        $serviceUrl = is_string($serviceUrl) ? trim($serviceUrl) : '';
        $translated = $fields;
        $allFieldsTranslated = true;

        foreach ($fields as $field => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            [$translated[$field], $succeeded] = $this->translateText(
                $text,
                (string) $field,
                $cacheScope,
                $targetLanguage,
                $provider,
                $serviceUrl
            );
            $allFieldsTranslated = $allFieldsTranslated && $succeeded;
        }

        return [$translated, $allFieldsTranslated];
    }

    private function translateText(
        string $text,
        string $field,
        string $cacheScope,
        string $targetLanguage,
        string $provider,
        string $serviceUrl
    ): array {
        $cacheKey = 'menu_translation:'.hash('sha256', serialize([
            $provider,
            $serviceUrl,
            hash('sha256', (string) config('services.translation.key', '')),
            $cacheScope,
            $field,
            $text,
            'auto',
            $targetLanguage,
        ]));

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['value'], $cached['successful'])) {
            return [$cached['value'], (bool) $cached['successful']];
        }

        $lock = Cache::lock($cacheKey.':lock', 120);
        if (! $lock->get()) {
            $cached = Cache::get($cacheKey);

            return is_array($cached) && isset($cached['value'], $cached['successful'])
                ? [$cached['value'], (bool) $cached['successful']]
                : [$text, false];
        }

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['value'], $cached['successful'])) {
                return [$cached['value'], (bool) $cached['successful']];
            }

            if ($serviceUrl === '') {
                Log::warning('Translation skipped: provider URL is not configured.', [
                    'provider' => $provider,
                    'target_language' => $targetLanguage,
                ]);

                return $this->cacheFailure($cacheKey, $text);
            }

            if (! in_array($provider, ['mymemory', 'libretranslate'], true)) {
                Log::error('Translation skipped: unsupported provider is configured.', [
                    'provider' => $provider,
                    'target_language' => $targetLanguage,
                ]);

                return $this->cacheFailure($cacheKey, $text);
            }

            $parts = $this->splitIntoChunks($text);
            $translatedParts = [];

            foreach ($parts as $part) {
                try {
                    $response = $provider === 'libretranslate'
                        ? $this->requestLibreTranslate($serviceUrl, $part, $targetLanguage)
                        : $this->requestMyMemory($serviceUrl, $part, $targetLanguage);
                } catch (ConnectionException $exception) {
                    Log::warning('Translation provider connection failed.', [
                        'provider' => $provider,
                        'target_language' => $targetLanguage,
                        'exception' => $exception::class,
                    ]);

                    return $this->cacheFailure($cacheKey, $text);
                }

                if ($provider === 'libretranslate') {
                    $result = $response->json('translatedText');
                    $detected = $response->json('detectedLanguage.language');
                } else {
                    $providerStatus = (string) $response->json('responseStatus');
                    $result = $response->json('responseData.translatedText');
                    $detected = $response->json('responseData.detectedLanguage');

                    if ($providerStatus !== '200') {
                        Log::warning('Translation provider returned an API error.', [
                            'provider' => $provider,
                            'target_language' => $targetLanguage,
                            'http_status' => $response->status(),
                            'provider_status' => $providerStatus,
                        ]);

                        return $this->cacheFailure($cacheKey, $text);
                    }
                }

                if (! $response->successful() || ! is_string($result) || trim($result) === '') {
                    Log::warning('Translation provider returned an unsuccessful or empty response.', [
                        'provider' => $provider,
                        'target_language' => $targetLanguage,
                        'http_status' => $response->status(),
                    ]);

                    return $this->cacheFailure($cacheKey, $text);
                }

                $detected = strtolower((string) $detected);
                $detected = str_replace('_', '-', $detected);
                $detected = explode('-', $detected)[0];
                $translatedParts[] = $detected === $targetLanguage ? $part : $result;
            }

            $value = implode(' ', $translatedParts);
            Cache::put($cacheKey, ['value' => $value, 'successful' => true], now()->addDays(30));

            return [$value, true];
        } finally {
            $lock->release();
        }
    }

    private function requestLibreTranslate(string $url, string $text, string $targetLanguage): Response
    {
        $payload = [
            'q' => $text,
            'source' => 'auto',
            'target' => $targetLanguage,
            'format' => 'text',
        ];
        $apiKey = config('services.translation.key');
        if (is_string($apiKey) && trim($apiKey) !== '') {
            $payload['api_key'] = $apiKey;
        }

        return $this->sendWithRetry(fn () => Http::acceptJson()
            ->connectTimeout(2)
            ->timeout(4)
            ->post($url, $payload));
    }

    private function requestMyMemory(string $url, string $text, string $targetLanguage): Response
    {
        $query = [
            'q' => $text,
            'langpair' => 'autodetect|'.$targetLanguage,
            'mt' => '1',
        ];
        $contactEmail = config('services.translation.contact_email');
        if (is_string($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $query['de'] = $contactEmail;
        }

        return $this->sendWithRetry(fn () => Http::acceptJson()
            ->connectTimeout(2)
            ->timeout(4)
            ->get($url, $query));
    }

    private function sendWithRetry(\Closure $request): Response
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }

                usleep(100_000 * $attempt);
                continue;
            }

            $retryableStatus = in_array($response->status(), [408, 425, 429], true)
                || $response->serverError();
            if (! $retryableStatus || $attempt === 3) {
                return $response;
            }

            usleep(100_000 * $attempt);
        }

        throw new \LogicException('Translation request retry loop ended unexpectedly.');
    }

    private function cacheFailure(string $cacheKey, string $text): array
    {
        Cache::put(
            $cacheKey,
            ['value' => $text, 'successful' => false],
            now()->addMinutes(5)
        );

        return [$text, false];
    }

    private function splitIntoChunks(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?؟])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)
            ?: [trim($text)];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if (strlen($sentence) <= 450) {
                $candidate = $current === '' ? $sentence : $current.' '.$sentence;
                if (strlen($candidate) <= 450) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $sentence;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }
            $current = '';
            $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [$sentence];

            foreach ($words as $word) {
                if (strlen($word) > 450) {
                    if ($current !== '') {
                        $chunks[] = $current;
                    }
                    $current = '';
                    while (strlen($word) > 450) {
                        $piece = mb_strcut($word, 0, 450, 'UTF-8');
                        $chunks[] = $piece;
                        $word = mb_substr($word, mb_strlen($piece, 'UTF-8'), null, 'UTF-8');
                    }
                }

                $candidate = $current === '' ? $word : $current.' '.$word;
                if (strlen($candidate) > 450) {
                    if ($current !== '') {
                        $chunks[] = $current;
                    }
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [''] : $chunks;
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        if (! in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('Unsupported translation language.');
        }

        return $language;
    }

    private function storeAutomaticTranslation(
        EloquentModel $entity,
        string $language,
        array $translated,
        string $sourceHash
    ): EloquentModel {
        try {
            return DB::transaction(function () use ($entity, $language, $translated, $sourceHash) {
                $relation = $entity->translations();
                $existing = $relation
                    ->where('language', $language)
                    ->lockForUpdate()
                    ->first();

                if ($existing?->is_manual) {
                    return $existing;
                }

                $attributes = [
                    ...$translated,
                    'is_manual' => false,
                    'source_hash' => $sourceHash,
                ];

                if ($existing) {
                    $existing->fill($attributes)->save();

                    return $existing;
                }

                $translation = $relation->make([
                    'language' => $language,
                    ...$attributes,
                ]);
                $translation->save();

                return $translation;
            });
        } catch (QueryException $exception) {
            $existing = $entity->translations()
                ->where('language', $language)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return DB::transaction(function () use ($existing, $translated, $sourceHash) {
                $translation = $existing->newQuery()
                    ->whereKey($existing->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $translation->is_manual && $translation->source_hash !== $sourceHash) {
                    $translation->fill([
                        ...$translated,
                        'is_manual' => false,
                        'source_hash' => $sourceHash,
                    ])->save();
                }

                return $translation;
            });
        }
    }
}
