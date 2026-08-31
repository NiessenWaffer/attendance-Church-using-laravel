<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\AttendanceSchema;
use App\Support\AttendanceAudience;
use App\Support\CacheHelper;
use App\Services\DashboardReminderService;
use App\Services\FollowupService;
use App\Services\MemberFetchService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary()
    {
        $data = CacheHelper::remember('dashboard.summary', 60, function () {
            $schema = app(AttendanceSchema::class);
            $db = $schema->db();
            $sessions = $schema->sessionsTable();
            $records = $schema->recordsTable();

            $fetch = app(MemberFetchService::class);
            $external = $fetch->list();

            $membersTotal = count($external);
            $membersActive = count(array_filter($external, function ($m) {
                return ($m['membership_status'] ?? '') === 'active';
            }));
            $membersInactive = $membersTotal - $membersActive;

            $followups = app(FollowupService::class)->missingMembers();

            $sessionsTotal = $db->table($sessions)->count();
            $sessionsOpen = $db->table($sessions)
                ->whereIn($schema->sessionStatusColumn(), ['scheduled', 'active'])
                ->count();
            $sessionsClosed = $db->table($sessions)
                ->whereIn($schema->sessionStatusColumn(), ['completed', 'cancelled'])
                ->count();

            $latestSession = $db->table($sessions)
            ->select(...$schema->sessionSelectColumns($sessions, $records))
            ->orderBy($sessions . '.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy($sessions . '.start_time', 'desc')
            ->orderBy($sessions . '.id', 'desc')
            ->first();

        if ($latestSession) {
            (new AttendanceAudience($schema, $external))->enrich(collect([$latestSession]));
        }

            $recentSessions = $db->table($sessions)
            ->select(...$schema->sessionSelectColumns($sessions, $records))
            ->orderBy($sessions . '.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy($sessions . '.start_time', 'desc')
            ->orderBy($sessions . '.id', 'desc')
            ->limit(5)
            ->get();
            (new AttendanceAudience($schema, $external))->enrich($recentSessions);

         return [
            'members' => [
                'total'      => (int) $membersTotal,
                'active'     => (int) $membersActive,
                'inactive'   => (int) $membersInactive,
                'followup_30' => count($followups),
            ],
            'sessions' => [
                'total'  => (int) $sessionsTotal,
                'open'   => (int) $sessionsOpen,
                'closed' => (int) $sessionsClosed,
            ],
            'latest_session'   => $latestSession,
            'recent_sessions'  => $recentSessions,
            'followups'      => $followups,
            'analytics'      => $this->analytics($external),
        ];
        });

        return ApiResponse::success($data);
    }

    public function reminders(DashboardReminderService $service)
    {
        return ApiResponse::success($service->upcoming());
    }

    public function insights()
    {
        $data = CacheHelper::remember('dashboard.insights', 60, function () {
            $schema = app(AttendanceSchema::class);
            $db = $schema->db();
            $sessions = $schema->sessionsTable();
            $records = $schema->recordsTable();
            $sessionIdColumn = $schema->recordSessionIdColumn();
            $memberCol = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();
            $memberIdentityField = $schema->mode() === 'prod' ? 'external_id' : 'member_code';

            $fetch = app(MemberFetchService::class);
            $external = $fetch->list();

            $today = Carbon::today();

            // ── Top ministries by attendance (last 30 days) ──────────────────────
            $topMinistries = [];

            if ($memberCol !== null) {
                $since = $today->copy()->subDays(30)->format('Y-m-d');
                $recordCodes = $db->table($records . ' as r')
                    ->join($sessions . ' as s', 's.id', '=', 'r.' . $sessionIdColumn)
                    ->where('s.' . $schema->sessionDateColumn(), '>=', $since)
                    ->where('s.' . $schema->sessionDateColumn(), '<=', $today->format('Y-m-d'))
                    ->where('s.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
                    ->whereRaw('(' . $schema->attendanceStatusSelectSql('r', 's') . ') = ?', ['present'])
                    ->distinct()
                    ->pluck('r.' . $memberCol)
                    ->all();
                $recordCodeSet = array_flip($recordCodes);

                $ministryCounts = [];

                foreach ($external as $member) {
                    if (!isset($recordCodeSet[$member[$memberIdentityField] ?? null])) {
                        continue;
                    }

                    foreach ($member['ministries'] ?? [] as $name) {
                        $ministryCounts[$name] = ($ministryCounts[$name] ?? 0) + 1;
                    }
                }

                arsort($ministryCounts);

                foreach (array_slice($ministryCounts, 0, 5, true) as $name => $count) {
                    $topMinistries[] = [
                        'ministry'  => $name,
                        'attendees' => (int) $count,
                    ];
                }
            }

            // ── New members who attended this month ──────────────────────────────
            $newAttendees = [];

            if ($memberCol !== null) {
                $monthStart = $today->copy()->startOfMonth();
                $monthEnd = $today->copy()->endOfMonth();

                $monthlyAttended = $db->table($records . ' as r')
                    ->join($sessions . ' as s', 's.id', '=', 'r.' . $sessionIdColumn)
                    ->where('s.' . $schema->sessionDateColumn(), '>=', $monthStart->format('Y-m-d'))
                    ->where('s.' . $schema->sessionDateColumn(), '<=', min($monthEnd->format('Y-m-d'), $today->format('Y-m-d')))
                    ->where('s.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
                    ->whereRaw('(' . $schema->attendanceStatusSelectSql('r', 's') . ') = ?', ['present'])
                    ->distinct()
                    ->pluck('r.' . $memberCol)
                    ->all();
                $monthlySet = array_flip($monthlyAttended);

                foreach ($external as $member) {
                    if (!isset($monthlySet[$member[$memberIdentityField] ?? null])) {
                        continue;
                    }

                    $joined = $member['date_joined'] ?? null;

                    if (!$joined) {
                        continue;
                    }

                    try {
                        $joinedAt = Carbon::parse($joined);
                    } catch (\Throwable $e) {
                        continue;
                    }

                    if ($joinedAt->greaterThanOrEqualTo($monthStart)) {
                        $newAttendees[] = (object) $member;
                    }
                }

                usort($newAttendees, function ($a, $b) {
                    return strcmp((string) ($a->date_joined ?? ''), (string) ($b->date_joined ?? ''));
                });

                $newAttendees = array_slice($newAttendees, 0, 10);
            }

            // ── Members absent for the last N consecutive sessions ───────────────
            $absentStreaks = [];
            $currentTime = Carbon::now()->format('H:i:s');

            $recentSessions = $db->table($sessions)
                ->where($schema->sessionStatusColumn(), '!=', 'cancelled')
                ->where(function ($query) use ($schema, $today, $currentTime) {
                    $query->where($schema->sessionDateColumn(), '<', $today->format('Y-m-d'))
                        ->orWhere(function ($query) use ($schema, $today, $currentTime) {
                            $query->where($schema->sessionDateColumn(), $today->format('Y-m-d'))
                                ->where(function ($query) use ($currentTime) {
                                    $query->whereNull('start_time')
                                        ->orWhere('start_time', '<=', $currentTime);
                                });
                        });
                })
                ->orderBy($schema->sessionDateColumn(), 'desc')
                ->orderBy('start_time', 'desc')
                ->orderBy('id', 'desc')
                ->limit(8)
                ->get();

            if ($recentSessions->isNotEmpty() && $memberCol !== null) {
                $sessionIds = $recentSessions->pluck('id')->all();

                $attendedByCode = [];

                $rows = $db->table($records)
                    ->join($sessions . ' as streak_sessions', 'streak_sessions.id', '=', $records . '.' . $sessionIdColumn)
                    ->whereIn($sessionIdColumn, $sessionIds)
                    ->whereRaw('(' . $schema->attendanceStatusSelectSql($records, 'streak_sessions') . ') = ?', ['present'])
                    ->select($records . '.' . $sessionIdColumn, $records . '.' . $memberCol)
                    ->get();

                foreach ($rows as $row) {
                    $code = $row->{$memberCol};
                    $attendedByCode[$code][(int) $row->{$sessionIdColumn}] = true;
                }

                foreach ($external as $member) {
                    if (($member['membership_status'] ?? '') !== 'active') {
                        continue;
                    }

                    $code = $member['member_code'];
                    $missed = 0;

                    foreach ($recentSessions as $session) {
                        if (isset($attendedByCode[$code][(int) $session->id])) {
                            break;
                        }

                        $missed++;
                    }

                    if ($missed >= 3) {
                        $absentStreaks[] = (object) array_merge($member, [
                            'missed_sessions' => $missed,
                        ]);
                    }
                }

                usort($absentStreaks, function ($a, $b) {
                    return (int) $b->missed_sessions <=> (int) $a->missed_sessions;
                });

                $absentStreaks = array_slice($absentStreaks, 0, 10);
            }

            return [
                'top_ministries' => $topMinistries,
                'new_attendees'  => $newAttendees,
                'absent_streaks' => $absentStreaks,
            ];
        });

        return ApiResponse::success($data);
    }

    private function analytics(array $external): array
    {
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessions = $schema->sessionsTable();
        $records = $schema->recordsTable();
        $overallRate = null;
        $recordTotal = $db->table($records)->count();
        $eligibleSessions = $db->table($sessions)
            ->select(...$schema->sessionSelectColumns($sessions, $records))
            ->where($schema->sessionStatusColumn(), '!=', 'cancelled')
            ->where(function ($query) use ($schema) {
                $query->where($schema->sessionDateColumn(), '<', now()->format('Y-m-d'))
                    ->orWhere(function ($query) use ($schema) {
                        $query->where($schema->sessionDateColumn(), now()->format('Y-m-d'))
                            ->where(function ($query) {
                                $query->whereNull('start_time')
                                    ->orWhere('start_time', '<=', now()->format('H:i:s'));
                            });
                    });
            })
            ->get();
        (new AttendanceAudience($schema, $external))->enrich($eligibleSessions);
        $audience = new AttendanceAudience($schema, $external);
        $memberDays = $audience->memberDayTotals($eligibleSessions);
        $opportunityTotal = $memberDays['opportunities'];
        $expectedPresentTotal = $memberDays['expected_present'];
        $serviceParticipations = (int) $eligibleSessions->sum('participation_count');
        $guestOtherParticipations = (int) $eligibleSessions->sum('guest_other_count');
        $uniqueAttendeeDays = $memberDays['unique_attendee_days'];

        if ($eligibleSessions->isNotEmpty()) {
            $overallRate = AttendanceSchema::attendanceRate($expectedPresentTotal, $opportunityTotal);
        }

        $trend = $db->table($sessions)
            ->select(
                DB::raw($sessions . '.' . $schema->sessionDateColumn() . ' as session_date'),
                DB::raw($sessions . '.' . $schema->sessionTitleColumn() . ' as session_title'),
                $sessions . '.id',
                DB::raw($schema->hasColumn($sessions, 'service_id') ? $sessions . '.service_id' : 'NULL as service_id'),
                DB::raw('(SELECT COUNT(*) FROM ' . $records . ' WHERE ' . $records . '.' . $schema->recordSessionIdColumn() . ' = ' . $sessions . '.id) as record_count'),
                DB::raw($schema->presentCountSelectSql($sessions, $records) . ' as present_count')
            )
            ->where($sessions . '.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
            ->where(function ($query) use ($sessions, $schema) {
                $query->where($sessions . '.' . $schema->sessionDateColumn(), '<', now()->format('Y-m-d'))
                    ->orWhere(function ($query) use ($sessions, $schema) {
                        $query->where($sessions . '.' . $schema->sessionDateColumn(), now()->format('Y-m-d'))
                            ->where(function ($query) use ($sessions) {
                                $query->whereNull($sessions . '.start_time')
                                    ->orWhere($sessions . '.start_time', '<=', now()->format('H:i:s'));
                            });
                    });
            })
            ->whereExists(function ($query) {
                $schema = app(AttendanceSchema::class);
                $query->selectRaw('1')
                    ->from($schema->recordsTable())
                    ->whereColumn($schema->recordsTable() . '.' . $schema->recordSessionIdColumn(), $schema->sessionsTable() . '.id');
            })
            ->orderBy($sessions . '.' . $schema->sessionDateColumn(), 'desc')
            ->orderBy($sessions . '.id', 'desc')
            ->limit(7)
            ->get()
            ->reverse()
            ->values();
        (new AttendanceAudience($schema, $external))->enrich($trend);

        $statuses = ['present', 'absent', 'excused'];
        $statusBreakdown = [];

        $statusMemberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();
        $statusIdentity = $statusMemberColumn ?: 'id';
        $statusRows = $db->table($records . ' as status_records')
            ->join($sessions . ' as status_sessions', 'status_sessions.id', '=', 'status_records.' . $schema->recordSessionIdColumn())
            ->select(
                DB::raw('status_sessions.' . $schema->sessionDateColumn() . ' as attendance_date'),
                DB::raw('status_records.' . $statusIdentity . ' as member_identifier'),
                DB::raw($schema->attendanceStatusSelectSql('status_records', 'status_sessions') . ' as attendance_status')
            )
            ->where('status_sessions.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
            ->where(function ($query) use ($schema) {
                $query->where('status_sessions.' . $schema->sessionDateColumn(), '<', now()->format('Y-m-d'))
                    ->orWhere(function ($query) use ($schema) {
                        $query->where('status_sessions.' . $schema->sessionDateColumn(), now()->format('Y-m-d'))
                            ->where(function ($query) {
                                $query->whereNull('status_sessions.start_time')
                                    ->orWhere('status_sessions.start_time', '<=', now()->format('H:i:s'));
                            });
                    });
            })
            ->distinct()->get();
        $statusPriority = ['present' => 3, 'excused' => 2, 'absent' => 1];
        $statusByMemberDay = [];
        foreach ($statusRows as $row) {
            if (!isset($statusPriority[$row->attendance_status])) {
                continue;
            }
            $key = (string) $row->attendance_date . ':' . (string) $row->member_identifier;
            if (!isset($statusByMemberDay[$key])
                || $statusPriority[$row->attendance_status] > $statusPriority[$statusByMemberDay[$key]]) {
                $statusByMemberDay[$key] = $row->attendance_status;
            }
        }
        $statusRow = array_fill_keys($statuses, 0);
        foreach ($statusByMemberDay as $status) {
            $statusRow[$status]++;
        }
        $recordTotal = count($statusByMemberDay);

        foreach ($statuses as $status) {
            $statusBreakdown[] = [
                'status' => $status,
                'count'  => (int) ($statusRow[$status] ?? 0),
            ];
        }

        $typeRow = $db->table($sessions)
            ->select($schema->sessionTypeColumn(), DB::raw('COUNT(*) as total'))
            ->whereNotNull($schema->sessionTypeColumn())
            ->where($schema->sessionTypeColumn(), '!=', '')
            ->groupBy($schema->sessionTypeColumn())
            ->orderByDesc('total')
            ->pluck('total', $schema->sessionTypeColumn())
            ->all();

        $typeBreakdown = [];

        foreach ($typeRow as $type => $count) {
            $typeBreakdown[] = [
                'session_type' => $type,
                'count'      => (int) $count,
            ];
        }

        $monthStart = Carbon::today()->startOfMonth()->subMonths(5);

        $memberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();
        $monthlyRows = $memberColumn === null ? collect() : $db->table($records)
            ->select(
                $records . '.' . $schema->recordSessionIdColumn() . ' as session_id',
                $records . '.' . $memberColumn . ' as member_identifier',
                $sessions . '.' . $schema->sessionDateColumn() . ' as session_date'
            )
            ->join($sessions, $sessions . '.id', '=', $records . '.' . $schema->recordSessionIdColumn())
            ->where($sessions . '.' . $schema->sessionDateColumn(), '>=', $monthStart->format('Y-m-d'))
            ->where($sessions . '.' . $schema->sessionDateColumn(), '<=', now()->format('Y-m-d'))
            ->where($sessions . '.' . $schema->sessionStatusColumn(), '!=', 'cancelled')
            ->where(function ($query) use ($sessions, $schema) {
                $query->where($sessions . '.' . $schema->sessionDateColumn(), '<', now()->format('Y-m-d'))
                    ->orWhere(function ($query) use ($sessions, $schema) {
                        $query->where($sessions . '.' . $schema->sessionDateColumn(), now()->format('Y-m-d'))
                            ->where(function ($query) use ($sessions) {
                                $query->whereNull($sessions . '.start_time')
                                    ->orWhere($sessions . '.start_time', '<=', now()->format('H:i:s'));
                            });
                    });
            })
            ->whereRaw('(' . $schema->attendanceStatusSelectSql($records, $sessions) . ') = ?', ['present'])
            ->distinct()
            ->get();
        $monthlyParticipations = [];
        $monthlyUniqueDays = [];

        foreach ($monthlyRows as $row) {
            $month = substr((string) $row->session_date, 0, 7);
            $member = (string) $row->member_identifier;
            $monthlyParticipations[$month][(int) $row->session_id . ':' . $member] = true;
            $monthlyUniqueDays[$month][(string) $row->session_date . ':' . $member] = true;
        }

        $monthly = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::today()->startOfMonth()->subMonths($i);
            $key = $month->format('Y-m');
            $participations = isset($monthlyParticipations[$key]) ? count($monthlyParticipations[$key]) : 0;
            $uniqueDays = isset($monthlyUniqueDays[$key]) ? count($monthlyUniqueDays[$key]) : 0;

            $monthly[] = [
                'month'   => $month->format('Y-m'),
                'label'   => $month->format('M'),
                'present' => $uniqueDays,
                'service_participations' => $participations,
                'unique_attendee_days' => $uniqueDays,
            ];
        }

        return [
            'overall_rate'     => $overallRate,
            'rate_basis'       => 'unique_eligible_member_days',
            'record_total'     => (int) $recordTotal,
            'opportunity_total'=> $opportunityTotal,
            'expected_participations' => $expectedPresentTotal,
            'service_participations' => $serviceParticipations,
            'guest_other_participations' => $guestOtherParticipations,
            'unique_attendee_days' => (int) $uniqueAttendeeDays,
            'trend'            => $trend,
            'status_breakdown' => $statusBreakdown,
            'type_breakdown'   => $typeBreakdown,
            'monthly'          => $monthly,
        ];
    }
}
