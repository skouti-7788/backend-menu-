<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantAppearance;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RestaurantAppearanceController extends Controller
{
    private const FONT_FAMILY_WHITELIST = [
        'Inter',
        'Poppins',
        'Roboto',
        'Open Sans',
        'Montserrat',
        'Lato',
        'Playfair Display',
        'Merriweather',
    ];

    public function show(Request $request)
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $appearance = $restaurant->appearance()->firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            $this->defaultValues()
        );

        return response()->json($appearance->fresh());
    }

    public function update(Request $request)
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $validated = $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,avif', 'max:2048'],
            'header_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,avif', 'max:4096'],
            'background_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,avif', 'max:4096'],
            'primary_color' => ['nullable', 'string', 'max:20', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'secondary_color' => ['nullable', 'string', 'max:20', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'text_color' => ['nullable', 'string', 'max:20', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'background_color' => ['nullable', 'string', 'max:20', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'font_family' => ['nullable', 'string', 'max:100', 'in:' . implode(',', self::FONT_FAMILY_WHITELIST)],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_header_image' => ['nullable', 'boolean'],
            'remove_background_image' => ['nullable', 'boolean'],
        ]);

        $appearance = $restaurant->appearance()->firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            $this->defaultValues()
        );

        foreach (['logo', 'header_image', 'background_image'] as $field) {
            $removeFlag = 'remove_' . $field;

            if ($request->boolean($removeFlag)) {
                if ($appearance->{$field}) {
                    $this->deleteCloudinaryImageByUrl($appearance->{$field});
                }

                $validated[$field] = null;
                continue;
            }

            if ($request->hasFile($field)) {
                try {
                    if ($appearance->{$field}) {
                        $this->deleteCloudinaryImageByUrl($appearance->{$field});
                    }

                    $uploadedFile = Cloudinary::upload(
                        $request->file($field)->getRealPath(),
                        [
                            'folder' => 'menu-online/restaurants/appearance',
                            'resource_type' => 'image',
                        ]
                    );

                    $validated[$field] = $uploadedFile->getSecurePath();
                } catch (\Throwable $e) {
                    Log::error('Cloudinary appearance image upload failed.', [
                        'field' => $field,
                        'restaurant_id' => $restaurant->id,
                        'error' => $e->getMessage(),
                    ]);

                    throw ValidationException::withMessages([
                        $field => ['The image could not be uploaded. Please try another file.'],
                    ]);
                }
            }
        }

        $appearance->fill($validated);
        $appearance->save();

        return response()->json([
            'message' => 'Appearance updated successfully.',
            'appearance' => $appearance->fresh(),
        ]);
    }

    protected function resolveRestaurantForUser(Request $request): Restaurant
    {
        $restaurant = $request->user()?->restaurants()->first();

        if (! $restaurant) {
            abort(404, 'Restaurant not found for this user.');
        }

        return $restaurant;
    }

    protected function defaultValues(): array
    {
        return [
            'primary_color' => '#D97706',
            'secondary_color' => '#92400E',
            'text_color' => '#1F2937',
            'background_color' => '#FFFFFF',
            'font_family' => 'Inter',
        ];
    }

    protected function deleteCloudinaryImageByUrl(?string $url): void
    {
        if (! $url) {
            return;
        }

        $publicId = $this->extractCloudinaryPublicId($url);

        if (! $publicId) {
            return;
        }

        try {
            Cloudinary::destroy($publicId);
        } catch (\Throwable $e) {
            Log::error('Cloudinary appearance image deletion failed.', [
                'url' => $url,
                'public_id' => $publicId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function extractCloudinaryPublicId(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! isset($parsed['path'])) {
            return null;
        }

        $path = ltrim($parsed['path'], '/');

        if (preg_match('/^(?:image|video|raw|auto)\/upload\/(?:v\d+\/)?(.+)$/', $path, $matches)) {
            $publicId = $matches[1];

            return preg_replace('/\.[^.]+$/', '', $publicId);
        }

        return null;
    }
}
