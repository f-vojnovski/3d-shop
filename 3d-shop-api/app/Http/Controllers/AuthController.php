<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller as BaseController;

class AuthController extends BaseController
{
    /**
     * A real bcrypt digest at the configured cost, of a random value. It has to
     * be valid: `password_verify` rejects a malformed hash in 0.2ms against
     * 190ms for a real one, which is the timing signal this is here to remove.
     */
    private const ABSENT_USER_HASH = '$2y$12$lcrScVYpCelgj6Dw.cmIOeVvhjVSLwPVfE5rBXzUOY3dOOXZI8wWG';

    public function register(Request $request) {
        $fields = $request->validate([
            'name' => 'required|string|unique:users,name',
            'email' => 'required|string|unique:users,email|email',
            'password' => 'required|string|min:8|confirmed'
        ]);

        $user = User::create([
            'name' => $fields['name'],
            'email'=> $fields['email'],
            'password' => $fields['password']
        ]);

        $token = $user->createToken('myapptoken')->plainTextToken;

        $response = [
            'user' => $user,
            'token' => $token
        ];

        return response($response, 201);
    }

    public function login(Request $request) {
        $fields = $request->validate([
            'name' => 'required|string',
            'password' => 'required|string'
        ]);

        $user = User::where('name', $fields['name'])->first();

        // One answer for a wrong name and a wrong password, and a hash check
        // either way: a faster refusal for names that do not exist answers the
        // same question the message used to.
        if (!Hash::check($fields['password'], $user->password ?? self::ABSENT_USER_HASH)) {
            return response([
                'message' => 'Those credentials do not match our records.'
            ], 401);
        }

        $token = $user->createToken('myapptoken')->plainTextToken;

        $response = [
            'user' => $user,
            'token' => $token
        ];

        return response($response, 200);
    }

    public function logout(Request $request) {
        // Revoke only the token this request authenticated with, so signing out
        // in one browser does not sign the user out everywhere else.
        $request->user()->currentAccessToken()->delete();

        return [
            'message' => 'Logged out'
        ];
    }
}
