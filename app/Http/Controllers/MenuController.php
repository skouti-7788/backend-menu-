<?php

// namespace App\Http\Controllers;

// use App\Models\Restaurant;
// use App\Services\TranslationService;
// use Illuminate\Http\Request;

// class MenuController extends Controller
// {
//     public function show(Request $request, string $slug, TranslationService $translator)
//     {
//         $language = $request->query('lang', 'en');

//         $restaurant = Restaurant::where('slug', $slug)
//             ->with(['categories', 'meals.translations'])
//             ->firstOrFail();

//         $meals = $restaurant->meals->filter(fn ($meal) => $meal->status->value === 'active');

//         if ($language !== 'en') {
//             $meals = $meals->map(function ($meal) use ($language, $translator) {
//                 $translation = $translator->translateMeal($meal, $language);
//                 $meal->name = $translation->name;
//                 $meal->description = $translation->description;

//                 return $meal;
//             });
//         }

//         return response()->json([
//             'restaurant' => [
//                 'id' => $restaurant->id,
//                 'name' => $restaurant->name,
//                 'description' => $restaurant->description,
//                 'address' => $restaurant->address,
//                 'phone' => $restaurant->phone,
//                 'email' => $restaurant->email,
//                 'opening_hours' => $restaurant->opening_hours,
//                 'social_links' => $restaurant->social_links,
//                 'logo_url' => $restaurant->logo_url,
//                 'cover_image_url' => $restaurant->cover_image_url,
//                 'menu_url' => $restaurant->menu_url,
//             ],
//             'categories' => $restaurant->categories->map(fn ($category) => [
//                 'id' => $category->id,
//                 'name' => $category->name,
//                 'image_url' => $category->image ? url('storage/'.$category->image) : null,
//             ]),
//             'meals' => $meals->map(fn ($meal) => [
//                 'id' => $meal->id,
//                 'category_id' => $meal->category_id,
//                 'name' => $meal->name,
//                 'description' => $meal->description,
//                 'price' => $meal->price,
//                 'image_url' => $meal->image_url,
//                 'featured' => $meal->featured,
//             ]),
//         ]);
//     }
// }
