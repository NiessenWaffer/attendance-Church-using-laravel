<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class SystemInfoController extends Controller
{
    public function index()
    {
        $database = [
            'status' => 'ok',
            'connection' => config('database.default'),
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'message' => 'Connected',
        ];

        try {
            DB::select('select 1 as ok');
        } catch (\Throwable $e) {
            $database['status'] = 'error';
            $database['message'] = $e->getMessage();
        }

        $storageWritable = is_writable(storage_path());
        $logsWritable = is_writable(storage_path('logs'));

        return ApiResponse::success([
            'app' => [
                'name' => config('app.name'),
                'environment' => config('app.env'),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
                'timezone' => config('app.timezone'),
                'server_time' => now()->format('Y-m-d H:i:s'),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
            'database' => $database,
            'storage' => [
                'storage_path_writable' => $storageWritable,
                'logs_path_writable' => $logsWritable,
            ],
            'features' => [
                'attendance_mode' => config('attendance.mode'),
                'kiosk_key_configured' => (string) config('attendance.kiosk_key', '') !== '',
                'integration_configured' => (string) config('integration.url', '') !== '',
                'cache_driver' => config('cache.default'),
                'queue_connection' => config('queue.default'),
            ],
        ]);
    }
}
