<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\KioskAuthenticatable;
use App\Services\AttendanceRecordService;
use App\Services\BirthdayService;
use App\Services\MemberFetchService;
use App\Services\ScheduleService;
use App\Services\SessionResolver;
use App\Support\ApiResponse;
use App\Support\AttendanceSchema;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoints consumed by the standalone kiosk UI (PublicAttendance page).
 * All read endpoints are public; writes (check-in / sync) require the
 * Bearer kiosk key configured via ATTENDANCE_KIOSK_KEY.
 */
class KioskController extends Controller
{
    use KioskAuthenticatable;

    public function status()
    {
        return response()->json([
            'success' => true,
            'status' => 'ok',
        ]);
    }

    public function settings()
    {
        $data = $this->readAnnouncement();

        return response()->json([
            'enabled' => true,
            'theme' => null,
            'sound_enabled' => $data['sound_enabled'] ?? true,
        ]);
    }

    public function announcementVideo()
    {
        $data = $this->readAnnouncement();

        return response()->json([
            'status' => 'success',
            'video_url' => $data['video_url'] ?? '',
        ]);
    }

    public function getAnnouncement()
    {
        $data = $this->readAnnouncement();

        return ApiResponse::success($data);
    }

    public function updateAnnouncement(Request $request)
    {
        $validated = $request->validate([
            'video_url'     => ['nullable', 'string', 'max:500'],
            'sound_enabled' => ['nullable', 'boolean'],
        ]);

        $existing = $this->readAnnouncement();

        $data = [
            'video_url'     => $validated['video_url'] ?? $existing['video_url'] ?? '',
            'sound_enabled' => array_key_exists('sound_enabled', $validated)
                ? (bool) $validated['sound_enabled']
                : ($existing['sound_enabled'] ?? true),
        ];

        File::put($this->announcementPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ApiResponse::success($data, 'Kiosk settings updated.');
    }

    public function provision()
    {
        $key = (string) config('attendance.kiosk_key', '');

        if ($key === '') {
            return ApiResponse::error('The kiosk credential is not configured.', 503);
        }

        AuditLogger::log('kiosk.provision', 'settings', 'Provisioned the current browser for kiosk writes.');

        return ApiResponse::success(['kiosk_key' => $key], 'This browser is ready for kiosk check-ins.');
    }

    protected function announcementPath(): string
    {
        return storage_path('app/announcement.json');
    }

    protected function readAnnouncement(): array
    {
        $path = $this->announcementPath();

        if (!File::exists($path)) {
            return ['video_url' => ''];
        }

        $data = json_decode(File::get($path), true);

        return is_array($data) ? $data : ['video_url' => ''];
    }

    public function birthdays(Request $request)
    {
        if (!$this->authorized($request)) {
            return ApiResponse::unauthorized('Kiosk authentication failed.');
        }

        $data = app(BirthdayService::class)->monthCelebrants();

        return response()->json(array_merge(['success' => true], $data));
    }

    public function sessions(Request $request)
    {
        if (!$this->authorized($request)) {
            return ApiResponse::unauthorized('Kiosk authentication failed.');
        }

        app(ScheduleService::class)->ensureCurrent();

        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();
        $recordsTable = $schema->recordsTable();
        $sessionIdColumn = $schema->recordSessionIdColumn();
        $memberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();

        $date = (string) $request->query('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Carbon::now()->format('Y-m-d');
        }

        $sessions = $db->table($sessionsTable)
            ->where($schema->sessionDateColumn(), $date)
            ->orderBy('start_time')
            ->get();

        $ids = $sessions->pluck('id')->all();

        $counts = empty($ids)
            ? collect()
            : $db->table($recordsTable)
                ->whereIn($sessionIdColumn, $ids)
                ->select($sessionIdColumn, DB::raw('COUNT(*) as count'))
                ->groupBy($sessionIdColumn)
                ->pluck('count', $sessionIdColumn);

        $sessions->transform(function ($session) use ($counts) {
            $session->attendee_count = (int) ($counts[$session->id] ?? 0);

            return $session;
        });

        $unique = empty($ids) || $memberColumn === null
            ? 0
            : (int) $db->table($recordsTable)
                ->whereIn($sessionIdColumn, $ids)
                ->distinct()
                ->count($memberColumn);

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
            'server_date' => Carbon::now()->format('Y-m-d'),
            'server_time' => Carbon::now()->format('H:i:s'),
            'summary' => ['unique_member_count' => $unique],
        ]);
    }

    public function recent(Request $request)
    {
        if (!$this->authorized($request)) {
            return ApiResponse::unauthorized('Kiosk authentication failed.');
        }

        $schema = app(AttendanceSchema::class);
        $db = $schema->db();

        $recordsTable = $schema->recordsTable();
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

        $rows = $db->table($recordsTable)
            ->select($select)
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        app(MemberFetchService::class)->hydrateRecords($rows);

        $recent = $rows
            ->map(function ($record) {
                $created = $record->created_at ? Carbon::parse($record->created_at) : null;
                $name = trim((string) ($record->first_name ?? '') . ' ' . (string) ($record->last_name ?? ''));
                $identifier = $record->member_code ?? $record->member_id ?? '';

                return [
                    'name' => $record->member_name_cache ?: ($name ?: $identifier),
                    'time' => $created ? $created->format('h:i:s A') : '',
                    'attended_at' => $created ? $created->toIso8601String() : null,
                    'profile_photo_url' => $record->member_photo_cache,
                    'offline' => false,
                ];
            });

        return response()->json([
            'success' => true,
            'recent' => $recent,
        ]);
    }

    public function todayBySession(Request $request)
    {
        if (!$this->authorized($request)) {
            return ApiResponse::unauthorized('Kiosk authentication failed.');
        }

        app(ScheduleService::class)->ensureCurrent();

        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $sessionsTable = $schema->sessionsTable();
        $recordsTable = $schema->recordsTable();
        $sessionIdColumn = $schema->recordSessionIdColumn();
        $memberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();
        $date = Carbon::now()->format('Y-m-d');

        $sessions = $db->table($sessionsTable)
            ->where($schema->sessionDateColumn(), $date)
            ->orderBy('start_time')
            ->get();

        $ids = $sessions->pluck('id')->all();

        $membersBySession = [];

        if (!empty($ids)) {
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

            $rows = $db->table($recordsTable)
                ->select($select)
                ->whereIn($sessionIdColumn, $ids)
                ->orderByDesc('id')
                ->get();
            app(MemberFetchService::class)->hydrateRecords($rows);

            foreach ($rows as $row) {
                $name = trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? ''));
                $identifier = $row->member_code ?? $row->member_id ?? '';
                $membersBySession[$row->{$sessionIdColumn}][] = [
                    'name' => $row->member_name_cache ?: ($name ?: $identifier),
                    'profile_photo_url' => $row->member_photo_cache,
                ];
            }
        }

        $now = Carbon::now();
        $currentTime = $now->format('H:i:s');

        $sessions->transform(function ($session) use ($membersBySession, $currentTime) {
            $session->members = $membersBySession[$session->id] ?? [];
            $session->is_live = !in_array($session->status, ['completed', 'cancelled'])
                && (!$session->start_time || $currentTime >= $session->start_time)
                && (!$session->end_time || $currentTime <= $session->end_time);

            return $session;
        });

        $unique = empty($ids) || $memberColumn === null
            ? 0
            : (int) $db->table($recordsTable)
                ->whereIn($sessionIdColumn, $ids)
                ->distinct()
                ->count($memberColumn);

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
            'total_unique' => $unique,
        ]);
    }

    public function sync(Request $request)
    {
        if (!$this->authorized($request)) {
            return response()->json(['success' => false, 'message' => 'Kiosk authentication failed.'], 401);
        }

        $request->validate([
            'records' => ['sometimes', 'array', 'max:100'],
        ]);

        $records = $request->input('records', []);
        $syncedUuids = [];
        $failed = [];
        $processed = 0;

        if (empty($records)) {
            return response()->json([
                'synced_uuids' => [],
                'processed_count' => 0,
                'failed' => [],
            ]);
        }

        try {
            app(ScheduleService::class)->ensureCurrent();
        } catch (QueryException $e) {
            foreach ($records as $index => $item) {
                $failed[] = [
                    'index' => $index,
                    'sync_id' => is_array($item) && is_scalar($item['sync_id'] ?? null)
                        ? (string) $item['sync_id']
                        : null,
                    'reason' => 'database_error',
                ];
            }

            return response()->json([
                'synced_uuids' => [],
                'processed_count' => 0,
                'failed' => $failed,
            ], 503);
        }

        $resolver = app(SessionResolver::class);
        $recordService = app(AttendanceRecordService::class);
        $memberService = app(MemberFetchService::class);

        foreach ($records as $index => $item) {
            $uuid = is_array($item) && is_scalar($item['sync_id'] ?? null)
                ? trim((string) $item['sync_id'])
                : '';

            if (!is_array($item)) {
                $failed[] = ['index' => $index, 'sync_id' => null, 'reason' => 'invalid_record'];
                continue;
            }

            $validator = Validator::make($item, [
                'sync_id' => ['required', 'string', 'max:100'],
                'member_id' => ['required_without:member_code', 'nullable', 'string', 'max:150'],
                'member_code' => ['required_without:member_id', 'nullable', 'string', 'max:150'],
                'session_id' => ['nullable', 'integer', 'min:1'],
                'created_at' => ['nullable', 'date'],
            ]);

            if ($validator->fails()) {
                $failed[] = ['index' => $index, 'sync_id' => $uuid ?: null, 'reason' => 'validation_failed'];
                continue;
            }

            $memberCode = trim((string) (!empty($item['member_id']) ? $item['member_id'] : $item['member_code']));
            $sessionId = $item['session_id'] ?? null;

            try {
                $createdAt = !empty($item['created_at']) ? Carbon::parse($item['created_at']) : null;

                if ($recordService->findBySyncId($uuid)) {
                    $syncedUuids[] = $uuid;
                    $processed++;
                    continue;
                }

                $session = $resolver->resolve(
                    $sessionId !== null && $sessionId !== '' ? (int) $sessionId : null,
                    $createdAt
                );

                if (!$session) {
                    $failed[] = ['index' => $index, 'sync_id' => $uuid, 'reason' => 'session_unavailable'];
                    continue;
                }

                $member = $memberService->findByIdentifierLive($memberCode);
                $canonicalMemberId = $recordService->canonicalMemberIdentifier($memberCode, $member);
                if ($canonicalMemberId === null) {
                    $failed[] = ['index' => $index, 'sync_id' => $uuid, 'reason' => 'member_not_found'];
                    continue;
                }

                $recordService->insert($session, $canonicalMemberId, $member, [
                    'sync_id' => $uuid,
                    'offline_created_at' => $createdAt,
                ]);

                $syncedUuids[] = $uuid;
                $processed++;
            } catch (QueryException $e) {
                $failed[] = ['index' => $index, 'sync_id' => $uuid, 'reason' => 'database_error'];
            } catch (\Throwable $e) {
                $failed[] = ['index' => $index, 'sync_id' => $uuid, 'reason' => 'processing_error'];
            }
        }

        return response()->json([
            'synced_uuids' => $syncedUuids,
            'processed_count' => $processed,
            'failed' => $failed,
        ]);
    }
}
