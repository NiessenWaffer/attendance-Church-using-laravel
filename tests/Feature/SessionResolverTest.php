<?php

namespace Tests\Feature;

use App\Services\SessionResolver;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionResolverTest extends TestCase
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
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
        });
        Schema::connection('sqlite')->create('attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('service_id')->nullable();
            $table->date('session_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('status');
        });
        Schema::connection('sqlite')->create('service_blackouts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('service_id')->nullable();
            $table->date('blackout_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
        });
    }

    public function testExplicitSessionIsRejectedBeforeItsEffectiveWindow()
    {
        $sessionId = $this->insertSessionWithService('09:00:00', '11:00:00');

        $session = app(SessionResolver::class)->resolve(
            $sessionId,
            Carbon::parse('2026-08-30 08:59:59')
        );

        $this->assertNull($session);
    }

    public function testExplicitSessionIsResolvedWithinItsEffectiveWindow()
    {
        $sessionId = $this->insertSessionWithService('09:00:00', '11:00:00');

        $session = app(SessionResolver::class)->resolve(
            $sessionId,
            Carbon::parse('2026-08-30 10:00:00')
        );

        $this->assertNotNull($session);
        $this->assertSame($sessionId, (int) $session->id);
    }

    public function testExplicitSessionIsRejectedAfterItsEffectiveWindow()
    {
        $sessionId = $this->insertSessionWithService('09:00:00', '11:00:00');

        $session = app(SessionResolver::class)->resolve(
            $sessionId,
            Carbon::parse('2026-08-30 11:00:01')
        );

        $this->assertNull($session);
    }

    public function testNullSessionAndServiceBoundariesAllowTheWholeDay()
    {
        $sessionId = $this->insertSessionWithService(null, null);

        $resolver = app(SessionResolver::class);

        $this->assertNotNull($resolver->resolve($sessionId, Carbon::parse('2026-08-30 00:00:00')));
        $this->assertNotNull($resolver->resolve($sessionId, Carbon::parse('2026-08-30 23:59:59')));
    }

    private function insertSessionWithService(?string $startTime, ?string $endTime): int
    {
        $serviceId = DB::connection('sqlite')->table('services')->insertGetId([
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return DB::connection('sqlite')->table('attendance_sessions')->insertGetId([
            'service_id' => $serviceId,
            'session_date' => '2026-08-30',
            'start_time' => null,
            'end_time' => null,
            'status' => 'scheduled',
        ]);
    }
}
