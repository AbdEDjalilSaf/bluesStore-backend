<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Access denied. Admin only.'], 403);
        }

        $token = $user->createToken('admin-token', ['*'], now()->addDays(7));

        return response()->json([
            'user' => $user,
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
        ]);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->currentAccessToken()->delete();

        $newToken = $user->createToken('admin-token', ['*'], now()->addDays(7));

        return response()->json([
            'token' => $newToken->plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
