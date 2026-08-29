<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceSchema
{
    public function mode(): string
    {
        return config('attendance.mode', 'local') === 'prod' ? 'prod' : 'local';
    }

    public function connection(): string
    {
        return (string) config('attendance.connection', config('database.default'));
    }

    public function db()
    {
        return DB::connection($this->connection());
    }

    public function sessionsTable(): string
    {
        return (string) config('attendance.tables.' . $this->mode() . '.sessions', 'attendance_sessions');
    }

    public function recordsTable(): string
    {
        return (string) config('attendance.tables.' . $this->mode() . '.records', 'attendance_records');
    }

    public function sessionTitleColumn(): string
    {
        return (string) config('attendance.columns.' . $this->mode() . '.session_title', 'session_name');
    }

    public function sessionDateColumn(): string
    {
        return (string) config('attendance.columns.' . $this->mode() . '.session_date', 'session_date');
    }

    public function sessionTypeColumn(): string
    {
        return (string) config('attendance.columns.' . $this->mode() . '.session_type', 'service_type');
    }

    public function sessionStatusColumn(): string
    {
        return (string) config('attendance.columns.' . $this->mode() . '.session_status', 'status');
    }

    public function recordSessionIdColumn(): string
    {
        return (string) config('attendance.columns.' . $this->mode() . '.record_session_id', 'session_id');
    }

    public function recordNotesColumn(): string
    {
        return (string) config('attendance.columns.' . $this->mode() . '.record_notes', 'remarks');
    }

    public function recordStatusColumn(): ?string
    {
        $value = config('attendance.columns.' . $this->mode() . '.record_status');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function recordMemberCodeColumn(): ?string
    {
        $value = config('attendance.columns.' . $this->mode() . '.record_member_code');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function recordMemberIdColumn(): ?string
    {
        $value = config('attendance.columns.' . $this->mode() . '.record_member_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function recordCheckInColumn(): string
    {
        return (string) config('attendance.columns.' . $this->mode() . '.record_check_in', 'created_at');
    }

    public function explicitAttendanceStatus(): bool
    {
        return $this->recordStatusColumn() !== null;
    }

    public function hasColumn(string $table, string $column): bool
    {
        static $columns = [];
        $key = $this->connection() . ':' . $table . ':' . $column;

        if (!array_key_exists($key, $columns)) {
            $columns[$key] = Schema::connection($this->connection())->hasColumn($table, $column);
        }

        return $columns[$key];
    }

    public function sessionClosedSelectSql(string $sessionTable): string
    {
        return "CASE WHEN {$sessionTable}.{$this->sessionStatusColumn()} IN ('completed', 'cancelled') THEN 1 ELSE 0 END";
    }

    public function presentCountSelectSql(string $sessionTable, string $recordsTable): string
    {
        $memberColumn = $this->recordMemberCodeColumn() ?: $this->recordMemberIdColumn();
        $count = $memberColumn !== null
            ? 'COUNT(DISTINCT ' . $recordsTable . '.' . $memberColumn . ')'
            : 'COUNT(*)';

        if ($this->explicitAttendanceStatus()) {
            return '(SELECT ' . $count . ' FROM ' . $recordsTable
                . ' WHERE ' . $recordsTable . '.' . $this->recordSessionIdColumn() . ' = ' . $sessionTable . '.id'
                . ' AND ' . $recordsTable . '.' . $this->recordStatusColumn() . ' = "present")';
        }

        // A check-in is present; a missing check-in is not an attendance record.
        return '(SELECT ' . $count . ' FROM ' . $recordsTable
            . ' WHERE ' . $recordsTable . '.' . $this->recordSessionIdColumn() . ' = ' . $sessionTable . '.id'
            . ' AND ' . $recordsTable . '.' . $this->recordCheckInColumn() . ' IS NOT NULL)';
    }

    /**
     * SQL expression yielding an attendance_status value for a records row.
     * In prod mode the status is derived from the check-in time vs the session
     * start time: no check-in => absent, otherwise present.
     * Requires the sessions table to be available in the query scope.
     */
    public function attendanceStatusSelectSql(string $recordsTable, string $sessionTable): string
    {
        if ($this->explicitAttendanceStatus()) {
            return $recordsTable . '.' . $this->recordStatusColumn();
        }

        return 'CASE'
            . ' WHEN ' . $recordsTable . '.' . $this->recordCheckInColumn() . ' IS NULL THEN "absent"'
            . ' ELSE "present" END';
    }

    public function sessionSelectColumns(string $s, string $r): array
    {
        $memberColumn = $this->recordMemberCodeColumn() ?: $this->recordMemberIdColumn();
        $recordCount = $memberColumn !== null
            ? 'COUNT(DISTINCT ' . $r . '.' . $memberColumn . ')'
            : 'COUNT(*)';

        return [
            $s . '.id',
            DB::raw($s . '.' . $this->sessionDateColumn() . ' as session_date'),
            DB::raw($s . '.' . $this->sessionTitleColumn() . ' as session_title'),
            DB::raw($s . '.' . $this->sessionTypeColumn() . ' as session_type'),
            DB::raw($s . '.' . $this->sessionStatusColumn() . ' as session_status'),
            $s . '.start_time',
            DB::raw($this->sessionClosedSelectSql($s) . ' as is_closed'),
            DB::raw($this->hasColumn($s, 'service_id') ? $s . '.service_id' : 'NULL as service_id'),
            DB::raw('(SELECT ' . $recordCount . ' FROM ' . $r . ' WHERE ' . $r . '.' . $this->recordSessionIdColumn() . ' = ' . $s . '.id) as record_count'),
            DB::raw('(SELECT COUNT(*) FROM ' . $r . ' WHERE ' . $r . '.' . $this->recordSessionIdColumn() . ' = ' . $s . '.id) as raw_record_count'),
            DB::raw($this->presentCountSelectSql($s, $r) . ' as present_count'),
        ];
    }

    public function buildRecordArray($session, string $memberCode, ?array $member = null): array
    {
        $table = $this->recordsTable();
        $memberColumn = $this->recordMemberCodeColumn() ?: $this->recordMemberIdColumn();
        $data = [
            $this->recordSessionIdColumn() => $session->id,
        ];

        if ($memberColumn !== null) {
            $data[$memberColumn] = $memberCode;
        }
        if ($this->recordStatusColumn() !== null) {
            $data[$this->recordStatusColumn()] = 'present';
        }
        if ($this->hasColumn($table, $this->recordCheckInColumn())) {
            $data[$this->recordCheckInColumn()] = now();
        }
        if ($this->hasColumn($table, 'member_name_cache')) {
            $data['member_name_cache'] = $member['name'] ?? null;
        }
        if ($this->hasColumn($table, 'member_photo_cache')) {
            $data['member_photo_cache'] = $member['profile_photo_url'] ?? null;
        }
        if ($this->hasColumn($table, 'attendance_date')) {
            $data['attendance_date'] = $session->{$this->sessionDateColumn()} ?? $session->session_date ?? null;
        }
        if ($this->hasColumn($table, 'service_time')) {
            $data['service_time'] = $session->service_time ?? $session->{$this->sessionTitleColumn()} ?? null;
        }
        if ($this->hasColumn($table, 'created_at') && !array_key_exists('created_at', $data)) {
            $data['created_at'] = now();
        }
        if ($this->hasColumn($table, 'updated_at')) {
            $data['updated_at'] = now();
        }

        return $data;
    }

    public static function attendanceRate(int $present, int $total): int
    {
        return $total > 0 ? (int) round(($present / $total) * 100) : 0;
    }
}
