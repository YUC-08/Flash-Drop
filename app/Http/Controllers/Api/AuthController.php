<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Create a new personal access token for authentication.
     *
     * @unauthenticated
     */
    public function token(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        if (! Auth::attempt(['email' => $validated['email'], 'password' => $validated['password']])) {
            return response()->json([
                'message' => 'Geçersiz kimlik bilgileri.',
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        $token = $user->createToken($validated['device_name'], [
            'drops:read',
            'drops:reserve',
        ])->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'abilities' => [
                'drops:read',
                'drops:reserve',
            ],
        ], 200);
    }
}
