<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MealResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'image_url' => $this->image_url,
            'status' => $this->status->value,
            'featured' => $this->featured,
            'translations' => $this->whenLoaded('translations', function () {
                return $this->translations->map(fn($translation) => [
                    'language' => $translation->language,
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
