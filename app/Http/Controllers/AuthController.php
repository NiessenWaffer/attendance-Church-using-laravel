<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $query = DB::table('admin_users')
            ->where('username', $validated['login'])
            ->where('is_active', 1);

        if (Schema::hasColumn('admin_users', 'email')) {
            $query->orWhere(function ($q) use ($validated) {
                $q->where('email', $validated['login'])
                    ->where('is_active', 1);
            });
        }

        $user = $query->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return ApiResponse::unauthorized('Invalid username or email or password.');
        }

        $admin = AdminUser::find($user->id);
        $admin->tokens()->delete();
        $token = $admin->createToken('cas-token')->plainTextToken;

        AuditLogger::log('auth.login', 'auth', "User {$user->username} logged in.", [], $user->username, $user->id);

        return ApiResponse::success([
            'user' => [
                'id'        => $user->id,
                'username'  => $user->username,
                'full_name' => $user->full_name,
            ],
            'token' => $token,
        ], 'Login successful.');
    }

    public function logout(Request $request)
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        AuditLogger::log('auth.logout', 'auth', 'User logged out.');

        return ApiResponse::success(null, 'Logged out successfully.');
    }
}
