<?php

namespace App\Http\Controllers;

use App\Services\MemberFetchService;
use App\Support\AttendanceQuery;
use App\Support\AttendanceAudience;
use App\Support\ApiResponse;
use App\Support\AttendanceSchema;
use App\Support\AuditLogger;
use App\Support\PdfExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary(Request $request)
    {
        $this->validateFilters($request);

        $fetch = app(MemberFetchService::class);
        $schema = app(AttendanceSchema::class);
        $recordsTable = $schema->recordsTable();
        $sessionsTable = $schema->sessionsTable();
        $base = AttendanceQuery::forHistory($request);

        $ministry = trim((string) $request->input('ministry', ''));
        $members = $this->filteredActiveMembers($fetch, $ministry, $request);

        if ($this->hasMemberScope($request, $ministry)) {
            $memberColumn = $schema->mode() === 'prod'
                ? $schema->recordMemberIdColumn()
                : $schema->recordMemberCodeColumn();

            if ($memberColumn !== null) {
                $base->whereIn($recordsTable . '.' . $memberColumn, array_keys($members));
            }
        }

        $statuses = ['present' => 0, 'absent' => 0, 'excused' => 0];
        $statusMemberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();
        $statusIdentityColumn = $statusMemberColumn !== null
            ? $recordsTable . '.' . $statusMemberColumn
            : $recordsTable . '.id';
        $statusRows = (clone $base)
            ->select(
                DB::raw($schema->attendanceStatusSelectSql($recordsTable, $sessionsTable) . ' as attendance_status'),
                $recordsTable . '.' . $schema->recordSessionIdColumn(),
                $statusIdentityColumn
            )
            ->distinct()
            ->get();
        foreach ($statusRows as $row) {
            if (array_key_exists($row->attendance_status, $statuses)) {
                $statuses[$row->attendance_status]++;
            }
        }

        $total = array_sum($statuses);
        $bySession = $this->eligibleSessions($request, $schema);

        $audience = new AttendanceAudience($schema, $members, $this->hasMemberScope($request, $ministry));
        $audience->enrich($bySession);
        $rateDenominator = (int) $bySession->sum('eligible_member_count');
        $expectedPresent = (int) $bySession->sum('expected_present_count');
        $serviceParticipations = (int) $bySession->sum('participation_count');
        $guestOtherParticipations = (int) $bySession->sum('guest_other_count');
        $rate = AttendanceSchema::attendanceRate($expectedPresent, $rateDenominator);
        $strongestSessions = $bySession->sort(function ($a, $b) {
            return [(int) $b->attendance_rate, (int) $b->expected_present_count, (string) $b->session_date]
                <=> [(int) $a->attendance_rate, (int) $a->expected_present_count, (string) $a->session_date];
        })->take(3)->values();
        $bySession = $bySession->take(30)->values();

        return ApiResponse::success([
            'statuses'   => $statuses,
            'total'      => $total,
            'rate'       => $rate,
            'rate_denominator' => $rateDenominator,
            'rate_basis' => 'target_audience_member_opportunities_in_reported_sessions',
            'expected_participations' => $expectedPresent,
            'service_participations' => $serviceParticipations,
            'guest_other_participations' => $guestOtherParticipations,
            'by_session' => $bySession,
            'strongest_sessions' => $strongestSessions,
            'sunday'     => $this->buildSundaySummary($request, $members),
            'general'    => $this->buildSundaySummary($request, $members, false),
            'youth'      => $this->buildYouthSummary($request, $members),
            'ministries' => $fetch->ministries(),
        ]);
    }

    public function pdf(Request $request)
    {
        $this->validateFilters($request);

        $fetch = app(MemberFetchService::class);
        $schema = app(AttendanceSchema::class);
        $recordsTable = $schema->recordsTable();
        $sessionsTable = $schema->sessionsTable();

        $ministry = trim((string) $request->input('ministry', ''));
        $members = $this->filteredActiveMembers($fetch, $ministry, $request);

        $sunday = $this->buildSundaySummary($request, $members);
        $youth = $this->buildYouthSummary($request, $members);

        $memberColumn = null;
        if ($this->hasMemberScope($request, $ministry)) {
            $memberColumn = $schema->mode() === 'prod'
                ? $schema->recordMemberIdColumn()
                : $schema->recordMemberCodeColumn();
        }

        $bySession = $this->eligibleSessions($request, $schema);

        (new AttendanceAudience($schema, $members, $this->hasMemberScope($request, $ministry)))->enrich($bySession);

        $query = AttendanceQuery::forHistory($request);
        if ($this->hasMemberScope($request, $ministry) && $memberColumn !== null) {
            $query->whereIn($recordsTable . '.' . $memberColumn, array_keys($members));
        }

        $records = $query
            ->orderBy($sessionsTable . '.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy($recordsTable . '.id', 'desc')
            ->get();

        $fetch->hydrateRecords($records);

        $total = $records->count();

        AuditLogger::log('report.pdf', 'report', "Downloaded attendance report PDF ({$total} records).");

        return PdfExporter::download('reports.attendance', [
            'sunday'    => $sunday,
            'youth'     => $youth,
            'bySession' => $bySession,
            'records'   => $records,
            'dateFrom'  => $request->input('date_from'),
            'dateTo'    => $request->input('date_to'),
            'ministry'  => $ministry,
            'general'   => $this->buildSundaySummary($request, $members, false),
        ], 'attendance-report-' . now()->format('Ymd-His') . '.pdf', 'portrait');
    }

    private function filteredActiveMembers(MemberFetchService $fetch, string $ministry, Request $request): array
    {
        $members = [];

        foreach ($fetch->list() as $member) {
            if (($member['membership_status'] ?? '') !== 'active') {
                continue;
            }

            if ($ministry !== '' && !in_array($ministry, $member['ministries'] ?? [], true)) {
                continue;
            }

            $memberCode = trim((string) $request->input('member_code', ''));
            if ($memberCode !== '' && (string) ($member['member_code'] ?? '') !== $memberCode) {
                continue;
            }

            $memberId = $request->input('member_id');
            if ($memberId !== null && $memberId !== '' && (string) ($member['external_id'] ?? '') !== (string) $memberId) {
                continue;
            }

            $schema = app(AttendanceSchema::class);
            $code = $schema->mode() === 'prod'
                ? (string) ($member['external_id'] ?? '')
                : (string) ($member['member_code'] ?? '');

            if ($code === '') {
                continue;
            }

            $members[$code] = $member;
        }

        return $members;
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'session_id' => ['nullable', 'integer', 'min:1'],
            'attendance_status' => ['nullable', 'string', 'in:present,absent,excused'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'member_id' => ['nullable', 'string', 'max:150'],
            'member_code' => ['nullable', 'string', 'max:150'],
            'ministry' => ['nullable', 'string', 'max:150'],
            'service_id' => ['nullable', 'integer', 'min:1'],
            'schedule_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($request->filled('date_from') && $request->filled('date_to')
            && $request->input('date_to') < $request->input('date_from')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'date_to' => 'The end date must be on or after the start date.',
            ]);
        }

        if ($request->filled('member_code')) {
            $request->merge(['member_code' => strtoupper(trim((string) $request->input('member_code')))]);
        }
    }

    private function hasMemberScope(Request $request, string $ministry): bool
    {
        return $ministry !== ''
            || trim((string) $request->input('member_code', '')) !== ''
            || trim((string) $request->input('member_id', '')) !== '';
    }

    private function eligibleSessions(Request $request, AttendanceSchema $schema)
    {
        $sessionsTable = $schema->sessionsTable();
        $query = $schema->db()->table($sessionsTable)
            ->select(...$schema->sessionSelectColumns($sessionsTable, $schema->recordsTable()))
            ->where($sessionsTable . '.' . $schema->sessionStatusColumn(), '!=', 'cancelled');
        $this->excludeFutureSessions($query, $sessionsTable, $schema);

        if ($request->filled('session_id')) {
            $query->where($sessionsTable . '.id', $request->input('session_id'));
        }

        $serviceId = $request->input('service_id', $request->input('schedule_id'));
        if ($serviceId !== null && $serviceId !== '' && $schema->hasColumn($sessionsTable, 'service_id')) {
            $query->where($sessionsTable . '.service_id', $serviceId);
        }

        if ($request->filled('date_from')) {
            $query->where($sessionsTable . '.' . $schema->sessionDateColumn(), '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where($sessionsTable . '.' . $schema->sessionDateColumn(), '<=', $request->input('date_to'));
        }

        return $query
            ->orderByDesc($sessionsTable . '.' . $schema->sessionDateColumn())
            ->orderByDesc($sessionsTable . '.id')
            ->get();
    }

    private function excludeFutureSessions($query, string $sessionsTable, AttendanceSchema $schema): void
    {
        $dateColumn = $sessionsTable . '.' . $schema->sessionDateColumn();
        $today = now()->format('Y-m-d');
        $time = now()->format('H:i:s');

        $query->where(function ($query) use ($dateColumn, $sessionsTable, $today, $time) {
            $query->where($dateColumn, '<', $today)
                ->orWhere(function ($query) use ($dateColumn, $sessionsTable, $today, $time) {
                    $query->where($dateColumn, $today)
                        ->where(function ($query) use ($sessionsTable, $time) {
                            $query->whereNull($sessionsTable . '.start_time')
                                ->orWhere($sessionsTable . '.start_time', '<=', $time);
                        });
                });
        });
    }

    private function buildSundaySummary(Request $request, array $members, bool $sundayOnly = true): array
    {
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();
        $recordsTable = $schema->recordsTable();
        $dateColumn = $schema->sessionDateColumn();
        $sessionIdColumn = $schema->recordSessionIdColumn();
        $memberCodeColumn = $schema->mode() === 'prod'
            ? $schema->recordMemberIdColumn()
            : $schema->recordMemberCodeColumn();

        if ($memberCodeColumn === null) {
            return ['rows' => [], 'overview' => ['active_members' => count($members), 'sundays' => 0, 'dates' => 0, 'present' => 0, 'service_participations' => 0, 'missed' => 0, 'rate' => 0]];
        }

        $sessionsQuery = $db->table($sessionsTable)
            ->select('id', DB::raw($sessionsTable . '.' . $dateColumn . ' as session_date'))
            ->where($sessionsTable . '.' . $schema->sessionStatusColumn(), '!=', 'cancelled');
        if ($sundayOnly) {
            $sundaySql = $schema->db()->getDriverName() === 'sqlite'
                ? "strftime('%w', " . $sessionsTable . '.' . $dateColumn . ") = '0'"
                : 'DAYOFWEEK(' . $sessionsTable . '.' . $dateColumn . ') = 1';
            $sessionsQuery->whereRaw($sundaySql);
        }
        $this->excludeFutureSessions($sessionsQuery, $sessionsTable, $schema);

        $sessionId = $request->input('session_id');
        if ($sessionId !== null && $sessionId !== '') {
            $sessionsQuery->where($sessionsTable . '.id', $sessionId);
        }

        $serviceId = $request->input('service_id', $request->input('schedule_id'));
        if ($serviceId !== null && $serviceId !== '' && $schema->hasColumn($sessionsTable, 'service_id')) {
            $sessionsQuery->where($sessionsTable . '.service_id', $serviceId);
        }

        $dateFrom = $request->input('date_from');
        if ($dateFrom !== null && $dateFrom !== '') {
            $sessionsQuery->where($sessionsTable . '.' . $dateColumn, '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to');
        if ($dateTo !== null && $dateTo !== '') {
            $sessionsQuery->where($sessionsTable . '.' . $dateColumn, '<=', $dateTo);
        }

        $sessions = $sessionsQuery
            ->orderBy($sessionsTable . '.' . $dateColumn, 'desc')
            ->get();

        $sessionDateById = [];
        $dates = [];

        foreach ($sessions as $session) {
            $sessionDateById[(int) $session->id] = $session->session_date;
            $dates[$session->session_date] = true;
        }

        if (empty($sessionDateById)) {
            return ['rows' => [], 'overview' => ['active_members' => count($members), 'sundays' => 0, 'dates' => 0, 'present' => 0, 'service_participations' => 0, 'missed' => 0, 'rate' => 0]];
        }

        $recordsQuery = $db->table($recordsTable)
            ->join($sessionsTable, $sessionsTable . '.id', '=', $recordsTable . '.' . $sessionIdColumn)
            ->select($recordsTable . '.' . $sessionIdColumn . ' as session_id', $recordsTable . '.' . $memberCodeColumn . ' as member_code')
            ->whereIn($recordsTable . '.' . $sessionIdColumn, array_keys($sessionDateById))
            ->whereRaw('(' . $schema->attendanceStatusSelectSql($recordsTable, $sessionsTable) . ') = ?', ['present']);

        $records = $recordsQuery->get();

        $presentByDate = [];
        $participationsByDate = [];

        foreach ($records as $record) {
            $code = (string) $record->member_code;

            if ($code === '' || !isset($members[$code])) {
                continue;
            }

            $date = $sessionDateById[(int) $record->session_id] ?? null;

            if ($date === null) {
                continue;
            }

            $presentByDate[$date][$code] = true;
            $participationsByDate[$date][(int) $record->session_id . ':' . $code] = true;
        }

        $rows = [];
        $activeMembers = count($members);
        $presentTotal = 0;
        $missedTotal = 0;

        $dateList = array_keys($dates);
        rsort($dateList);

        foreach ($dateList as $date) {
            $present = isset($presentByDate[$date]) ? count($presentByDate[$date]) : 0;
            $missed = max(0, $activeMembers - $present);
            $presentTotal += $present;
            $missedTotal += $missed;

            $rows[] = [
                'date' => $date,
                'present' => $present,
                'unique_attendees' => $present,
                'service_participations' => isset($participationsByDate[$date]) ? count($participationsByDate[$date]) : 0,
                'missed' => $missed,
                'rate' => AttendanceSchema::attendanceRate($present, $activeMembers),
            ];
        }

        return [
            'rows' => $rows,
            'overview' => [
                'active_members' => $activeMembers,
                'sundays' => count($dateList),
                'dates' => count($dateList),
                'present' => $presentTotal,
                'unique_attendee_days' => $presentTotal,
                'service_participations' => array_sum(array_map('count', $participationsByDate)),
                'missed' => $missedTotal,
                'rate' => AttendanceSchema::attendanceRate($presentTotal, $presentTotal + $missedTotal),
            ],
        ];
    }

    private function buildYouthSummary(Request $request, array $members): array
    {
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();
        $recordsTable = $schema->recordsTable();
        $dateColumn = $schema->sessionDateColumn();
        $typeColumn = $schema->sessionTypeColumn();
        $titleColumn = $schema->sessionTitleColumn();
        $sessionIdColumn = $schema->recordSessionIdColumn();
        $memberCodeColumn = $schema->mode() === 'prod'
            ? $schema->recordMemberIdColumn()
            : $schema->recordMemberCodeColumn();

        if ($memberCodeColumn === null) {
            return ['rows' => [], 'overview' => ['youth_members' => 0, 'attended_youth' => 0, 'worship_only' => 0, 'no_sunday_scan' => 0]];
        }

        $youthMembers = [];

        foreach ($members as $code => $member) {
            foreach ($member['ministries'] ?? [] as $ministry) {
                if ($this->isYouthMember((string) $ministry)) {
                    $youthMembers[$code] = $member;
                    break;
                }
            }
        }

        if (empty($youthMembers)) {
            return ['rows' => [], 'overview' => ['youth_members' => 0, 'attended_youth' => 0, 'worship_only' => 0, 'no_sunday_scan' => 0]];
        }

        $sessionsQuery = $db->table($sessionsTable)
            ->select(
                'id',
                DB::raw($sessionsTable . '.' . $dateColumn . ' as session_date'),
                DB::raw($sessionsTable . '.' . $typeColumn . ' as session_type'),
                DB::raw($sessionsTable . '.' . $titleColumn . ' as session_title')
            )
            ->whereRaw($schema->db()->getDriverName() === 'sqlite'
                ? "strftime('%w', " . $sessionsTable . '.' . $dateColumn . ") = '0'"
                : 'DAYOFWEEK(' . $sessionsTable . '.' . $dateColumn . ') = 1')
            ->where($sessionsTable . '.' . $schema->sessionStatusColumn(), '!=', 'cancelled');
        $this->excludeFutureSessions($sessionsQuery, $sessionsTable, $schema);

        $sessionId = $request->input('session_id');
        if ($sessionId !== null && $sessionId !== '') {
            $sessionsQuery->where($sessionsTable . '.id', $sessionId);
        }

        $serviceId = $request->input('service_id', $request->input('schedule_id'));
        if ($serviceId !== null && $serviceId !== '' && $schema->hasColumn($sessionsTable, 'service_id')) {
            $sessionsQuery->where($sessionsTable . '.service_id', $serviceId);
        }

        $dateFrom = $request->input('date_from');
        if ($dateFrom !== null && $dateFrom !== '') {
            $sessionsQuery->where($sessionsTable . '.' . $dateColumn, '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to');
        if ($dateTo !== null && $dateTo !== '') {
            $sessionsQuery->where($sessionsTable . '.' . $dateColumn, '<=', $dateTo);
        }

        $sessions = $sessionsQuery
            ->orderBy($sessionsTable . '.' . $dateColumn, 'desc')
            ->get();

        $sessionMeta = [];
        $dates = [];

        foreach ($sessions as $session) {
            $sessionMeta[(int) $session->id] = [
                'date' => $session->session_date,
                'is_youth' => $this->isYouthSession($session->session_type ?? '', $session->session_title ?? ''),
            ];
            $dates[$session->session_date] = true;
        }

        if (empty($sessionMeta)) {
            return ['rows' => [], 'overview' => ['youth_members' => count($youthMembers), 'attended_youth' => 0, 'worship_only' => 0, 'no_sunday_scan' => 0]];
        }

        $recordsQuery = $db->table($recordsTable)
            ->join($sessionsTable, $sessionsTable . '.id', '=', $recordsTable . '.' . $sessionIdColumn)
            ->select($recordsTable . '.' . $sessionIdColumn . ' as session_id', $recordsTable . '.' . $memberCodeColumn . ' as member_code')
            ->whereIn($recordsTable . '.' . $sessionIdColumn, array_keys($sessionMeta))
            ->whereRaw('(' . $schema->attendanceStatusSelectSql($recordsTable, $sessionsTable) . ') = ?', ['present']);

        $records = $recordsQuery->get();

        $attendance = [];

        foreach ($records as $record) {
            $code = (string) $record->member_code;

            if ($code === '' || !isset($youthMembers[$code])) {
                continue;
            }

            $meta = $sessionMeta[(int) $record->session_id] ?? null;

            if ($meta === null) {
                continue;
            }

            $attendance[$meta['date']][$code] = [
                'attended_youth' => ($attendance[$meta['date']][$code]['attended_youth'] ?? false) || $meta['is_youth'],
                'attended_any' => true,
            ];
        }

        $rows = [];
        $overview = ['youth_members' => count($youthMembers), 'attended_youth' => 0, 'worship_only' => 0, 'no_sunday_scan' => 0];
        $dateList = array_keys($dates);
        rsort($dateList);

        foreach ($dateList as $date) {
            $attendedYouth = 0;
            $worshipOnly = 0;
            $noScan = 0;

            foreach ($youthMembers as $code => $member) {
                $state = $attendance[$date][$code] ?? null;

                if (!$state) {
                    $noScan++;
                    continue;
                }

                if (!empty($state['attended_youth'])) {
                    $attendedYouth++;
                    continue;
                }

                $worshipOnly++;
            }

            $overview['attended_youth'] += $attendedYouth;
            $overview['worship_only'] += $worshipOnly;
            $overview['no_sunday_scan'] += $noScan;

            $rows[] = [
                'date' => $date,
                'attended_youth' => $attendedYouth,
                'worship_only' => $worshipOnly,
                'no_sunday_scan' => $noScan,
            ];
        }

        return ['rows' => $rows, 'overview' => $overview];
    }

    private function isYouthSession(string $type, string $title): bool
    {
        return stripos($type, 'youth') !== false || stripos($title, 'youth') !== false;
    }

    private function isYouthMember(string $ministry): bool
    {
        $normalized = strtolower(trim($ministry));

        if ($normalized === '') {
            return false;
        }

        $knownYouthMinistries = [
            'youth alive ministry',
            'youth ministry',
            'youth alive',
        ];

        if (in_array($normalized, $knownYouthMinistries, true)) {
            return true;
        }

        return strpos($normalized, 'youth') !== false;
    }
}
