<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class RestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'cover_image' => ['nullable', 'image', 'max:5120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:1024'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'opening_hours' => ['nullable', 'array'],
            'opening_hours.*.day' => ['required_with:opening_hours', 'string', 'max:32'],
            'opening_hours.*.from' => ['required_with:opening_hours', 'string', 'max:16'],
            'opening_hours.*.to' => ['required_with:opening_hours', 'string', 'max:16'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url'],
        ];
    }
}
