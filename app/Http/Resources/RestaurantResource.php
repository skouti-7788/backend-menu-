<?php

namespace App\Http\Resources;

// use Illuminate\Http\Resources\Json\JsonResource;

// class RestaurantResource extends JsonResource
// {
//     public function toArray($request): array
//     {
//         return [
//             'id' => $this->id,
//             'user_id' => $this->user_id,
//             'name' => $this->name,
//             'slug' => $this->slug,
//             'logo_url' => $this->logo_url,
//             'cover_image_url' => $this->cover_image_url,
//             'description' => $this->description,
//             'address' => $this->address,
//             'phone' => $this->phone,
//             'email' => $this->email,
//             'opening_hours' => $this->opening_hours,
//             'social_links' => $this->social_links,
//             'menu_url' => $this->menu_url,
//             'created_at' => $this->created_at,
//             'updated_at' => $this->updated_at,
//         ];
//     }
// }
 
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'opening_hours' => $this->opening_hours,
            'social_links' => $this->social_links,
            'logo_url' => $this->logo_url,
            'cover_image_url' => $this->cover_image_url,
            'menu_url' => $this->menu_url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}