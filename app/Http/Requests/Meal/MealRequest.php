<?php

namespace App\Http\Requests\Meal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurant = $this->route('restaurant');

        $restaurantId = is_object($restaurant)
            ? $restaurant->id
            : $restaurant;

        return [
            'category_id' => [
                'required',
                Rule::exists('menu_categories', 'id')
                    ->where(
                        fn ($query) =>
                            $query->where(
                                'restaurant_id',
                                $restaurantId
                            )
                    ),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'image' => [
                'nullable',
                'image',
                'max:5120',
            ],

            'status' => [
                'required',
                'in:active,inactive',
            ],

            'featured' => [
                'nullable',
                'boolean',
            ],
        ];
    }
}
