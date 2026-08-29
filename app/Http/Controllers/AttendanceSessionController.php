<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\AttendanceAudience;
use App\Support\AttendanceSchema;
use App\Support\AuditLogger;
use App\Support\CacheHelper;
use App\Services\ScheduleService;
use App\Services\MemberFetchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceSessionController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'service_id' => ['nullable', 'integer', 'min:1'],
            'schedule_id' => ['nullable', 'integer', 'min:1'],
        ]);

        app(ScheduleService::class)->ensureCurrent();

        $schema = app(AttendanceSchema::class);
        $sessions = $schema->sessionsTable();
        $records = $schema->recordsTable();

        $query = $schema->db()->table($sessions)
            ->select(...$schema->sessionSelectColumns($sessions, $records));

        $search = trim((string) $request->input('search'));
        if ($search !== '') {
            $query->where($sessions . '.' . $schema->sessionTitleColumn(), 'like', "%{$search}%");
        }

        $sessionType = $request->input('session_type');
        if ($sessionType !== null && $sessionType !== '') {
            $query->where($sessions . '.' . $schema->sessionTypeColumn(), $sessionType);
        }

        $status = $request->input('status');
        if ($status === 'open') {
            $query->whereIn($sessions . '.' . $schema->sessionStatusColumn(), ['scheduled', 'active']);
        } elseif ($status === 'closed') {
            $query->whereIn($sessions . '.' . $schema->sessionStatusColumn(), ['completed', 'cancelled']);
        } elseif (in_array($status, ['scheduled', 'active', 'completed', 'cancelled'], true)) {
            $query->where($sessions . '.' . $schema->sessionStatusColumn(), $status);
        }

        $dateFrom = $request->input('date_from');
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where($sessions . '.' . $schema->sessionDateColumn(), '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to');
        if ($dateTo !== null && $dateTo !== '') {
            $query->where($sessions . '.' . $schema->sessionDateColumn(), '<=', $dateTo);
        }

        $serviceId = $request->input('service_id', $request->input('schedule_id'));
        if ($serviceId !== null && $serviceId !== '' && $schema->hasColumn($sessions, 'service_id')) {
            $query->where($sessions . '.service_id', $serviceId);
        }

        $sessionsList = $query->orderBy($sessions . '.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy($sessions . '.start_time', 'desc')
            ->get();

        (new AttendanceAudience($schema, app(MemberFetchService::class)->list()))->enrich($sessionsList);

        return ApiResponse::success($sessionsList);
    }

    public function status(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:scheduled,active,completed,cancelled'],
        ]);
        $schema = app(AttendanceSchema::class);
        $sessions = $schema->sessionsTable();
        $session = $schema->db()->table($sessions)->where('id', $id)->first();

        if (!$session) {
            return ApiResponse::notFound();
        }

        $schema->db()->table($sessions)->where('id', $id)->update([
            $schema->sessionStatusColumn() => $validated['status'],
            'updated_at' => now(),
        ]);
        CacheHelper::forget('dashboard.summary');
        CacheHelper::forget('dashboard.insights');
        AuditLogger::log('attendance.session_status', 'attendance', "Changed attendance session #{$id} to {$validated['status']}.", ['session_id' => (int) $id, 'status' => $validated['status']]);

        return ApiResponse::success(['status' => $validated['status']], 'Attendance session updated.');
    }

}
