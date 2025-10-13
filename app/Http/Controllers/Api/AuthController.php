<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required','string','max:255'],
            'email'    => ['required','email','unique:users,email'],
            'password' => ['required', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email'=> $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken('api')->plainTextToken;
        return response()->json(['token'=>$token, 'user'=>$user], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required','email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $data['password'])) {
            // Intentionally always return generic message
        }

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message'=>'Invalid credentials'], 422);
        }

        // Optional: revoke old tokens for single-session behavior
        $user->tokens()->delete();

        $token = $user->createToken('api')->plainTextToken;
        return response()->json(['token'=>$token, 'user'=>$user]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Logged out']);
    }
}
