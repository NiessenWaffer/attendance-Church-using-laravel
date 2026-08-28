<?php

namespace Tests\Feature;

use App\Services\AttendanceRecordService;
use App\Support\AttendanceSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionAttendanceCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'attendance.mode' => 'prod',
            'attendance.connection' => 'sqlite',
        ]);

        DB::purge('sqlite');

        Schema::connection('sqlite')->create('attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('session_name');
            $table->date('session_date');
            $table->string('service_time')->nullable();
            $table->time('start_time')->nullable();
            $table->string('service_type')->default('worship');
            $table->string('status')->default('scheduled');
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('attendance_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('session_id');
            $table->unsignedInteger('member_id');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function testProductionWriteUsesExistingColumnsAndDoesNotRequireSyncColumns()
    {
        $sessionId = DB::connection('sqlite')->table('attendance_sessions')->insertGetId([
            'session_name' => 'Sunday Worship',
            'session_date' => '2026-08-30',
            'service_time' => 'Sunday Worship',
            'start_time' => '08:00:00',
            'service_type' => 'worship',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $session = DB::connection('sqlite')->table('attendance_sessions')->find($sessionId);
        $member = [
            'external_member_id' => 'WOH-T3RF',
            'name' => 'Test Member',
            'member' => ['id' => 42, 'member_id' => 'WOH-T3RF'],
        ];

        $result = app(AttendanceRecordService::class)->insert($session, 'WOH-T3RF', $member, [
            'sync_id' => 'offline-uuid',
            'offline_created_at' => now()->subMinute(),
        ]);

        $this->assertFalse($result['duplicate']);
        $this->assertDatabaseHas('attendance_records', ['session_id' => $sessionId, 'member_id' => 42], 'sqlite');
        $this->assertFalse(Schema::connection('sqlite')->hasColumn('attendance_records', 'sync_id'));
    }

    public function testDuplicateIsDetectedWithoutAddingAUniqueIndex()
    {
        $sessionId = DB::connection('sqlite')->table('attendance_sessions')->insertGetId([
            'session_name' => 'Sunday Worship',
            'session_date' => '2026-08-30',
            'service_time' => 'Sunday Worship',
            'start_time' => '08:00:00',
            'service_type' => 'worship',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $session = DB::connection('sqlite')->table('attendance_sessions')->find($sessionId);
        $member = [
            'external_member_id' => 'WOH-T3RF',
            'member' => ['id' => 42, 'member_id' => 'WOH-T3RF'],
        ];
        $service = app(AttendanceRecordService::class);

        $service->insert($session, 'WOH-T3RF', $member);
        $duplicate = $service->insert($session, 'WOH-T3RF', $member);

        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame(1, DB::connection('sqlite')->table('attendance_records')->count());
    }

    public function testSessionClosedExpressionUsesCanonicalStatuses()
    {
        $sql = app(AttendanceSchema::class)->sessionClosedSelectSql('attendance_sessions');

        $this->assertStringContainsString("'completed', 'cancelled'", $sql);
    }
}
