<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:1024'],
            'status' => ['nullable', 'in:pending,preparing,ready,completed,cancelled'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.meal_id' => ['required', 'exists:meals,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'], 
            'items.*.notes' => ['nullable', 'string', 'max:500'], 
            // 'table_id' => ['required', 'integer'],
            // 'table_token' => ['required', 'string', 'max:64'],
        ];
    }
}
