<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemFeedbackController extends Controller
{
    private const CATEGORIES = [
        'General',
        'Login',
        'Dashboard',
        'Members',
        'Schedules',
        'Attendance',
        'History',
        'Reports',
        'Audit Logs',
        'Settings',
        'Kiosk',
    ];

    public function index()
    {
        $feedback = DB::table('system_feedback')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(200)
            ->get();

        return ApiResponse::success($feedback);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:50'],
            'message'  => ['required', 'string', 'max:2000'],
            'rating'   => ['nullable', 'string', 'max:20'],
        ]);

        $userId = $request->header('X-User-Id');
        $username = $request->header('X-Username');

        $data = [
            'user_id'    => $userId ? (int) $userId : null,
            'username'   => $username ?: null,
            'category'   => $validated['category'],
            'message'    => $validated['message'],
            'rating'     => $validated['rating'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = DB::table('system_feedback')->insertGetId($data);

        $entry = DB::table('system_feedback')->where('id', $id)->first();

        return ApiResponse::created($entry, 'Feedback submitted successfully.');
    }

    public function categories()
    {
        return ApiResponse::success(self::CATEGORIES);
    }
}
