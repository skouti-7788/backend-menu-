<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
class ForgotPasswordController extends Controller
{
    public function sendLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );

            if ($status === Password::RESET_LINK_SENT) {
                return response()->json([
                    'message' => 'If the account exists, a password reset link will be sent to its email.',
                ]);
            }

            if ($status === Password::RESET_THROTTLED) {
                return response()->json([
                    'message' => 'Please wait before requesting another reset link.',
                ], 429);
            }

            return response()->json([
                'message' => 'If the account exists, a password reset link will be sent to its email.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Forgot password failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Unable to process the password reset request.',
            ], 500);
        }
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'The reset link is invalid or has expired.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }
}
