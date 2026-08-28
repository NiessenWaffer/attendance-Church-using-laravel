<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConvertAttendanceRecordsToExternalMembers extends Migration
{
    public function up()
    {
        if (!$this->indexExists('attendance_records', 'attendance_records_session_id_index')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->index('session_id', 'attendance_records_session_id_index');
            });
        }
        if ($this->indexExists('attendance_records', 'unique_session_member')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->dropUnique('unique_session_member');
            });
        }
        if ($this->indexExists('attendance_records', 'attendance_records_member_id_index')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->dropIndex('attendance_records_member_id_index');
            });
        }

        $oldColumns = array_values(array_filter(['member_id', 'member_code', 'sync_id', 'check_in_time'], function ($column) {
            return Schema::hasColumn('attendance_records', $column);
        }));
        if ($oldColumns) {
            Schema::table('attendance_records', function (Blueprint $table) use ($oldColumns) {
                $table->dropColumn($oldColumns);
            });
        }

        if (!Schema::hasColumn('attendance_records', 'external_member_id')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->string('external_member_id', 150)->after('session_id');
            });
        }
        if (!Schema::hasColumn('attendance_records', 'member_name_cache')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->string('member_name_cache', 255)->nullable()->after('external_member_id');
            });
        }
        if (!Schema::hasColumn('attendance_records', 'member_photo_cache')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->string('member_photo_cache', 500)->nullable()->after('member_name_cache');
            });
        }
        if (!$this->indexExists('attendance_records', 'unique_session_external_member')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->unique(['session_id', 'external_member_id'], 'unique_session_external_member');
            });
        }
    }

    public function down()
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique('unique_session_external_member');
            $table->dropIndex('attendance_records_session_id_index');
            $table->dropColumn(['external_member_id', 'member_name_cache', 'member_photo_cache']);
            $table->unsignedBigInteger('member_id')->nullable()->after('session_id');
            $table->string('member_code', 100)->nullable()->after('member_id');
            $table->string('sync_id', 100)->nullable()->after('member_code');
            $table->time('check_in_time')->nullable()->after('service_time');
            $table->unique(['session_id', 'member_id'], 'unique_session_member');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (DB::select('SHOW INDEX FROM `' . $table . '`') as $row) {
            if ($row->Key_name === $index) {
                return true;
            }
        }

        return false;
    }
}
