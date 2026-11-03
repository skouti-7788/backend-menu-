<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'different:current_password',
            ],
        ]);

        $user = $request->user();

        if (! Hash::check(
            $validated['current_password'],
            $user->password
        )) {
            return response()->json([
                'message' => __('messages.current_password_incorrect'),
                'errors' => [
                    'current_password' => [
                        __('messages.current_password_incorrect'),
                    ],
                ],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make(
                $validated['password']
            ),
        ])->save();

        return response()->json([
            'message' => __('messages.password_updated'),
        ]);
    }
}

