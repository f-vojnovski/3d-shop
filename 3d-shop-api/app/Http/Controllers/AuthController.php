<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Session cookies, not bearer tokens. Client and API are one origin - both the
 * dev server and nginx proxy `/api` - so Sanctum's stateful mode applies and the
 * session lives in a cookie no script on the page can read.
 */
class AuthController extends BaseController
{
    /**
     * A real bcrypt digest at the configured cost, of a random value. It has to
     * be valid: `password_verify` rejects a malformed hash in 0.2ms against
     * 190ms for a real one, which is the timing signal this is here to remove.
     */
    private const ABSENT_USER_HASH = '$2y$12$lcrScVYpCelgj6Dw.cmIOeVvhjVSLwPVfE5rBXzUOY3dOOXZI8wWG';

    public function register(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string|unique:users,name',
            'email' => 'required|string|unique:users,email|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $fields['name'],
            'email' => $fields['email'],
            'password' => $fields['password'],
        ]);

        $this->start($request, $user);

        return response(['user' => $user], 201);
    }

    public function login(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('name', $fields['name'])->first();

        // One answer for a wrong name and a wrong password, and a hash check
        // either way: a faster refusal for names that do not exist would answer
        // the question the message refuses to.
        if (! Hash::check($fields['password'], $user->password ?? self::ABSENT_USER_HASH)) {
            return response([
                'message' => 'Those credentials do not match our records.',
            ], 401);
        }

        $this->start($request, $user);

        return response(['user' => $user], 200);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['message' => 'Logged out'];
    }

    /**
     * A new session id on the way in: one fixed beforehand must not become the
     * one they are signed in with.
     */
    private function start(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);

        $request->session()->regenerate();
    }
}
