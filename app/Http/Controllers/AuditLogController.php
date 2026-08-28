<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\CacheHelper;
use App\Support\CsvExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    private function indexQuery(Request $request)
    {
        $query = DB::table('audit_logs');

        $search = trim((string) $request->input('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        $module = $request->input('module');
        if ($module !== null && $module !== '') {
            $query->where('module', $module);
        }

        $action = $request->input('action');
        if ($action !== null && $action !== '') {
            $query->where('action', $action);
        }

        $username = trim((string) $request->input('username'));
        if ($username !== '') {
            $query->where('username', 'like', "%{$username}%");
        }

        $dateFrom = $request->input('date_from');
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        $dateTo = $request->input('date_to');
        if ($dateTo !== null && $dateTo !== '') {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        return $query;
    }

    public function index(Request $request)
    {
        $logs = $this->indexQuery($request)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(500)
            ->get();

        return ApiResponse::success($logs);
    }

    public function export(Request $request)
    {
        $logs = $this->indexQuery($request)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $headers = [
            'id', 'username', 'action', 'module', 'description',
            'ip_address', 'user_agent', 'metadata', 'created_at',
        ];

        $rows = $logs->map(function ($log) {
            $metadata = $log->metadata;
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : $metadata;
            }
            if (is_array($metadata)) {
                $metadata = json_encode($metadata);
            }

            return [
                $log->id,
                $log->username,
                $log->action,
                $log->module,
                $log->description,
                $log->ip_address,
                $log->user_agent,
                $metadata,
                $log->created_at,
            ];
        })->toArray();

        AuditLogger::log('audit.export', 'audit', "Exported audit logs to CSV ({$logs->count()} rows).");

        return CsvExporter::download('audit-logs-' . now()->format('Ymd-His') . '.csv', $headers, $rows);
    }

    public function modules()
    {
        $modules = CacheHelper::remember('audit.modules', 60, function () {
            return DB::table('audit_logs')
                ->select('module')
                ->distinct()
                ->whereNotNull('module')
                ->orderBy('module')
                ->pluck('module');
        });

        return ApiResponse::success($modules);
    }
}
