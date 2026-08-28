<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\KioskAuthenticatable;
use App\Services\AttendanceRecordService;
use App\Services\BirthdayService;
use App\Services\MemberFetchService;
use App\Services\ScheduleService;
use App\Services\SessionResolver;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    use KioskAuthenticatable;

    public function checkIn(Request $request, ScheduleService $schedules, MemberFetchService $members)
    {
        if (!$this->authorized($request)) {
            return ApiResponse::unauthorized('Kiosk authentication failed.');
        }

        $validated = $request->validate([
            'member_id' => ['required', 'string', 'max:150'],
            'session_id' => ['nullable', 'integer'],
        ]);

        $schedules->ensureCurrent();

        $session = app(SessionResolver::class)->resolve($validated['session_id'] ?? null);

        if (!$session) {
            return ApiResponse::error('No active attendance session is available.', 409);
        }

        $member = $members->findByIdentifierLive($validated['member_id']);

        if (!$member) {
            return ApiResponse::notFound('Invalid member code.');
        }

        $recordService = app(AttendanceRecordService::class);
        $canonicalMemberId = $recordService->canonicalMemberIdentifier($validated['member_id'], $member);
        if ($canonicalMemberId === null) {
            return ApiResponse::notFound('Invalid member code.');
        }

        $result = $recordService->insert($session, $canonicalMemberId, $member);

        if ($result['duplicate']) {
            $existing = $result['existing'] ?? null;

            return ApiResponse::error('Already checked in', 409, [
                'status' => 'duplicate',
                'record_id' => $result['id'],
                'member_name' => $existing->member_name_cache ?? null,
                'previous_check_in_time' => !empty($existing->created_at)
                    ? \Carbon\Carbon::parse($existing->created_at)->format('h:i:s A')
                    : null,
            ]);
        }

        AuditLogger::log(
            'attendance.check_in',
            'attendance',
            "Checked in {$validated['member_id']} for session #{$session->id}",
            ['record_id' => $result['id'], 'session_id' => $session->id, 'external_member_id' => $canonicalMemberId]
        );

        $birthday = app(BirthdayService::class)->checkFor($member, $validated['member_id']);

        return ApiResponse::success([
            'record_id' => $result['id'],
            'member' => $member['member'],
            'external_member_id' => $canonicalMemberId,
            'profile_photo_url' => $member['profile_photo_url'],
            'session_id' => $session->id,
            'attendance_time' => now()->format('h:i:s A'),
            'birthday' => $birthday,
        ], 'Check-in completed.');
    }
}
