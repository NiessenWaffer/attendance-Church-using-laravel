<?php

namespace App\Services;

use App\Support\AttendanceSchema;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FollowupService
{
    public function missingMembers(int $days = 30): array
    {
        $since = Carbon::today()->subDays($days)->format('Y-m-d');
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $records = $schema->recordsTable();
        $sessions = $schema->sessionsTable();

        $attendedQuery = $db->table($records . ' as r')
            ->join($sessions . ' as e', 'e.id', '=', 'r.' . $schema->recordSessionIdColumn())
            ->where('e.' . $schema->sessionDateColumn(), '>=', $since)
            ->where('e.' . $schema->sessionDateColumn(), '<=', Carbon::today()->format('Y-m-d'))
            ->where('e.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
            ->whereRaw('(' . $schema->attendanceStatusSelectSql('r', 'e') . ') = ?', ['present'])
            ->distinct();

        $memberColumn = $schema->mode() === 'prod'
            ? $schema->recordMemberIdColumn()
            : $schema->recordMemberCodeColumn();

        if ($memberColumn === null) {
            return [];
        }

        $memberCol = 'r.' . $memberColumn;

        $attended = array_flip(
            $attendedQuery->pluck($memberCol)->all()
        );

        $followupNotes = Schema::hasTable('attendance_followups')
            ? $db->table('attendance_followups')->get()->keyBy('external_member_id')
            : collect();
        $lastAttendance = [];

        $lastRows = $db->table($records . ' as r')
            ->join($sessions . ' as e', 'e.id', '=', 'r.' . $schema->recordSessionIdColumn())
            ->select(
                DB::raw($memberCol . ' as member_key'),
                DB::raw('e.' . $schema->sessionDateColumn() . ' as attendance_date'),
                DB::raw($schema->attendanceStatusSelectSql('r', 'e') . ' as attendance_status')
            )
            ->where('e.' . $schema->sessionDateColumn(), '<=', Carbon::today()->format('Y-m-d'))
            ->where('e.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
            ->orderBy('e.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy('r.id', 'desc')
            ->get();

        foreach ($lastRows as $lastRow) {
            $key = (string) $lastRow->member_key;

            if ($key !== '' && !isset($lastAttendance[$key])) {
                $lastAttendance[$key] = $lastRow;
            }
        }

        $rows = [];

        foreach (app(MemberFetchService::class)->list() as $member) {
            $memberKey = $schema->mode() === 'prod'
                ? ($member['external_id'] ?? null)
                : ($member['member_code'] ?? null);

            if (
                ($member['membership_status'] ?? '') !== 'active'
                || $memberKey === null
                || isset($attended[$memberKey])
            ) {
                continue;
            }

            $memberKeyString = (string) $memberKey;
            $followupKey = (string) ($member['member_code'] ?? $memberKeyString);
            $followup = $followupNotes->get($followupKey);

            if ($followup && $followup->status === 'completed') {
                continue;
            }

            $last = $lastAttendance[$memberKeyString] ?? null;

            $rows[] = (object) array_merge($member, [
                'id'                     => null,
                'last_attendance_date'   => $last ? $last->attendance_date : null,
                'last_attendance_status' => $last ? $last->attendance_status : null,
                'followup_note'          => $followup ? $followup->note : '',
                'followup_status'        => $followup ? $followup->status : 'open',
                'followup_due_date'      => $followup ? $followup->due_date : null,
            ]);
        }

        return $rows;
    }
}
