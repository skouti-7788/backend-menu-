<?php

namespace App\Http\Requests\Order;

use App\Models\Meal;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isPublicMenuOrder = $this->route('slug') !== null;

        $restaurantId = null;

        if ($isPublicMenuOrder) {
            $restaurantId = Restaurant::where(
                'slug',
                $this->route('slug')
            )->value('id');
        } else {
            $restaurant = $this->route('restaurant');

            $restaurantId = is_object($restaurant)
                ? $restaurant->id
                : $restaurant;
        }

        $statusRules = $isPublicMenuOrder
            ? ['nullable', 'in:pending']
            : [
                'nullable',
                'in:pending,preparing,ready,completed,cancelled',
            ];

        $rules = [
            'customer_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:32',
            ],

            'address' => [
                'required',
                'string',
                'max:1024',
            ],

            'status' => $statusRules,

            'items' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],

            'items.*.meal_id' => [
                'required',
                'integer',
                'exists:meals,id',

                function (
                    $attribute,
                    $value,
                    $fail
                ) use (
                    $restaurantId
                ) {
                    if ($restaurantId === null) {
                        return;
                    }

                    if (
                        ! Meal::where(
                            'restaurant_id',
                            $restaurantId
                        )
                        ->whereKey($value)
                        ->where(
                            'status',
                            'active'
                        )
                        ->exists()
                    ) {
                        $fail(
                            'The selected meal is not available for this restaurant.'
                        );
                    }
                },
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],

            'items.*.notes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];

        /*
         * =====================================================
         * DASHBOARD ORDER
         * =====================================================
         *
         * table_id must belong to the same restaurant.
         */
        if (! $isPublicMenuOrder) {
            $rules['table_id'] = [
                'nullable',
                'integer',
                Rule::exists(
                    'restaurant_tables',
                    'id'
                )->where(
                    fn ($query) =>
                        $query->where(
                            'restaurant_id',
                            $restaurantId
                        )
                ),
            ];
        }

        /*
         * =====================================================
         * PUBLIC MENU ORDER
         * =====================================================
         */
        if ($isPublicMenuOrder) {
            $rules['table_token'] = [
                'required',
                'string',
                'max:64',

                function (
                    $attribute,
                    $value,
                    $fail
                ) use (
                    $restaurantId
                ) {
                    if ($restaurantId === null) {
                        return;
                    }

                    if (
                        ! RestaurantTable::where(
                            'restaurant_id',
                            $restaurantId
                        )
                        ->where(
                            'qr_token',
                            $value
                        )
                        ->exists()
                    ) {
                        $fail(
                            'The selected table is invalid for this restaurant.'
                        );
                    }
                },
            ];
        }

        return $rules;
    }
}