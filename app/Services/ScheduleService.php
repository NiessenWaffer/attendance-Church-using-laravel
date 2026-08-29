<?php

namespace App\Services;

use App\Support\AttendanceSchema;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ScheduleService
{
    public const DEFAULT_SCHEDULES = [
        [
            'schedule_name'   => 'Sunday Worship',
            'session_title'     => 'Sunday Worship Service',
            'session_type'      => 'worship',
            'recurrence_type' => 'weekly',
            'day_of_week'     => 0,
            'day_of_month'    => null,
            'start_date'      => '2026-01-01',
            'end_date'        => null,
            'start_time'      => '08:00:00',
            'end_time'        => '12:00:00',
            'is_default'      => 1,
        ],
        [
            'schedule_name'   => 'Midweek Prayer',
            'session_title'     => 'Midweek Prayer Meeting',
            'session_type'      => 'prayer',
            'recurrence_type' => 'weekly',
            'day_of_week'     => 3,
            'day_of_month'    => null,
            'start_date'      => '2026-01-01',
            'end_date'        => null,
            'start_time'      => '18:30:00',
            'end_time'        => '20:00:00',
            'is_default'      => 1,
        ],
        [
            'schedule_name'   => 'Youth Fellowship',
            'session_title'     => 'Youth Fellowship',
            'session_type'      => 'youth',
            'recurrence_type' => 'weekly',
            'day_of_week'     => 6,
            'day_of_month'    => null,
            'start_date'      => '2026-01-01',
            'end_date'        => null,
            'start_time'      => '17:00:00',
            'end_time'        => '19:00:00',
            'is_default'      => 1,
        ],
    ];

    public function ensureDefaults()
    {
        $existing = DB::table('services')
            ->where('is_default', 1)
            ->get()
            ->keyBy('name');

        foreach (self::DEFAULT_SCHEDULES as $schedule) {
            $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $scheduleDay = $weekdays[$schedule['day_of_week']] ?? 'Sunday';

            if ($existing->has($schedule['schedule_name'])) {
                $existingSchedule = $existing->get($schedule['schedule_name']);
                $updates = [
                    'schedule_day' => $scheduleDay,
                    'service_type' => $schedule['session_type'],
                    'recurrence_type' => $schedule['recurrence_type'],
                    'day_of_week' => $schedule['day_of_week'],
                    'day_of_month' => $schedule['day_of_month'],
                    'specific_date' => null,
                ];

                if (empty($existingSchedule->end_time)) {
                    $updates['end_time'] = $schedule['end_time'];
                }

                DB::table('services')
                    ->where('name', $schedule['schedule_name'])
                    ->where('is_default', 1)
                    ->update($updates);

                continue;
            }

            DB::table('services')->insert([
                'name'           => $schedule['schedule_name'],
                'description'    => $schedule['session_title'],
                'schedule_day'   => $scheduleDay,
                'start_time'     => $schedule['start_time'],
                'end_time'       => $schedule['end_time'],
                'service_type'   => $schedule['session_type'],
                'recurrence_type'=> $schedule['recurrence_type'],
                'day_of_week'    => $schedule['day_of_week'],
                'day_of_month'   => $schedule['day_of_month'],
                'start_date'     => $schedule['start_date'],
                'end_date'       => $schedule['end_date'],
                'is_default'     => 1,
                'is_active'  => 1,
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function isDueOn(array $schedule, Carbon $date): bool
    {
        $dateStr = $date->format('Y-m-d');

        if (!empty($schedule['start_date']) && $dateStr < $schedule['start_date']) {
            return false;
        }

        if (!empty($schedule['end_date']) && $dateStr > $schedule['end_date']) {
            return false;
        }

        switch ($schedule['recurrence_type']) {
            case 'daily':
                return true;
            case 'weekly':
                return (int) $schedule['day_of_week'] === $date->dayOfWeek;
            case 'monthly':
                return (int) $schedule['day_of_month'] === (int) $date->format('j');
            case 'once':
                $onceDate = $schedule['specific_date'] ?? $schedule['start_date'] ?? null;

                return $onceDate !== null && $dateStr === $onceDate;
            default:
                return false;
        }
    }

    public function generateForDate($date = null, $serviceId = null): int
    {
        $date = $date ? Carbon::parse($date) : Carbon::today();

        $this->ensureDefaults();
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();

        $schedulesQuery = DB::table('services')->where('is_active', 1);

        if ($serviceId !== null) {
            $schedulesQuery->where('id', (int) $serviceId);
        }

        $schedules = $schedulesQuery->get()
            ->map(function ($row) {
                return (array) $row;
            });

        $created = 0;

        foreach ($schedules as $schedule) {
            if (!$this->isDueOn($schedule, $date)) {
                continue;
            }

            $exists = $db->table($sessionsTable)
                ->where('service_id', $schedule['id'])
                ->where($schema->sessionDateColumn(), $date->format('Y-m-d'))
                ->where('start_time', $schedule['start_time'])
                ->exists();

            if ($exists) {
                continue;
            }

            try {
                $db->table($sessionsTable)->insert([
                    'service_id'   => $schedule['id'],
                    $schema->sessionTitleColumn() => $schedule['name'],
                    $schema->sessionDateColumn() => $date->format('Y-m-d'),
                    'service_time' => $schedule['name'],
                    'start_time'   => $schedule['start_time'],
                    'end_time'     => $schedule['end_time'],
                    'schedule_type'=> $schedule['recurrence_type'],
                    $schema->sessionTypeColumn() => $schedule['service_type'],
                    $schema->sessionStatusColumn() => 'scheduled',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            } catch (QueryException $e) {
                $nowExists = $db->table($sessionsTable)
                    ->where('service_id', $schedule['id'])
                    ->where($schema->sessionDateColumn(), $date->format('Y-m-d'))
                    ->where('start_time', $schedule['start_time'])
                    ->exists();

                if ($nowExists) {
                    continue;
                }

                throw $e;
            }

            $created++;
        }

        return $created;
    }

    public function autoCreateScheduledSessions($date = null): int
    {
        return $this->generateForDate($date);
    }

    public function autoUpdateSessionStatuses($date = null): void
    {
        $now = $date ? Carbon::parse($date) : Carbon::now();
        $today = $now->format('Y-m-d');
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();

        $sessions = $db->table($sessionsTable)
            ->where($schema->sessionDateColumn(), $today)
            ->whereNotIn($schema->sessionStatusColumn(), ['completed', 'cancelled'])
            ->get();

        foreach ($sessions as $session) {
            $status = $this->statusForSession($session, $now);

            $statusColumn = $schema->sessionStatusColumn();

            if ($status !== $session->{$statusColumn}) {
                $db->table($sessionsTable)
                    ->where('id', $session->id)
                    ->update([$schema->sessionStatusColumn() => $status, 'updated_at' => now()]);
            }
        }
    }

    public function ensureCurrent($date = null): void
    {
        $this->autoCreateScheduledSessions($date);
        $this->autoUpdateSessionStatuses($date);
    }

    public function generateRange($from, $to, $serviceId = null): int
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        $total = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $total += $this->generateForDate($day->format('Y-m-d'), $serviceId);
        }

        return $total;
    }

    public function synchronizeFutureSessions(int $serviceId): array
    {
        $schedule = DB::table('services')->where('id', $serviceId)->first();
        $result = ['updated' => 0, 'restored' => 0, 'cancelled' => 0, 'generated' => 0, 'skipped_attended' => 0];

        if (!$schedule) {
            return $result;
        }

        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();
        $sessionDateColumn = $schema->sessionDateColumn();
        $statusColumn = $schema->sessionStatusColumn();
        $today = Carbon::today()->format('Y-m-d');
        $horizon = $db->table($sessionsTable)
            ->where('service_id', $serviceId)
            ->where($sessionDateColumn, '>=', $today)
            ->max($sessionDateColumn) ?: $today;

        $sessions = $db->table($sessionsTable)
            ->where('service_id', $serviceId)
            ->where($sessionDateColumn, '>=', $today)
            ->whereIn($statusColumn, ['scheduled', 'active', 'cancelled'])
            ->get();

        foreach ($sessions as $session) {
            if ($this->sessionHasAttendance($session->id)) {
                $result['skipped_attended']++;
                continue;
            }

            if (!$schedule->is_active || !$this->isDueOn((array) $schedule, Carbon::parse($session->{$sessionDateColumn}))) {
                $db->table($sessionsTable)->where('id', $session->id)->update([
                    $statusColumn => 'cancelled',
                    'updated_at' => now(),
                ]);
                if ($session->{$statusColumn} !== 'cancelled') {
                    $result['cancelled']++;
                }
                continue;
            }

            $newStatus = $session->{$statusColumn};
            if ($newStatus === 'cancelled') {
                $newStatus = $session->{$sessionDateColumn} === $today
                    ? $this->statusForSession($session, Carbon::now())
                    : 'scheduled';
            }
            $db->table($sessionsTable)->where('id', $session->id)->update([
                $schema->sessionTitleColumn() => $schedule->name,
                'service_time' => $schedule->name,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'schedule_type' => $schedule->recurrence_type,
                $schema->sessionTypeColumn() => $schedule->service_type,
                $statusColumn => $newStatus,
                'updated_at' => now(),
            ]);
            if ($session->{$statusColumn} === 'cancelled') {
                $result['restored']++;
            }
            $result['updated']++;
        }

        if ($schedule->is_active) {
            $result['generated'] = $this->generateRange($today, $horizon, $serviceId);
            $this->autoUpdateSessionStatuses();
        }

        return $result;
    }

    public function cancelFutureSessions(int $serviceId): array
    {
        $result = ['cancelled' => 0, 'skipped_attended' => 0];
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();

        foreach ($this->futureScheduledSessions($serviceId)->get() as $session) {
            if ($this->sessionHasAttendance($session->id)) {
                $result['skipped_attended']++;
                continue;
            }

            $db->table($sessionsTable)->where('id', $session->id)->update([
                $schema->sessionStatusColumn() => 'cancelled',
                'updated_at' => now(),
            ]);
            $result['cancelled']++;
        }

        return $result;
    }

    public function removeFutureSessions(int $serviceId): array
    {
        $result = ['deleted' => 0, 'skipped_attended' => 0];
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();

        foreach ($this->futureScheduledSessions($serviceId, true)->get() as $session) {
            if ($this->sessionHasAttendance($session->id)) {
                $result['skipped_attended']++;
                continue;
            }

            $db->table($sessionsTable)->where('id', $session->id)->delete();
            $result['deleted']++;
        }

        return $result;
    }

    private function futureScheduledSessions(int $serviceId, bool $includeCancelled = false)
    {
        $schema = app(AttendanceSchema::class);
        $sessions = $schema->sessionsTable();
        $today = Carbon::today()->format('Y-m-d');
        $query = $schema->db()->table($sessions)
            ->where('service_id', $serviceId);

        if ($includeCancelled) {
            $query->whereIn($schema->sessionStatusColumn(), ['scheduled', 'active', 'cancelled']);
        } else {
            $query->whereIn($schema->sessionStatusColumn(), ['scheduled', 'active']);
        }

        return $query->where($schema->sessionDateColumn(), '>=', $today);
    }

    private function statusForSession($session, Carbon $now): string
    {
        $currentTime = $now->format('H:i:s');

        if ($session->end_time && $currentTime >= $session->end_time) {
            return 'completed';
        }

        if ($session->start_time && $currentTime >= $session->start_time) {
            return 'active';
        }

        return 'scheduled';
    }

    private function sessionHasAttendance(int $sessionId): bool
    {
        $schema = app(AttendanceSchema::class);

        return $schema->db()->table($schema->recordsTable())
            ->where($schema->recordSessionIdColumn(), $sessionId)
            ->exists();
    }
}
