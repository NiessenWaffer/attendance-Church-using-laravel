<?php

namespace App\Http\Controllers;

use App\Repositories\BaseRepository;
use App\Services\ScheduleService;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\CacheHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceScheduleController extends Controller
{
    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private $scheduleService;
    private $repo;

    public function __construct(ScheduleService $scheduleService, BaseRepository $repo)
    {
        $this->scheduleService = $scheduleService;
        $this->repo = $repo;
    }

    public function index(Request $request)
    {
        $this->scheduleService->ensureDefaults();

        $query = DB::table('services')
            ->select(
                'services.id',
                'services.name',
                'services.name as schedule_name',
                'services.description',
                'services.description as session_title',
                'services.service_type',
                'services.service_type as session_type',
                'services.recurrence_type',
                'services.schedule_day',
                'services.specific_date',
                'services.day_of_week',
                'services.day_of_month',
                'services.start_date',
                'services.end_date',
                'services.start_time',
                'services.end_time',
                'services.ministries',
                'services.is_default',
                'services.is_active'
            );

        $type = $request->input('type');
        if ($type === 'default') {
            $query->where('is_default', 1);
        } elseif ($type === 'custom') {
            $query->where('is_default', 0);
        }

        $status = $request->input('status');
        if ($status === 'active') {
            $query->where('is_active', 1);
        } elseif ($status === 'inactive') {
            $query->where('is_active', 0);
        }

        $search = trim((string) $request->input('search'));
        if ($search !== '') {
            $query->where('services.name', 'like', "%{$search}%");
        }

        $schedules = $query->orderBy('is_default', 'desc')
            ->orderBy('services.name')
            ->get();

        $schedules->transform(function ($schedule) {
            if (!empty($schedule->ministries)) {
                $decoded = json_decode($schedule->ministries, true);
                if (is_array($decoded)) {
                    $schedule->ministries = $decoded;
                }
            }

            return $schedule;
        });

        return ApiResponse::success($schedules);
    }

    private function rules(bool $forUpdate = false): array
    {
        return [
            'schedule_name'   => array_merge(['nullable', 'string', 'max:150'], $forUpdate ? [] : ['required_without:name']),
            'name'            => array_merge(['nullable', 'string', 'max:150'], $forUpdate ? [] : ['required_without:schedule_name']),
            'session_title'   => ['nullable', 'string', 'max:150'],
            'description'     => ['nullable', 'string', 'max:255'],
            'session_type'    => ['nullable', 'string', 'in:worship,prayer,youth', 'max:50'],
            'service_type'    => ['nullable', 'string', 'in:worship,prayer,youth', 'max:50'],
            'schedule_day'    => ['nullable', 'string', 'in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Daily,Monthly,One-time'],
            'specific_date'   => ['nullable', 'date_format:Y-m-d', 'required_if:schedule_day,One-time'],
            'recurrence_type' => ['nullable', 'string', 'in:daily,weekly,monthly,once'],
            'day_of_week'     => ['nullable', 'integer', 'between:0,6'],
            'day_of_month'    => ['nullable', 'integer', 'between:1,31'],
            'start_date'      => ['nullable', 'date_format:Y-m-d'],
            'end_date'        => ['nullable', 'date_format:Y-m-d'],
            'start_time'      => array_merge(['date_format:H:i'], $forUpdate ? ['nullable'] : ['required']),
            'end_time'        => ['nullable', 'date_format:H:i'],
            'ministries'      => ['nullable', 'array'],
        ];
    }

    private function assertValidSchedule(array $validated): void
    {
        $recurrence = $validated['recurrence_type'] ?? null;
        $scheduleDay = $validated['schedule_day'] ?? null;

        if ($scheduleDay === 'Daily') {
            $recurrence = 'daily';
        } elseif ($scheduleDay === 'Monthly') {
            $recurrence = 'monthly';
        } elseif ($scheduleDay === 'One-time') {
            $recurrence = 'once';
        } elseif (in_array($scheduleDay, self::WEEKDAYS, true)) {
            $recurrence = 'weekly';
        }

        if ($recurrence === 'once' && empty($validated['specific_date']) && empty($validated['start_date'])) {
            throw ValidationException::withMessages([
                'specific_date' => 'A specific date is required for a one-time service.',
            ]);
        }

        if ($recurrence === 'once' && !empty($validated['specific_date']) && !empty($validated['start_date'])
            && $validated['specific_date'] !== $validated['start_date']) {
            throw ValidationException::withMessages([
                'start_date' => 'The start date must match the specific date for a one-time service.',
            ]);
        }

        if ($recurrence === 'weekly' && !isset($validated['day_of_week']) && !in_array($scheduleDay, self::WEEKDAYS, true)) {
            throw ValidationException::withMessages([
                'day_of_week' => 'A weekday is required for a weekly service.',
            ]);
        }

        if ($recurrence === 'monthly' && empty($validated['day_of_month'])) {
            throw ValidationException::withMessages([
                'day_of_month' => 'A day of month is required for a monthly service.',
            ]);
        }

        if (!empty($validated['start_date']) && !empty($validated['end_date'])
            && $validated['end_date'] < $validated['start_date']) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date must be on or after the start date.',
            ]);
        }

        if (!empty($validated['start_time']) && !empty($validated['end_time'])
            && $validated['end_time'] <= $validated['start_time']) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }
    }

    public function create(Request $request)
    {
        $this->normalizeLegacyServiceType($request);
        $validated = $request->validate($this->rules());
        $data = $this->serviceData($validated);
        $this->assertValidSchedule($data);

        $schedule = $this->repo->create('services', $data, [
            'is_default' => 0,
            'is_active'  => 1,
        ]);

        AuditLogger::log(
            'schedule.create',
            'schedule',
            "Created schedule \"{$schedule->name}\"",
            ['schedule_id' => $schedule->id]
        );

        return ApiResponse::created($schedule, 'Schedule created successfully.');
    }

    public function update(Request $request, $id)
    {
        if (!$this->repo->exists('services', $id)) {
            return ApiResponse::notFound();
        }

        $this->normalizeLegacyServiceType($request);
        $validated = $request->validate($this->rules(true));
        $validated = $this->normalizeAliases($validated);
        $existing = (array) $this->repo->find('services', $id);
        $source = array_merge($existing, $validated);

        if (!array_key_exists('schedule_day', $validated)
            && count(array_intersect(['recurrence_type', 'day_of_week', 'day_of_month'], array_keys($validated))) > 0) {
            $source['schedule_day'] = null;
        }

        if (!empty($source['ministries']) && !is_array($source['ministries'])) {
            $decoded = json_decode($source['ministries'], true);
            $source['ministries'] = is_array($decoded) ? $decoded : [];
        }

        $data = $this->serviceData($source);
        $this->assertValidSchedule($data);

        $result = DB::transaction(function () use ($id, $data) {
            return [
                'schedule' => $this->repo->update('services', $id, $data),
                'sessions' => $this->scheduleService->synchronizeFutureSessions((int) $id),
            ];
        });
        $schedule = $result['schedule'];

        AuditLogger::log(
            'schedule.update',
            'schedule',
            "Updated schedule \"{$schedule->name}\"",
            ['schedule_id' => (int) $id]
        );

        return ApiResponse::success([
            'schedule' => $schedule,
            'session_sync' => $result['sessions'],
        ], 'Schedule updated successfully; future sessions were synchronized.');
    }

    public function toggle($id)
    {
        $schedule = $this->repo->find('services', $id);

        if (!$schedule) {
            return ApiResponse::notFound();
        }

        $isActive = $schedule->is_active ? 0 : 1;

        $sessionResult = DB::transaction(function () use ($id, $isActive) {
            $this->repo->update('services', $id, ['is_active' => $isActive]);

            return $isActive
                ? ['cancelled' => 0, 'skipped_attended' => 0]
                : $this->scheduleService->cancelFutureSessions((int) $id);
        });

        AuditLogger::log(
            'schedule.toggle',
            'schedule',
            ($isActive ? 'Activated' : 'Deactivated') . " schedule \"{$schedule->name}\"",
            ['schedule_id' => (int) $id]
        );

        return ApiResponse::success([
            'is_active' => $isActive,
            'session_sync' => $sessionResult,
        ], $isActive ? 'Schedule activated.' : 'Schedule deactivated; safe future sessions were cancelled.');
    }

    public function destroy($id)
    {
        $schedule = $this->repo->find('services', $id);

        if (!$schedule) {
            return ApiResponse::notFound();
        }

        if ($schedule->is_default) {
            return ApiResponse::forbidden('Default schedules cannot be deleted. Deactivate instead.');
        }

        $sessionResult = DB::transaction(function () use ($id) {
            $result = $this->scheduleService->removeFutureSessions((int) $id);
            $this->repo->delete('services', $id);

            return $result;
        });

        AuditLogger::log(
            'schedule.delete',
            'schedule',
            "Deleted schedule \"{$schedule->name}\"",
            ['schedule_id' => (int) $id]
        );

        return ApiResponse::success([
            'session_sync' => $sessionResult,
        ], 'Schedule deleted; safe future sessions were removed and historical sessions were preserved.');
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
        ]);
        $date = $validated['date'] ?? null;
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        $serviceId = $validated['service_id'] ?? null;

        if ($date && ($from || $to)) {
            throw ValidationException::withMessages([
                'date' => 'Use either date or a from/to range, not both.',
            ]);
        }

        if ($from && $to && Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw ValidationException::withMessages([
                'to' => 'The generation range cannot exceed 366 days.',
            ]);
        }

        if ($from && $to) {
            $created = $this->scheduleService->generateRange($from, $to, $serviceId);
        } else {
            $created = $this->scheduleService->generateForDate($date, $serviceId);
        }

        CacheHelper::forget('dashboard.summary');

        AuditLogger::log('schedule.generate', 'schedule', "Generated {$created} session(s) from schedules", [
            'generated' => $created,
            'service_id' => $serviceId,
        ]);

        return ApiResponse::success([
            'generated' => $created,
            'service_id' => $serviceId,
        ], 'Schedule generation completed.');
    }

    private function serviceData(array $validated): array
    {
        $name = $validated['name'] ?? $validated['schedule_name'] ?? '';
        $description = $validated['description'] ?? $validated['session_title'] ?? null;
        $scheduleDay = $validated['schedule_day'] ?? null;
        $serviceType = $validated['service_type'] ?? $validated['session_type'] ?? 'worship';
        $endTime = $validated['end_time'] ?? null;

        $recurrenceType = null;
        $dayOfWeek = null;

        if ($scheduleDay !== null) {
            if ($scheduleDay === 'Daily') {
                $recurrenceType = 'daily';
            } elseif ($scheduleDay === 'Monthly') {
                $recurrenceType = 'monthly';
            } elseif ($scheduleDay === 'One-time') {
                $recurrenceType = 'once';
            } else {
                $recurrenceType = 'weekly';
                $dayOfWeek = (int) array_search($scheduleDay, self::WEEKDAYS, true);
            }
        } else {
            $recurrenceType = $validated['recurrence_type'] ?? 'weekly';
            $dayOfWeek = $validated['day_of_week'] ?? null;
            $scheduleDay = $recurrenceType === 'daily'
                ? 'Daily'
                : ($recurrenceType === 'once'
                    ? 'One-time'
                    : ($recurrenceType === 'monthly'
                        ? 'Monthly'
                        : (self::WEEKDAYS[(int) $dayOfWeek] ?? 'Sunday')));
        }

        $specificDate = null;
        $startDate = $validated['start_date'] ?? null;

        if ($recurrenceType === 'once') {
            $specificDate = $validated['specific_date'] ?? $startDate ?? null;
            $startDate = $startDate ?? $specificDate;
        }

        if ($startDate === null) {
            $startDate = Carbon::today()->format('Y-m-d');
        }

        return [
            'name'            => $name,
            'description'     => $description,
            'service_type'    => $serviceType,
            'recurrence_type' => $recurrenceType,
            'day_of_week'     => $dayOfWeek,
            'day_of_month'    => $validated['day_of_month'] ?? null,
            'start_date'      => $startDate,
            'end_date'        => $validated['end_date'] ?? null,
            'start_time'      => $validated['start_time'] ?? null,
            'end_time'        => $endTime,
            'specific_date'   => $specificDate,
            'schedule_day'    => $scheduleDay,
            'ministries'      => isset($validated['ministries']) && is_array($validated['ministries'])
                ? json_encode(array_values($validated['ministries']), JSON_UNESCAPED_UNICODE)
                : null,
        ];
    }

    private function normalizeAliases(array $validated): array
    {
        if (array_key_exists('schedule_name', $validated) && !array_key_exists('name', $validated)) {
            $validated['name'] = $validated['schedule_name'];
        }

        if (array_key_exists('session_title', $validated) && !array_key_exists('description', $validated)) {
            $validated['description'] = $validated['session_title'];
        }

        if (array_key_exists('session_type', $validated) && !array_key_exists('service_type', $validated)) {
            $validated['service_type'] = $validated['session_type'];
        }

        return $validated;
    }

    private function normalizeLegacyServiceType(Request $request): void
    {
        $field = $request->has('service_type') ? 'service_type' : ($request->has('session_type') ? 'session_type' : null);

        if ($field === null || !in_array($request->input($field), ['default', 'custom'], true)) {
            return;
        }

        $description = strtolower(trim((string) $request->input('name', $request->input('schedule_name', '')))
            . ' ' . trim((string) $request->input('description', $request->input('session_title', ''))));
        $serviceType = strpos($description, 'youth') !== false
            ? 'youth'
            : (strpos($description, 'prayer') !== false ? 'prayer' : 'worship');

        $request->merge([$field => $serviceType]);
    }
}
