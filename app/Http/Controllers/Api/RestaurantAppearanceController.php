<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RestaurantAppearanceController extends Controller
{
    /**
     * Allowed font families.
     */
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

    /**
     * Get restaurant appearance.
     */
    public function show(Request $request)
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $this->requirePermission(
            $restaurant,
            'appearance.view'
        );

        $appearance = $restaurant->appearance()->firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            $this->defaultValues()
        );

        return response()->json(
            $appearance->fresh()
        );
    }

    /**
     * Update restaurant appearance.
     */
    public function update(Request $request)
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $this->requirePermission(
            $restaurant,
            'appearance.update'
        );

        $validated = $request->validate([
            'logo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp,avif',
                'max:2048',
            ],

            'header_image' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp,avif',
                'max:4096',
            ],

            'background_image' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp,avif',
                'max:4096',
            ],

            'primary_color' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',
            ],

            'secondary_color' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',
            ],

            'text_color' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',
            ],

            'background_color' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',
            ],

            'font_family' => [
                'nullable',
                'string',
                'max:100',
                'in:' . implode(',', self::FONT_FAMILY_WHITELIST),
            ],

            'remove_logo' => [
                'nullable',
                'boolean',
            ],

            'remove_header_image' => [
                'nullable',
                'boolean',
            ],

            'remove_background_image' => [
                'nullable',
                'boolean',
            ],
        ]);

        $appearance = $restaurant->appearance()->firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            $this->defaultValues()
        );

        /*
        |--------------------------------------------------------------------------
        | Images
        |--------------------------------------------------------------------------
        */

        foreach ([
            'logo',
            'header_image',
            'background_image',
        ] as $field) {

            $removeFlag = 'remove_' . $field;

            /*
            |--------------------------------------------------------------------------
            | Remove existing image
            |--------------------------------------------------------------------------
            */

            if ($request->boolean($removeFlag)) {

                $oldImage = $appearance->{$field};

                $validated[$field] = null;

                $appearance->fill([
                    $field => null,
                ]);

                $appearance->save();

                if ($oldImage) {
                    $this->deleteCloudinaryImageByUrl($oldImage);
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Upload new image
            |--------------------------------------------------------------------------
            */

            if ($request->hasFile($field)) {

                try {

                    $oldImage = $appearance->{$field};

                    /*
                    |--------------------------------------------------------------------------
                    | Upload new image FIRST.
                    | We don't delete the old image before a successful upload.
                    |--------------------------------------------------------------------------
                    */

                    $uploadedFile = Cloudinary::upload(
                        $request
                            ->file($field)
                            ->getRealPath(),
                        [
                            'folder' => sprintf(
                                'menu-online/restaurants/%d/appearance',
                                $restaurant->id
                            ),
                            'resource_type' => 'image',
                        ]
                    );

                    $newImageUrl = $uploadedFile->getSecurePath();

                    if (! $newImageUrl) {
                        throw new \RuntimeException(
                            'Cloudinary did not return a secure URL.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Save new URL
                    |--------------------------------------------------------------------------
                    */

                    $validated[$field] = $newImageUrl;

                    /*
                    |--------------------------------------------------------------------------
                    | Delete old image AFTER successful upload.
                    |--------------------------------------------------------------------------
                    */

                    if ($oldImage) {
                        $this->deleteCloudinaryImageByUrl(
                            $oldImage
                        );
                    }

                } catch (\Throwable $e) {

                    Log::error(
                        'Cloudinary appearance image upload failed.',
                        [
                            'field' => $field,
                            'restaurant_id' => $restaurant->id,
                            'error' => $e->getMessage(),
                        ]
                    );

                    throw ValidationException::withMessages([
                        $field => [
                            'The image could not be uploaded. Please try another file.',
                        ],
                    ]);
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save appearance
        |--------------------------------------------------------------------------
        */

        $appearance->fill($validated);
        $appearance->save();

        return response()->json([
            'message' => 'Appearance updated successfully.',
            'appearance' => $appearance->fresh(),
        ]);
    }

    /**
     * Default appearance values.
     */
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

    /**
     * Delete Cloudinary image using its URL.
     */
    protected function deleteCloudinaryImageByUrl(
        ?string $url
    ): void {

        if (! $url) {
            return;
        }

        $publicId = $this->extractCloudinaryPublicId(
            $url
        );

        if (! $publicId) {
            Log::warning(
                'Unable to extract Cloudinary public ID.',
                [
                    'url' => $url,
                ]
            );

            return;
        }

        try {

            Cloudinary::destroy($publicId);

        } catch (\Throwable $e) {

            Log::error(
                'Cloudinary appearance image deletion failed.',
                [
                    'url' => $url,
                    'public_id' => $publicId,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Extract Cloudinary public ID from URL.
     */
    protected function extractCloudinaryPublicId(
        string $url
    ): ?string {

        $parsed = parse_url($url);

        if (! isset($parsed['path'])) {
            return null;
        }

        $path = ltrim(
            $parsed['path'],
            '/'
        );

        /*
        |--------------------------------------------------------------------------
        | Example:
        |
        | image/upload/v123456/menu-online/restaurants/1/appearance/logo.png
        |
        |--------------------------------------------------------------------------
        */

        if (
            preg_match(
                '/^(?:image|video|raw|auto)\/upload\/(?:v\d+\/)?(.+)$/',
                $path,
                $matches
            )
        ) {

            $publicId = $matches[1];

            /*
            |--------------------------------------------------------------------------
            | Remove extension.
            |--------------------------------------------------------------------------
            */

            return preg_replace(
                '/\.[^.]+$/',
                '',
                $publicId
            );
        }

        return null;
    }
}
 