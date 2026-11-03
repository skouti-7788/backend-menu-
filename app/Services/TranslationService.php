<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealTranslation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TranslationService
{
    private const LANGUAGES = ['en', 'fr', 'ar'];

    /**
     * Return a cached translation or generate one on first request.
     * Manual translations are never replaced by automatic translations.
     */
    public function translateMeal(Meal $meal, string $language): MealTranslation
    {
        $language = strtolower(trim($language));
        $this->validateLanguage($language);

        $source = [
            'name' => $meal->name,
            'description' => $meal->description ?? '',
        ];
        $sourceHash = $this->sourceHash($source);

        // Use the eager-loaded relation when available to avoid an N+1 query.
        $translation = $meal->relationLoaded('translations')
            ? $meal->translations->firstWhere('language', $language)
            : $meal->translations()->where('language', $language)->first();

        if ($translation && ($translation->is_manual || $translation->source_hash === $sourceHash)) {
            return $translation;
        }

        $translated = $this->translateFields($source, $language);

        if ($translated === $source) {
            return new MealTranslation([
                'meal_id' => $meal->id,
                'language' => $language,
                'name' => $source['name'],
                'description' => $source['description'],
            ]);
        }

        return $this->createOrGetTranslation(
            $meal,
            $language,
            $translated['name'],
            $translated['description'],
            $sourceHash
        );
    }

    /**
     * Translate every non-empty field independently because the name and
     * description may have been entered in different source languages.
     */
    public function translateFields(array $fields, string $targetLanguage): array
    {
        $targetLanguage = strtolower(trim($targetLanguage));
        $this->validateLanguage($targetLanguage);

        $fallback = [];
        foreach ($fields as $key => $value) {
            $fallback[$key] = is_string($value) ? $value : '';
        }

        $serviceUrl = config('services.translation.url');
        if (! is_string($serviceUrl) || trim($serviceUrl) === '') {
            return $fallback;
        }

        $translated = $fallback;
        $serviceKey = config('services.translation.key');

        foreach ($fallback as $key => $text) {
            if (trim($text) === '') {
                continue;
            }

            // Cache both successful results and short-lived fallbacks. If the
            // provider is down, every menu refresh must not wait for a timeout.
            $cacheKey = 'menu_translation:'.hash('sha256', implode('|', [
                (string) $serviceUrl,
                $targetLanguage,
                $text,
            ]));

            $cachedTranslation = Cache::get($cacheKey);

            if (is_string($cachedTranslation)) {
                $translated[$key] = $cachedTranslation;
                continue;
            }

            try {
                $payload = [
                    'q' => $text,
                    'source' => 'auto',
                    'target' => $targetLanguage,
                    'format' => 'text',
                ];

                if (is_string($serviceKey) && $serviceKey !== '') {
                    $payload['api_key'] = $serviceKey;
                }

                $response = Http::acceptJson()
                    ->asJson()
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->post($serviceUrl, $payload);

                if (! $response->successful()) {
                    Cache::put($cacheKey, $text, now()->addMinutes(5));
                    $translated[$key] = $text;
                    continue;
                }

                $result = $response->json('translatedText');
                $detectedLanguage = strtolower((string) data_get(
                    $response->json(),
                    'detectedLanguage.language',
                    ''
                ));

                if (! is_string($result) || trim($result) === '') {
                    Cache::put($cacheKey, $text, now()->addMinutes(5));
                    $translated[$key] = $text;
                    continue;
                }

                $value = $detectedLanguage === $targetLanguage ? $text : $result;

                // Successful translations are retained for 30 days. Provider
                // failures above only cache the original text for five minutes.
                Cache::put($cacheKey, $value, now()->addDays(30));
                $translated[$key] = $value;
            } catch (\Throwable $exception) {
                report($exception);
                Cache::put($cacheKey, $text, now()->addMinutes(5));
                $translated[$key] = $text;
            }
        }

        return $translated;
    }

    private function validateLanguage(string $language): void
    {
        if (! in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('Unsupported translation language.');
        }
    }

    private function sourceHash(array $source): string
    {
        return hash('sha256', json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected function createOrGetTranslation(
        Meal $meal,
        string $language,
        string $name,
        string $description,
        string $sourceHash
    ): MealTranslation {
        try {
            // The unique key is (meal_id, language). This update only runs
            // after the caller has confirmed the row is not manually edited.
            return MealTranslation::updateOrCreate(
                [
                    'meal_id' => $meal->id,
                    'language' => $language,
                ],
                [
                    'name' => $name,
                    'description' => $description,
                    'is_manual' => false,
                    'source_hash' => $sourceHash,
                ]
            );
        } catch (QueryException $exception) {
            $translation = $meal->translations()
                ->where('language', $language)
                ->first();

            if ($translation) {
                return $translation;
            }

            throw $exception;
        }
    }
}
