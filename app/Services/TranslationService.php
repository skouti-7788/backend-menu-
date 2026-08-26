<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealTranslation;
use Illuminate\Support\Facades\Http;

class TranslationService
{
    /**
     * Translate a meal into the requested language, saving the translation record.
     */
    public function translateMeal(Meal $meal, string $language): MealTranslation
    {
        $language = strtolower($language);

        if ($language === 'en' || $language === 'en_us') {
            return $this->createTranslation($meal, $language, $meal->name, $meal->description);
        }

        $translation = $meal->translations()->where('language', $language)->first();

        if ($translation) {
            return $translation;
        }

        $translated = $this->callExternalTranslationApi($meal, $language);

        return $this->createTranslation($meal, $language, $translated['name'], $translated['description']);
    }

    protected function createTranslation(Meal $meal, string $language, string $name, string $description): MealTranslation
    {
        return MealTranslation::create([
            'meal_id' => $meal->id,
            'language' => $language,
            'name' => $name,
            'description' => $description,
        ]);
    }

    protected function callExternalTranslationApi(Meal $meal, string $language): array
    {
        $serviceUrl = config('services.translation.url');
        $serviceKey = config('services.translation.key');
        $provider = config('services.translation.provider', 'generic');

        if (! $serviceUrl || ! $serviceKey) {
            return [
                'name' => $meal->name,
                'description' => $meal->description,
            ];
        }

        $payload = [
            'source' => 'en',
            'target' => $language,
            'texts' => [
                $meal->name,
                $meal->description,
            ],
        ];

        $response = Http::withToken($serviceKey)
            ->post($serviceUrl, $payload);

        if (! $response->successful()) {
            return [
                'name' => $meal->name,
                'description' => $meal->description,
            ];
        }

        $data = $response->json();

        if (isset($data['data']['translations'][0]['translatedText'])) {
            return [
                'name' => $data['data']['translations'][0]['translatedText'],
                'description' => $data['data']['translations'][1]['translatedText'] ?? $meal->description,
            ];
        }

        if (isset($data[0]['translations'][0]['text'])) {
            return [
                'name' => $data[0]['translations'][0]['text'],
                'description' => $data[1]['translations'][0]['text'] ?? $meal->description,
            ];
        }

        if (isset($data['translations'][0]['translatedText'])) {
            return [
                'name' => $data['translations'][0]['translatedText'],
                'description' => $data['translations'][1]['translatedText'] ?? $meal->description,
            ];
        }

        return [
            'name' => $meal->name,
            'description' => $meal->description,
        ];
    }
}
