<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLocalAttendanceWorkflow extends Migration
{
    public function up()
    {
        // Keep the old app-only schema available for reference and rollback.
        $this->renameIfPresent('attendance_schedules', 'attendance_schedules_legacy');
        $this->renameIfPresent('attendance_events', 'attendance_events_legacy');
        $this->renameIfPresent('attendance_records', 'attendance_records_legacy');

        if (!Schema::hasTable('services')) {
            Schema::create('services', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 150);
                $table->string('description')->nullable();
                $table->string('schedule_day', 20)->default('Sunday');
                $table->date('specific_date')->nullable();
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->string('service_type', 50)->default('default');
                $table->text('ministries')->nullable();
                $table->string('recurrence_type', 20)->default('weekly');
                $table->unsignedTinyInteger('day_of_week')->nullable();
                $table->unsignedTinyInteger('day_of_month')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedTinyInteger('sync_status')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['is_active', 'schedule_day']);
            });
        }

        if (!Schema::hasTable('attendance_sessions')) {
            Schema::create('attendance_sessions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('service_id')->nullable();
                $table->string('session_name', 150);
                $table->date('session_date');
                $table->string('service_time', 100);
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->string('schedule_type', 30)->default('one-time');
                $table->string('service_type', 50)->default('default');
                $table->enum('status', ['scheduled', 'active', 'completed', 'cancelled'])->default('scheduled');
                $table->unsignedTinyInteger('sync_status')->default(0);
                $table->timestamps();
                $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
                $table->unique(['service_id', 'session_date', 'start_time'], 'unique_service_session');
                $table->index(['session_date', 'status']);
            });
        }

        if (!Schema::hasTable('attendance_records')) {
            Schema::create('attendance_records', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('session_id');
                // No members table is created: member data remains API-owned.
                $table->unsignedBigInteger('member_id');
                $table->string('member_code', 100)->nullable();
                $table->string('sync_id', 100)->nullable();
                $table->date('attendance_date');
                $table->string('service_time', 100);
                $table->time('check_in_time')->nullable();
                $table->unsignedTinyInteger('sync_status')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('session_id')->references('id')->on('attendance_sessions')->cascadeOnDelete();
                $table->unique(['session_id', 'member_id'], 'unique_session_member');
                $table->index('member_id');
            });
        }

        if (!Schema::hasTable('service_blackouts')) {
            Schema::create('service_blackouts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('service_id')->nullable();
                $table->string('service_name', 150)->nullable();
                $table->date('blackout_date');
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->string('reason')->nullable();
                $table->unsignedTinyInteger('sync_status')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
                $table->index(['blackout_date', 'service_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('service_blackouts');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('services');

        $this->renameIfPresent('attendance_records_legacy', 'attendance_records');
        $this->renameIfPresent('attendance_events_legacy', 'attendance_events');
        $this->renameIfPresent('attendance_schedules_legacy', 'attendance_schedules');
    }

    private function renameIfPresent(string $from, string $to): void
    {
        if (Schema::hasTable($from) && !Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }
}
