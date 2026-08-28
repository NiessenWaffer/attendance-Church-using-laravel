<?php

namespace App\Services;

use App\Support\AttendanceSchema;
use Carbon\Carbon;

class SessionResolver
{
    private $schema;

    public function __construct(AttendanceSchema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Resolve the session a scan belongs to.
     *
     * Strategy B (locked kiosk): an explicit session_id maps the record
     * directly to that session as long as its date is today and it is still
     * scheduled/active - the clock window is deliberately bypassed.
     *
     * Strategy A (dynamic clock matching): the session is resolved straight in
     * the database by today's date, an open status, and the current time
     * falling inside the session window (falling back to the parent service's
     * times when the session times are blank).
     */
    public function resolve($sessionId = null, Carbon $asOf = null): ?object
    {
        $asOf = $asOf ?: Carbon::now();

        if ($sessionId !== null && $sessionId !== '') {
            return $this->resolveLocked((int) $sessionId, $asOf);
        }

        return $this->resolveByClock($asOf);
    }

    private function resolveLocked(int $sessionId, Carbon $asOf): ?object
    {
        $sessions = $this->schema->sessionsTable();

        $session = $this->schema->db()->table($sessions)
            ->where('id', $sessionId)
            ->where($this->schema->sessionDateColumn(), $asOf->format('Y-m-d'))
            ->whereIn($this->schema->sessionStatusColumn(), ['scheduled', 'active'])
            ->first();

        if (!$session || $this->isBlackout($session, $asOf)) {
            return null;
        }

        return $session;
    }

    private function resolveByClock(Carbon $asOf): ?object
    {
        $sessions = $this->schema->sessionsTable();
        $services = (string) config('attendance.tables.' . $this->schema->mode() . '.services', 'services');

        $query = $this->schema->db()->table($sessions . ' as asess')
            ->leftJoin($services . ' as s', 'asess.service_id', '=', 's.id')
            ->select('asess.*')
            ->whereDate('asess.' . $this->schema->sessionDateColumn(), $asOf->format('Y-m-d'))
            ->whereNotIn('asess.' . $this->schema->sessionStatusColumn(), ['completed', 'cancelled'])
            ->whereRaw('? BETWEEN COALESCE(asess.start_time, s.start_time, "00:00:00") AND COALESCE(asess.end_time, s.end_time, "23:59:59")', [$asOf->format('H:i:s')])
            ->orderBy('asess.start_time');

        foreach ($query->get() as $session) {
            if (!$this->isBlackout($session, $asOf)) {
                return $session;
            }
        }

        return null;
    }

    private function isBlackout($session, Carbon $asOf): bool
    {
        $blackouts = $this->schema->db()
            ->table(config('attendance.tables.' . $this->schema->mode() . '.blackouts', 'service_blackouts'))
            ->whereDate('blackout_date', $asOf->format('Y-m-d'));

        $blackouts->where(function ($query) use ($session) {
            $query->whereNull('service_id');

            if (!empty($session->service_id)) {
                $query->orWhere('service_id', $session->service_id);
            }
        });

        $blackouts->whereRaw('? BETWEEN COALESCE(start_time, "00:00:00") AND COALESCE(end_time, "23:59:59")', [
            $asOf->format('H:i:s'),
        ]);

        return $blackouts->exists();
    }
}
