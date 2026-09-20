<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealTranslation;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\QueryException;

class TranslationService
{
    /**
     * Translate a meal into the requested language and cache the result.
     */
    public function translateMeal(Meal $meal, string $language): MealTranslation
    {
        $language = strtolower(trim($language));

        if ($language === 'en' || $language === 'en_us') {
            return $this->createOrGetTranslation(
                $meal,
                $language,
                $meal->name,
                $meal->description ?? ''
            );
        }

        $translation = $meal->translations()
            ->where('language', $language)
            ->first();

        if ($translation) {
            return $translation;
        }

        $translated = $this->callExternalTranslationApi(
            $meal,
            $language
        );

        return $this->createOrGetTranslation(
            $meal,
            $language,
            $translated['name'],
            $translated['description']
        );
    }

    /**
     * Create a translation safely when concurrent requests happen.
     */
    protected function createOrGetTranslation(
        Meal $meal,
        string $language,
        string $name,
        string $description
    ): MealTranslation {
        try {
            return MealTranslation::firstOrCreate(
                [
                    'meal_id' => $meal->id,
                    'language' => $language,
                ],
                [
                    'name' => $name,
                    'description' => $description,
                ]
            );
        } catch (QueryException $exception) {
            // Another concurrent request may have created it.
            $translation = $meal->translations()
                ->where('language', $language)
                ->first();

            if ($translation) {
                return $translation;
            }

            throw $exception;
        }
    }

    /**
     * Call the external translation provider safely.
     */
    protected function callExternalTranslationApi(
        Meal $meal,
        string $language
    ): array {
        $fallback = [
            'name' => $meal->name,
            'description' => $meal->description ?? '',
        ];

        $serviceUrl = config('services.translation.url');
        $serviceKey = config('services.translation.key');

        if (! $serviceUrl || ! $serviceKey) {
            return $fallback;
        }

        $payload = [
            'source' => 'en',
            'target' => $language,
            'texts' => [
                $meal->name,
                $meal->description ?? '',
            ],
        ];

        try {
            $response = Http::withToken($serviceKey)
                ->connectTimeout(3)
                ->timeout(10)
                ->post($serviceUrl, $payload);

            if (! $response->successful()) {
                return $fallback;
            }

            $data = $response->json();

            if (isset($data['data']['translations'][0]['translatedText'])) {
                return [
                    'name' => $data['data']['translations'][0]['translatedText'],
                    'description' =>
                        $data['data']['translations'][1]['translatedText']
                        ?? $fallback['description'],
                ];
            }

            if (isset($data[0]['translations'][0]['text'])) {
                return [
                    'name' => $data[0]['translations'][0]['text'],
                    'description' =>
                        $data[1]['translations'][0]['text']
                        ?? $fallback['description'],
                ];
            }

            if (isset($data['translations'][0]['translatedText'])) {
                return [
                    'name' => $data['translations'][0]['translatedText'],
                    'description' =>
                        $data['translations'][1]['translatedText']
                        ?? $fallback['description'],
                ];
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $fallback;
    }
}