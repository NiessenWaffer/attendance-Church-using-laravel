<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Services\MemberFetchService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ReportAttendanceSemanticsTest extends TestCase
{
    private $members = [
        ['external_id' => '101', 'member_code' => 'M101', 'membership_status' => 'active', 'ministries' => ['Youth Ministry']],
        ['external_id' => '102', 'member_code' => 'M102', 'membership_status' => 'active', 'ministries' => ['Adults']],
        ['external_id' => '103', 'member_code' => 'M103', 'membership_status' => 'active', 'ministries' => []],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.sqlite_report' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'attendance.mode' => 'local',
            'attendance.connection' => 'sqlite_report',
        ]);
        DB::purge('sqlite_report');
        Carbon::setTestNow('2026-08-30 23:00:00');

        Schema::connection('sqlite_report')->create('services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('ministries')->nullable();
        });
        Schema::connection('sqlite_report')->create('attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('service_id')->nullable();
            $table->string('session_name');
            $table->date('session_date');
            $table->time('start_time')->nullable();
            $table->string('service_type')->default('worship');
            $table->string('status')->default('completed');
            $table->timestamps();
        });
        Schema::connection('sqlite_report')->create('attendance_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('session_id');
            $table->string('external_member_id');
            $table->timestamps();
        });

        $fetch = Mockery::mock(MemberFetchService::class);
        $fetch->shouldReceive('list')->andReturn($this->members);
        $fetch->shouldReceive('ministries')->andReturn(['Adults', 'Youth Ministry']);
        $this->app->instance(MemberFetchService::class, $fetch);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testOneMemberAtThreeSundayServicesIsThreeParticipationsAndOneUniqueAttendee(): void
    {
        $serviceId = $this->service('General Worship', null);
        $sessionIds = [
            $this->createSession($serviceId, 'First Service'),
            $this->createSession($serviceId, 'Second Service'),
            $this->createSession($serviceId, 'Third Service'),
        ];

        foreach ($sessionIds as $sessionId) {
            $this->attendance($sessionId, 'M101');
        }
        $this->attendance($sessionIds[0], 'M101');

        $data = $this->summary();

        $this->assertSame(3, $data['service_participations']);
        $this->assertSame(1, $data['sunday']['overview']['unique_attendee_days']);
        $this->assertSame(3, $data['sunday']['overview']['service_participations']);
        $this->assertSame(1, $data['general']['overview']['unique_attendee_days']);
        $this->assertSame([1, 1, 1], collect($data['by_session'])->pluck('participation_count')->all());
        $this->assertSame([1, 1, 1], collect($data['by_session'])->pluck('record_count')->map(function ($count) {
            return (int) $count;
        })->all());
    }

    public function testTargetedServiceUsesItsMinistryAsDenominatorAndKeepsOtherParticipationVisible(): void
    {
        $targetedService = $this->service('Youth Gathering', ['Youth Ministry']);
        $generalService = $this->service('General Worship', null);
        $targetedSession = $this->createSession($targetedService, 'Youth Gathering');
        $generalSession = $this->createSession($generalService, 'General Worship');

        $this->attendance($targetedSession, 'M101');
        $this->attendance($targetedSession, 'M102');
        $this->attendance($generalSession, 'M101');

        $data = $this->summary();
        $targeted = collect($data['by_session'])->first(function ($session) use ($targetedSession) {
            return (int) $session['id'] === $targetedSession;
        });
        $general = collect($data['by_session'])->first(function ($session) use ($generalSession) {
            return (int) $session['id'] === $generalSession;
        });

        $this->assertSame(1, $targeted['eligible_member_count']);
        $this->assertSame(1, $targeted['expected_present_count']);
        $this->assertSame(2, $targeted['participation_count']);
        $this->assertSame(1, $targeted['guest_other_count']);
        $this->assertSame(100, $targeted['attendance_rate']);
        $this->assertSame(3, $general['eligible_member_count']);
        $this->assertSame('ministry', $targeted['audience_type']);
        $this->assertSame('general', $general['audience_type']);

        $scopedData = $this->summary(['ministry' => 'Youth Ministry']);
        $scopedTargeted = collect($scopedData['by_session'])->first(function ($session) use ($targetedSession) {
            return (int) $session['id'] === $targetedSession;
        });
        $this->assertSame(1, $scopedTargeted['record_count']);
        $this->assertSame(1, $scopedTargeted['participation_count']);
        $this->assertSame(1, $scopedTargeted['eligible_member_count']);
        $this->assertSame(0, $scopedTargeted['guest_other_count']);
    }

    public function testZeroAttendanceSessionContributesAudienceOpportunitiesWithoutInventingAbsences(): void
    {
        $serviceId = $this->service('General Worship', null);
        $attendedSession = $this->createSession($serviceId, 'Attended Service');
        $emptySession = $this->createSession($serviceId, 'Zero Attendance Service');
        $this->attendance($attendedSession, 'M101');

        $data = $this->summary();
        $empty = collect($data['by_session'])->first(function ($session) use ($emptySession) {
            return (int) $session['id'] === $emptySession;
        });

        $this->assertCount(2, $data['by_session']);
        $this->assertNotNull($empty);
        $this->assertSame(0, $empty['participation_count']);
        $this->assertSame(3, $empty['eligible_member_count']);
        $this->assertSame(6, $data['rate_denominator']);
        $this->assertSame(1, $data['expected_participations']);
        $this->assertSame(17, $data['rate']);

        $absentData = $this->summary(['attendance_status' => 'absent']);
        $this->assertCount(2, $absentData['by_session']);
        $this->assertSame(17, $absentData['rate']);
        $this->assertSame(0, $absentData['statuses']['absent']);
    }

    private function summary(array $filters = []): array
    {
        $response = app(ReportController::class)->summary(Request::create('/api/report/summary', 'GET', $filters));

        return $response->getData(true)['data'];
    }

    private function service(string $name, ?array $ministries): int
    {
        return DB::connection('sqlite_report')->table('services')->insertGetId([
            'name' => $name,
            'ministries' => $ministries === null ? null : json_encode($ministries),
        ]);
    }

    private function createSession(int $serviceId, string $name): int
    {
        return DB::connection('sqlite_report')->table('attendance_sessions')->insertGetId([
            'service_id' => $serviceId,
            'session_name' => $name,
            'session_date' => '2026-08-30',
            'start_time' => '08:00:00',
            'service_type' => 'worship',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attendance(int $sessionId, string $memberCode): void
    {
        DB::connection('sqlite_report')->table('attendance_records')->insert([
            'session_id' => $sessionId,
            'external_member_id' => $memberCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
