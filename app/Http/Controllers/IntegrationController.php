<?php

namespace App\Http\Controllers;

use App\Services\MemberFetchService;
use App\Support\ApiResponse;
use App\Support\AttendanceSchema;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IntegrationController extends Controller
{
    public function status(MemberFetchService $service)
    {
        $schema = app(AttendanceSchema::class);

        return ApiResponse::success(array_merge($service->status(), [
            'attendance_mode' => $schema->mode(),
        ]));
    }

    public function fetch(Request $request, MemberFetchService $service)
    {
        try {
            $refresh = $request->boolean('refresh');
            $result = $service->fetch($refresh);

            AuditLogger::log(
                'integration.fetch',
                'integration',
                ($refresh ? 'Refreshed' : 'Loaded') . " {$result['count']} external member(s).",
                [
                    'count' => $result['count'],
                    'refresh' => $refresh,
                    'degraded' => $result['degraded'],
                    'truncated' => $result['truncated'],
                ]
            );

            $partial = $result['degraded'] || $result['truncated'];
            $message = ($refresh ? 'Refreshed' : 'Loaded') . " {$result['count']} external member(s).";

            if ($result['degraded']) {
                $message = 'The integration failed; serving the last known-good member cache.';
            } elseif ($result['truncated']) {
                $message = "Loaded {$result['count']} external member(s) up to the configured page cap.";
            }

            return ApiResponse::success($result, $message, $partial ? 206 : 200);
        } catch (\Throwable $e) {
            Log::error('integration.fetch failed.', [
                'exception' => $e,
                'url' => config('integration.url'),
            ]);

            return ApiResponse::error('Member fetch failed: ' . $e->getMessage(), 502);
        }
    }

}
