<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller as BaseController;

class AuthController extends BaseController
{
    public function register(Request $request) {
        $fields = $request->validate([
            'name' => 'required|string|unique:users,name',
            'email' => 'required|string|unique:users,email|email',
            'password' => 'required|string|confirmed'
        ]);

        $user = User::create([
            'name' => $fields['name'],
            'email'=> $fields['email'],
            'password' => bcrypt($fields['password'])
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

        if (!$user) {
            return response([
                'message' => 'Username not found'
            ], 401);
        }

        if (!Hash::check($fields['password'], $user->password)) {
            return response([
                'message' => 'Incorrect password'
            ], 401);
        }

        $token = $user->createToken('myapptoken')->plainTextToken;

        $response = [
            'user' => $user,
            'token' => $token
        ];

        return response($response, 201);
    }

    public function logout(Request $request) {
        auth()->user()->tokens()->delete();

        return [
            'message' => 'Logged out'
        ];
    }
}
