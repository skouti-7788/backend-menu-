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
        foreach ($fields as $key => $value) $fallback[$key] = is_string($value) ? $value : '';

        $serviceUrl = config('services.translation.url');
        if (! is_string($serviceUrl) || trim($serviceUrl) === '') return $fallback;

        $translated = $fallback;
        $contactEmail = config('services.translation.contact_email');

        foreach ($fallback as $key => $text) {
            if (trim($text) === '') continue;

            $cacheKey = 'menu_translation:'.hash('sha256', implode('|', [
                'mymemory', $serviceUrl, $targetLanguage, $text,
            ]));
            $cached = Cache::get($cacheKey);
            if (is_string($cached)) {
                $translated[$key] = $cached;
                continue;
            }

            try {
                $parts = $this->splitIntoChunks($text);
                $translatedParts = [];
                $failed = false;

                foreach ($parts as $part) {
                    $query = ['q' => $part, 'langpair' => 'autodetect|'.$targetLanguage, 'mt' => '1'];
                    if (is_string($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                        $query['de'] = $contactEmail;
                    }

                    $response = Http::acceptJson()->connectTimeout(2)->timeout(4)->get($serviceUrl, $query);
                    $status = (string) $response->json('responseStatus');
                    $result = $response->json('responseData.translatedText');
                    $detected = strtolower((string) $response->json('responseData.detectedLanguage', ''));
                    $detected = explode('-', $detected)[0];

                    if (! $response->successful() || $status !== '200' || ! is_string($result) || trim($result) === '') {
                        $failed = true;
                        break;
                    }
                    $translatedParts[] = $detected === $targetLanguage ? $part : $result;
                }

                if ($failed || count($translatedParts) !== count($parts)) {
                    Cache::put($cacheKey, $text, now()->addMinutes(5));
                    $translated[$key] = $text;
                    continue;
                }

                $value = implode(' ', $translatedParts);
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

    /**
     * MyMemory documents a 500-byte q limit. Use 450 bytes and split on
     * sentence/word boundaries to leave a safety margin.
     */
    private function splitIntoChunks(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?؟])\\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [trim($text)];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if (strlen($sentence) <= 450) {
                $candidate = $current === '' ? $sentence : $current.' '.$sentence;
                if (strlen($candidate) <= 450) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') $chunks[] = $current;
                $current = $sentence;
                continue;
            }

            if ($current !== '') $chunks[] = $current;
            $current = '';
            $words = preg_split('/\\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [$sentence];

            foreach ($words as $word) {
                if (strlen($word) > 450) {
                    if ($current !== '') $chunks[] = $current;
                    $current = '';
                    while (strlen($word) > 450) {
                        $piece = mb_strcut($word, 0, 450, 'UTF-8');
                        $chunks[] = $piece;
                        $word = mb_substr($word, mb_strlen($piece, 'UTF-8'), null, 'UTF-8');
                    }
                }
                $candidate = $current === '' ? $word : $current.' '.$word;
                if (strlen($candidate) > 450) {
                    if ($current !== '') $chunks[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks === [] ? [''] : $chunks;
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
