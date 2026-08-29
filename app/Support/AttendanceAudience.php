<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AttendanceAudience
{
    private $schema;
    private $activeMembers;
    private $participantScope;

    public function __construct(AttendanceSchema $schema, array $members, bool $restrictParticipants = false)
    {
        $this->schema = $schema;
        $this->activeMembers = [];

        foreach ($members as $key => $member) {
            if (($member['membership_status'] ?? '') !== 'active') {
                continue;
            }

            $identifier = is_string($key) && $key !== '' ? $key : $this->memberIdentifier($member);
            if ($identifier !== '') {
                $this->activeMembers[$identifier] = $member;
            }
        }

        $this->participantScope = $restrictParticipants ? array_fill_keys(array_keys($this->activeMembers), true) : null;
    }

    public function enrich(Collection $sessions): Collection
    {
        if ($sessions->isEmpty()) {
            return $sessions;
        }

        $targetsByService = $this->targetsByService($sessions);
        $presentBySession = $this->presentBySession($sessions->pluck('id')->all());

        foreach ($sessions as $session) {
            $targets = $targetsByService[(int) ($session->service_id ?? 0)] ?? [];
            $eligible = $this->eligibleMembers($targets);
            $present = $presentBySession[(int) $session->id] ?? [];
            $expectedPresent = count(array_intersect_key($present, $eligible));

            // Compatibility counts represent distinct present participants, not raw scan rows.
            $session->record_count = count($present);
            $session->present_count = count($present);
            $session->participation_count = count($present);
            $session->expected_present_count = $expectedPresent;
            $session->guest_other_count = max(0, count($present) - $expectedPresent);
            $session->eligible_member_count = count($eligible);
            $session->attendance_rate = AttendanceSchema::attendanceRate($expectedPresent, count($eligible));
            $session->audience_type = $targets ? 'ministry' : 'general';
            $session->target_ministries = array_values($targets);
        }

        return $sessions;
    }

    private function memberIdentifier(array $member): string
    {
        $value = $this->schema->mode() === 'prod'
            ? ($member['external_id'] ?? '')
            : ($member['member_code'] ?? '');

        return (string) $value;
    }

    private function eligibleMembers(array $targets): array
    {
        if (!$targets) {
            return $this->activeMembers;
        }

        $targetSet = array_fill_keys($targets, true);

        return array_filter($this->activeMembers, function ($member) use ($targetSet) {
            foreach ($member['ministries'] ?? [] as $ministry) {
                if (isset($targetSet[(string) $ministry])) {
                    return true;
                }
            }

            return false;
        });
    }

    private function targetsByService(Collection $sessions): array
    {
        $serviceIds = $sessions->pluck('service_id')->filter()->unique()->values()->all();
        $servicesTable = (string) config('attendance.tables.' . $this->schema->mode() . '.services', 'services');

        if (!$serviceIds
            || !Schema::connection($this->schema->connection())->hasTable($servicesTable)
            || !$this->schema->hasColumn($servicesTable, 'ministries')) {
            return [];
        }

        $rows = $this->schema->db()->table($servicesTable)
            ->whereIn('id', $serviceIds)
            ->get(['id', 'ministries']);
        $result = [];

        foreach ($rows as $row) {
            $decoded = is_string($row->ministries) ? json_decode($row->ministries, true) : $row->ministries;
            $result[(int) $row->id] = is_array($decoded)
                ? array_values(array_unique(array_filter(array_map('strval', $decoded))))
                : [];
        }

        return $result;
    }

    private function presentBySession(array $sessionIds): array
    {
        $memberColumn = $this->schema->recordMemberCodeColumn() ?: $this->schema->recordMemberIdColumn();
        if ($memberColumn === null) {
            return [];
        }

        $records = $this->schema->recordsTable();
        $sessions = $this->schema->sessionsTable();
        $sessionColumn = $this->schema->recordSessionIdColumn();
        $rows = $this->schema->db()->table($records . ' as audience_records')
            ->join($sessions . ' as audience_sessions', 'audience_sessions.id', '=', 'audience_records.' . $sessionColumn)
            ->whereIn('audience_records.' . $sessionColumn, $sessionIds)
            ->whereRaw('(' . $this->schema->attendanceStatusSelectSql('audience_records', 'audience_sessions') . ') = ?', ['present'])
            ->select('audience_records.' . $sessionColumn . ' as session_id', 'audience_records.' . $memberColumn . ' as member_identifier')
            ->distinct()
            ->get();
        $result = [];

        foreach ($rows as $row) {
            $identifier = (string) $row->member_identifier;
            if ($identifier !== '' && ($this->participantScope === null || isset($this->participantScope[$identifier]))) {
                $result[(int) $row->session_id][$identifier] = true;
            }
        }

        return $result;
    }
}
