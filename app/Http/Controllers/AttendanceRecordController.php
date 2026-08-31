<?php

namespace App\Http\Controllers;

use App\Services\AttendanceRecordService;
use App\Services\FollowupService;
use App\Services\MemberFetchService;
use App\Support\ApiResponse;
use App\Support\AttendanceQuery;
use App\Support\AttendanceSchema;
use App\Support\AuditLogger;
use App\Support\CacheHelper;
use App\Support\CsvExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceRecordController extends Controller
{
    public function showSessionAttendance($sessionId)
    {
        $schema = app(AttendanceSchema::class);
        $sessions = $schema->sessionsTable();
        $recordsTable = $schema->recordsTable();
        $db = $schema->db();

        $session = $db->table($sessions)
            ->select(...$schema->sessionSelectColumns($sessions, $recordsTable))
            ->addSelect(DB::raw('NULL as remarks'))
            ->where($sessions . '.id', $sessionId)
            ->first();

        if (!$session) {
            return ApiResponse::notFound();
        }

        $memberService = app(MemberFetchService::class);
        $members = $memberService->list();

        $memberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();
        $memberAlias = $schema->recordMemberCodeColumn() ? 'member_code' : 'member_id';
        $select = [$recordsTable . '.*'];
        if ($memberColumn !== null) {
            $select[] = DB::raw($recordsTable . '.' . $memberColumn . ' as ' . $memberAlias);
        }
        if (!$schema->hasColumn($recordsTable, 'member_name_cache')) {
            $select[] = DB::raw('NULL as member_name_cache');
        }
        if (!$schema->hasColumn($recordsTable, 'member_photo_cache')) {
            $select[] = DB::raw('NULL as member_photo_cache');
        }

        $records = $db->table($recordsTable)
            ->select($select)
            ->where($schema->recordSessionIdColumn(), $sessionId)
            ->get();
        $memberService->hydrateRecords($records);

        return ApiResponse::success([
            'session' => $session,
            'members' => $members,
            'records' => $records,
        ]);
    }

    public function history(Request $request)
    {
        $schema = app(AttendanceSchema::class);
        $records = AttendanceQuery::forHistory($request)
            ->orderBy($schema->sessionsTable() . '.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy($schema->recordsTable() . '.id', 'desc')
            ->get();

        app(MemberFetchService::class)->hydrateRecords($records);

        return ApiResponse::success($records);
    }

    public function followups()
    {
        $rows = app(FollowupService::class)->missingMembers();

        return ApiResponse::success($rows);
    }

    public function saveFollowup(Request $request)
    {
        $validated = $request->validate([
            'external_member_id' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:open,completed'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (!Schema::hasTable('attendance_followups')) {
            return ApiResponse::error('Follow-up storage is not available.', 503);
        }

        $data = [
            'note' => $validated['note'] ?? null,
            'status' => $validated['status'],
            'due_date' => $validated['due_date'] ?? null,
            'completed_at' => $validated['status'] === 'completed' ? now() : null,
            'updated_at' => now(),
        ];
        DB::table('attendance_followups')->updateOrInsert(
            ['external_member_id' => $validated['external_member_id']],
            array_merge($data, [
                'created_by' => optional($request->user())->id,
            ])
        );

        AuditLogger::log('attendance.followup_update', 'attendance', "Updated follow-up for member {$validated['external_member_id']}.", ['external_member_id' => $validated['external_member_id'], 'status' => $validated['status']]);
        return ApiResponse::success(null, 'Follow-up saved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'external_member_id' => ['required', 'string', 'max:150'],
        ]);
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $session = $db->table($schema->sessionsTable())->where('id', $validated['session_id'])->first();
        if (!$session) return ApiResponse::notFound('Attendance session not found.');

        $member = app(MemberFetchService::class)->findByIdentifierLive($validated['external_member_id']);

        $recordService = app(AttendanceRecordService::class);
        $canonicalMemberId = $recordService->canonicalMemberIdentifier($validated['external_member_id'], $member);
        if ($canonicalMemberId === null) {
            return ApiResponse::notFound('External member not found.');
        }

        $result = $recordService->insert($session, $canonicalMemberId, $member);

        if ($result['duplicate']) {
            return ApiResponse::error('This member is already recorded for the session.', 409);
        }

        AuditLogger::log('attendance.record_create', 'attendance', "Manually added attendance for member {$canonicalMemberId}.", ['session_id' => (int) $session->id, 'external_member_id' => $canonicalMemberId]);
        return ApiResponse::created(null, 'Attendance recorded.');
    }

    public function destroy($id)
    {
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $table = $schema->recordsTable();

        $record = $db->transaction(function () use ($db, $table, $id) {
            $record = $db->table($table)->where('id', $id)->lockForUpdate()->first();
            if (!$record) {
                return null;
            }

            $db->table($table)->where('id', $id)->delete();

            return $record;
        });

        if (!$record) {
            return ApiResponse::notFound('Attendance record not found.');
        }

        CacheHelper::forget('dashboard.summary');
        CacheHelper::forget('dashboard.insights');
        AuditLogger::log(
            'attendance.record_delete',
            'attendance',
            "Deleted attendance record #{$id}.",
            [
                'record_id' => (int) $id,
                'session_id' => isset($record->{$schema->recordSessionIdColumn()})
                    ? (int) $record->{$schema->recordSessionIdColumn()}
                    : null,
                'external_member_id' => $record->external_member_id ?? null,
            ]
        );

        return ApiResponse::success(null, 'Attendance record deleted.');
    }

    public function memberStats($identifier)
    {
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $records = $schema->recordsTable();
        $sessions = $schema->sessionsTable();
        $memberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();

        if (!$memberColumn) {
            return ApiResponse::success(['total' => 0, 'present' => 0, 'eligible_member_days' => 0, 'present_member_days' => 0, 'rate' => 0, 'last_attendance_date' => null]);
        }

        $storedIdentifier = $identifier;
        if ($schema->recordMemberCodeColumn() === null && $schema->recordMemberIdColumn() !== null) {
            $member = app(MemberFetchService::class)->findByCode((string) $identifier);
            if (!$member || empty($member['external_id'])) {
                return ApiResponse::success(['total' => 0, 'present' => 0, 'eligible_member_days' => 0, 'present_member_days' => 0, 'rate' => 0, 'last_attendance_date' => null]);
            }
            $storedIdentifier = $member['external_id'];
        }

        $query = $db->table($records . ' as r')
            ->join($sessions . ' as s', 's.id', '=', 'r.' . $schema->recordSessionIdColumn())
            ->where('r.' . $memberColumn, $storedIdentifier)
            ->where('s.' . $schema->sessionDateColumn(), '<=', now()->format('Y-m-d'))
            ->where('s.' . $schema->sessionStatusColumn(), '!=', 'cancelled');
        $present = (clone $query)
            ->whereRaw('(' . $schema->attendanceStatusSelectSql('r', 's') . ') = "present"')
            ->distinct()
            ->count('s.' . $schema->sessionDateColumn());
        $last = (clone $query)->max('s.' . $schema->sessionDateColumn());
        $total = $db->table($sessions . ' as s')
            ->where('s.' . $schema->sessionDateColumn(), '<=', now()->format('Y-m-d'))
            ->where('s.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
            ->distinct()
            ->count('s.' . $schema->sessionDateColumn());

        return ApiResponse::success([
            'total' => (int) $total,
            'present' => (int) $present,
            'eligible_member_days' => (int) $total,
            'present_member_days' => (int) $present,
            'metric_basis' => 'unique_member_calendar_days',
            'rate' => AttendanceSchema::attendanceRate((int) $present, (int) $total),
            'last_attendance_date' => $last,
        ]);
    }

    public function export(Request $request)
    {
        $schema = app(AttendanceSchema::class);
        $records = AttendanceQuery::forHistory($request)
            ->orderBy($schema->sessionsTable() . '.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy($schema->recordsTable() . '.id', 'desc')
            ->get();

        app(MemberFetchService::class)->hydrateRecords($records);

        $headers = [
            'record_id', 'session_date', 'session_title', 'member_code',
            'member_name', 'attendance_status', 'check_in_time', 'remarks',
        ];

        $rows = $records->map(function ($record) {
            return [
                $record->id,
                $record->session_date,
                $record->session_title,
                $record->member_code,
                trim($record->first_name . ' ' . $record->last_name),
                $record->attendance_status,
                $record->check_in_time,
                $record->remarks,
            ];
        })->toArray();

        AuditLogger::log('attendance.export', 'attendance', "Exported attendance records to CSV ({$records->count()} rows).");

        return CsvExporter::download('attendance-records-' . now()->format('Ymd-His') . '.csv', $headers, $rows);
    }

}
