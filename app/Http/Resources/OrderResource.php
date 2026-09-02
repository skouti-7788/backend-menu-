<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $subtotal = $this->whenLoaded('items', fn () => $this->items->sum('total_price'), 0);
        $subtotal = $subtotal ?: $this->items()->sum('total_price');
        $tax = round((float) $subtotal * 0.09, 2);
        $total = round((float) $subtotal + $tax, 2);

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'subtotal' => round((float) $subtotal, 2),
            'tax' => round((float) $tax, 2),
            'total' => round((float) $this->total ?: $total, 2),
            'table_id' => $this->table_id,
            'status' => $this->status->value,
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(fn($item) => [
                    'meal_id' => $item->meal_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'notes' => $item->notes,

                ]);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
