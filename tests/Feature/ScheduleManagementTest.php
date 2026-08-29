<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceScheduleController;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'attendance.mode' => 'local',
            'attendance.connection' => 'sqlite',
        ]);
        DB::purge('sqlite');

        Schema::connection('sqlite')->create('services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('schedule_day')->default('Sunday');
            $table->date('specific_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('service_type')->default('worship');
            $table->text('ministries')->nullable();
            $table->string('recurrence_type')->default('weekly');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('service_id')->nullable();
            $table->string('session_name');
            $table->date('session_date');
            $table->string('service_time')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('schedule_type')->default('once');
            $table->string('service_type')->default('worship');
            $table->string('status')->default('scheduled');
            $table->timestamps();
            $table->unique(['service_id', 'session_date', 'start_time']);
        });
        Schema::connection('sqlite')->create('attendance_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('session_id');
            $table->string('external_member_id')->nullable();
            $table->timestamps();
        });

        Carbon::setTestNow(Carbon::parse('2026-08-30 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testStatusTransitionsRespectTheFullTimeWindow()
    {
        $this->insertSession(null, '2026-08-30', '11:00:00', '12:00:00', 'active');
        $this->insertSession(null, '2026-08-30', '09:00:00', '11:00:00', 'scheduled');
        $this->insertSession(null, '2026-08-30', '08:00:00', '10:00:00', 'scheduled');

        app(ScheduleService::class)->autoUpdateSessionStatuses();

        $this->assertSame(
            ['scheduled', 'active', 'completed'],
            DB::table('attendance_sessions')->orderBy('id')->pluck('status')->all()
        );
    }

    public function testRecurrenceSynchronizationGeneratesNewDatesAndPreservesAttendedRows()
    {
        $serviceId = $this->insertService([
            'name' => 'Changed Recurrence',
            'schedule_day' => 'Monday',
            'recurrence_type' => 'weekly',
            'day_of_week' => 1,
        ]);
        $todayId = $this->insertSession($serviceId, '2026-08-30', '08:00:00', '09:00:00');
        $attendedId = $this->insertSession($serviceId, '2026-09-01', '08:00:00', '09:00:00');
        DB::table('attendance_records')->insert([
            'session_id' => $attendedId,
            'external_member_id' => 'TEST-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(ScheduleService::class)->synchronizeFutureSessions($serviceId);

        $this->assertSame(1, $result['generated']);
        $this->assertSame(1, $result['skipped_attended']);
        $this->assertDatabaseHas('attendance_sessions', ['id' => $todayId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('attendance_sessions', [
            'service_id' => $serviceId,
            'session_date' => '2026-08-31',
            'schedule_type' => 'weekly',
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('attendance_sessions', ['id' => $attendedId, 'status' => 'scheduled']);
    }

    public function testReactivationRestoresDueCancelledOccurrences()
    {
        $serviceId = $this->insertService([
            'is_active' => 1,
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
        ]);
        $sessionId = $this->insertSession($serviceId, '2026-08-30', '11:00:00', '12:00:00', 'cancelled');
        $futureSessionId = $this->insertSession($serviceId, '2026-09-06', '11:00:00', '12:00:00', 'cancelled');

        $result = app(ScheduleService::class)->synchronizeFutureSessions($serviceId);

        $this->assertSame(2, $result['restored']);
        $this->assertDatabaseHas('attendance_sessions', ['id' => $sessionId, 'status' => 'scheduled']);
        $this->assertDatabaseHas('attendance_sessions', ['id' => $futureSessionId, 'status' => 'scheduled']);
    }

    public function testCreateAndUpdateRequireAValidEndTime()
    {
        $controller = app(AttendanceScheduleController::class);

        try {
            $controller->create(Request::create('/', 'POST', [
                'name' => 'No End',
                'schedule_day' => 'Sunday',
                'service_type' => 'worship',
                'start_time' => '08:00',
            ]));
            $this->fail('Missing end time was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_time', $e->errors());
        }

        $legacyServiceId = $this->insertService(['end_time' => null]);
        try {
            $controller->update(Request::create('/', 'PATCH', [
                'description' => 'Edited legacy schedule',
            ]), $legacyServiceId);
            $this->fail('An update without an effective end time was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_time', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $controller->create(Request::create('/', 'POST', [
            'name' => 'Bad Window',
            'schedule_day' => 'Sunday',
            'service_type' => 'worship',
            'start_time' => '10:00',
            'end_time' => '09:00',
        ]));
    }

    public function testEditingAOneTimeDateUpdatesBothDateFields()
    {
        $serviceId = $this->insertService([
            'name' => 'One Time',
            'schedule_day' => 'One-time',
            'recurrence_type' => 'once',
            'day_of_week' => null,
            'specific_date' => '2026-09-05',
            'start_date' => '2026-09-05',
        ]);

        app(AttendanceScheduleController::class)->update(Request::create('/', 'PATCH', [
            'specific_date' => '2026-09-06',
        ]), $serviceId);

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'specific_date' => '2026-09-06',
            'start_date' => '2026-09-06',
        ]);
    }

    public function testOriginAndCategoryFiltersAreIndependent()
    {
        $this->insertService(['name' => 'Custom Prayer', 'service_type' => 'prayer']);
        $this->insertService(['name' => 'Custom Worship', 'service_type' => 'worship']);

        $response = app(AttendanceScheduleController::class)->index(Request::create('/', 'GET', [
            'origin' => 'custom',
            'service_type' => 'prayer',
        ]));
        $payload = $response->getData(true);

        $this->assertCount(1, $payload['data']);
        $this->assertSame('Custom Prayer', $payload['data'][0]['name']);
        $this->assertSame(0, (int) $payload['data'][0]['is_default']);
    }

    public function testBuiltInIdentityCannotBeRenamed()
    {
        app(ScheduleService::class)->ensureDefaults();
        $default = DB::table('services')->where('name', 'Sunday Worship')->first();

        $this->expectException(ValidationException::class);
        app(AttendanceScheduleController::class)->update(Request::create('/', 'PATCH', [
            'name' => 'Renamed Default',
        ]), $default->id);
    }

    private function insertService(array $overrides = []): int
    {
        return DB::table('services')->insertGetId(array_merge([
            'name' => 'Test Service',
            'description' => null,
            'schedule_day' => 'Sunday',
            'specific_date' => null,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'service_type' => 'worship',
            'ministries' => null,
            'recurrence_type' => 'weekly',
            'day_of_week' => 0,
            'day_of_month' => null,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_default' => 0,
            'is_active' => 1,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertSession($serviceId, string $date, string $start, string $end, string $status = 'scheduled'): int
    {
        return DB::table('attendance_sessions')->insertGetId([
            'service_id' => $serviceId,
            'session_name' => 'Test Session',
            'session_date' => $date,
            'service_time' => 'Test Session',
            'start_time' => $start,
            'end_time' => $end,
            'schedule_type' => 'weekly',
            'service_type' => 'worship',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
