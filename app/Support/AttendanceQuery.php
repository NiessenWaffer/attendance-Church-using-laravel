<?php

namespace App\Support;
use App\Services\MemberFetchService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceQuery
{
    public static function forHistory(Request $request)
    {
        $schema = app(AttendanceSchema::class);
        $records = $schema->recordsTable();
        $sessions = $schema->sessionsTable();
        $recordedBySql = 'NULL';
        $updatedAtSql = 'NULL';
        $notesSql = $schema->recordNotesColumn() ? $records . '.' . $schema->recordNotesColumn() : 'NULL';
        $nameCacheSql = $schema->mode() === 'local' ? $records . '.member_name_cache' : 'NULL';
        $photoCacheSql = $schema->mode() === 'local' ? $records . '.member_photo_cache' : 'NULL';
        $memberIdSql = $schema->recordMemberIdColumn()
            ? $records . '.' . $schema->recordMemberIdColumn()
            : 'NULL';

        $query = $schema->db()->table($records)
            ->select(
                $records . '.id',
                $records . '.' . $schema->recordSessionIdColumn() . ' as session_id',
                DB::raw($memberIdSql . ' as member_id'),
                DB::raw(($schema->recordMemberCodeColumn() ? $records . '.' . $schema->recordMemberCodeColumn() : 'NULL') . ' as member_code'),
                DB::raw($schema->attendanceStatusSelectSql($records, $sessions) . ' as attendance_status'),
                DB::raw($records . '.' . $schema->recordCheckInColumn() . ' as check_in_time'),
                DB::raw($notesSql . ' as remarks'),
                DB::raw($recordedBySql . ' as recorded_by'),
                DB::raw($nameCacheSql . ' as member_name_cache'),
                DB::raw($photoCacheSql . ' as member_photo_cache'),
                $records . '.created_at',
                DB::raw($updatedAtSql . ' as updated_at'),
                DB::raw($sessions . '.' . $schema->sessionTitleColumn() . ' as session_title'),
                DB::raw($sessions . '.' . $schema->sessionDateColumn() . ' as session_date')
            )
            ->join($sessions, $sessions . '.id', '=', $records . '.' . $schema->recordSessionIdColumn());

        $memberId = $request->input('member_id');
        if ($memberId !== null && $memberId !== '' && $schema->recordMemberIdColumn()) {
            $query->where($records . '.' . $schema->recordMemberIdColumn(), $memberId);
        }

        $memberCode = $request->input('member_code');
        if ($memberCode !== null && $memberCode !== '' && $schema->recordMemberCodeColumn()) {
            $query->where($records . '.' . $schema->recordMemberCodeColumn(), $memberCode);
        } elseif ($memberCode !== null && $memberCode !== '' && $schema->recordMemberIdColumn()) {
            $member = app(MemberFetchService::class)->findByCode((string) $memberCode);
            $query->where(
                $records . '.' . $schema->recordMemberIdColumn(),
                $member['external_id'] ?? -1
            );
        }

        $sessionId = $request->input('session_id');
        if ($sessionId !== null && $sessionId !== '') {
            $query->where($records . '.' . $schema->recordSessionIdColumn(), $sessionId);
        }

        $status = $request->input('attendance_status');
        if ($status !== null && $status !== '') {
            if ($schema->recordStatusColumn()) {
                $query->where($records . '.' . $schema->recordStatusColumn(), $status);
            } else {
                $query->whereRaw(
                    '(' . $schema->attendanceStatusSelectSql($records, $sessions) . ') = ?',
                    [$status]
                );
            }
        }

        $dateFrom = $request->input('date_from');
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where($sessions . '.' . $schema->sessionDateColumn(), '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to');
        if ($dateTo !== null && $dateTo !== '') {
            $query->where($sessions . '.' . $schema->sessionDateColumn(), '<=', $dateTo);
        }

        return $query;
    }
}
