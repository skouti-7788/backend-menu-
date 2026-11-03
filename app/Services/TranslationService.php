<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealTranslation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

class TranslationService
{
    /**
     * Return a cached translation, or translate the meal on first request.
     * The provider detects the source language automatically.
     */
    public function translateMeal(Meal $meal, string $language): MealTranslation
    {
        $language = strtolower(trim($language));

        if (! in_array($language, ['en', 'fr', 'ar'], true)) {
            throw new \InvalidArgumentException('Unsupported translation language.');
        }

        $source = [
            'name' => $meal->name,
            'description' => $meal->description ?? '',
        ];
        $sourceHash = hash('sha256', json_encode($source, JSON_UNESCAPED_UNICODE));

        $translation = $meal->translations()
            ->where('language', $language)
            ->first();

        if ($translation && ($translation->is_manual || $translation->source_hash === $sourceHash)) {
            return $translation;
        }

        $translated = $this->translateFields($source, $language);

        // Do not cache a fallback after a provider outage or missing configuration.
        if (
            $translated['name'] === $meal->name
            && $translated['description'] === ($meal->description ?? '')
        ) {
            return new MealTranslation([
                'meal_id' => $meal->id,
                'language' => $language,
                'name' => $translated['name'],
                'description' => $translated['description'],
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
     * Translate independent text fields with automatic source detection.
     * Empty fields are kept empty and never sent to the provider.
     */
    public function translateFields(array $fields, string $targetLanguage): array
    {
        $fallback = [];
        foreach ($fields as $key => $value) {
            $fallback[$key] = is_string($value) ? $value : '';
        }

        $serviceUrl = config('services.translation.url');
        if (! $serviceUrl) {
            return $fallback;
        }

        $serviceKey = config('services.translation.key');
        $translated = $fallback;

        foreach ($fallback as $key => $text) {
            if (trim($text) === '') {
                continue;
            }

            try {
                $payload = [
                    'q' => $text,
                    'source' => 'auto',
                    'target' => $targetLanguage,
                    'format' => 'text',
                ];

                // LibreTranslate accepts the key as a request field, not a Bearer token.
                if ($serviceKey) {
                    $payload['api_key'] = $serviceKey;
                }

                $response = Http::acceptJson()
                    ->asJson()
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->post($serviceUrl, $payload);

                if (! $response->successful()) {
                    continue;
                }

                $result = $response->json('translatedText');
                $detectedLanguage = strtolower((string) $response->json('detectedLanguage.language'));

                if (is_string($result) && $result !== '') {
                    // Do not replace a source-language value with an unnecessary translation.
                    $translated[$key] = $detectedLanguage === $targetLanguage
                        ? $text
                        : $result;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $translated;
    }

    protected function createOrGetTranslation(
        Meal $meal,
        string $language,
        string $name,
        string $description,
        string $sourceHash
    ): MealTranslation {
        try {
            return MealTranslation::updateOrCreate(
                [
                    'meal_id' => $meal->id,
                    'language' => $language,
                    'is_manual' => false,
                ],
                [
                    'name' => $name,
                    'description' => $description,
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
