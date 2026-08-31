<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceRecordController;
use App\Http\Controllers\KioskController;
use App\Services\MemberFetchService;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UniqueMemberDayMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'attendance.mode' => 'local',
            'attendance.connection' => 'sqlite',
            'attendance.kiosk_key' => 'test-kiosk-key',
        ]);
        DB::purge('sqlite');
        Carbon::setTestNow('2026-08-31 12:00:00');

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('session_name');
            $table->date('session_date');
            $table->time('start_time')->nullable();
            $table->string('service_type')->default('worship');
            $table->string('status')->default('completed');
            $table->timestamps();
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('session_id');
            $table->string('external_member_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testMemberStatsUsesCalendarDaysRatherThanSessions(): void
    {
        $first = $this->createAttendanceSession('2026-08-30', 'First');
        $second = $this->createAttendanceSession('2026-08-30', 'Second');
        $third = $this->createAttendanceSession('2026-08-29', 'Third');
        $this->record($first, 'M101');
        $this->record($second, 'M101');
        $this->record($third, 'M101');

        $response = app(AttendanceRecordController::class)->memberStats('M101');
        $data = $response->getData(true)['data'];

        $this->assertSame(2, $data['total']);
        $this->assertSame(2, $data['present']);
        $this->assertSame(2, $data['eligible_member_days']);
        $this->assertSame(2, $data['present_member_days']);
        $this->assertSame(100, $data['rate']);
    }

    public function testKioskSessionAndRosterCountsAreDistinctMembers(): void
    {
        $session = $this->createAttendanceSession('2026-08-31', 'Morning');
        $this->record($session, 'M101');
        $this->record($session, 'M101');
        $this->record($session, 'M102');

        $members = $this->createMock(MemberFetchService::class);
        $members->method('hydrateRecords');
        $this->app->instance(MemberFetchService::class, $members);
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('ensureCurrent');
        $this->app->instance(ScheduleService::class, $schedule);

        $request = Request::create('/api/attendance/sessions', 'GET', ['date' => '2026-08-31']);
        $request->headers->set('Authorization', 'Bearer test-kiosk-key');
        $data = app(KioskController::class)->sessions($request)->getData(true);

        $this->assertSame(2, $data['sessions'][0]['attendee_count']);
        $this->assertSame(2, $data['summary']['unique_member_count']);

        $roster = app(KioskController::class)->todayBySession($request)->getData(true);
        $this->assertCount(2, $roster['sessions'][0]['members']);
    }

    private function createAttendanceSession(string $date, string $name): int
    {
        return DB::table('attendance_sessions')->insertGetId([
            'session_name' => $name,
            'session_date' => $date,
            'start_time' => '08:00:00',
            'service_type' => 'worship',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function record(int $sessionId, string $member): void
    {
        DB::table('attendance_records')->insert([
            'session_id' => $sessionId,
            'external_member_id' => $member,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
