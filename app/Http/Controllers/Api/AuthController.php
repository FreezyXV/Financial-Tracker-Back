<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DefaultCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|string|email|max:190|unique:users',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($fields);
        DefaultCategories::seed($user);

        return response()->json([
            'user' => $user->fresh(),
            'token' => $user->createToken('api-token')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $fields = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $fields['email'])->first();
        if (! $user || ! Hash::check($fields['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Email ou mot de passe incorrect.']]);
        }

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('api-token')->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'email' => 'sometimes|email|max:190|unique:users,email,'.$user->id,
            'savings_rate_target' => 'sometimes|numeric|min:0|max:100',
            'large_transaction_threshold' => 'sometimes|numeric|min:0',
            'password' => ['sometimes', 'confirmed', Password::min(8)],
            'current_password' => 'required_with:password|current_password',
        ]);
        unset($data['current_password']);
        $user->update($data);

        return $user->fresh();
    }

    /** Suppression définitive du compte et de toutes ses données (droit à l'effacement). */
    public function destroy(Request $request)
    {
        $request->validate(['password' => 'required|current_password']);
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Compte supprimé']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnecté']);
    }
}
