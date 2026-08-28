<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttendanceFollowups extends Migration
{
    public function up()
    {
        if (Schema::hasTable('attendance_followups')) {
            return;
        }

        Schema::create('attendance_followups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('external_member_id', 150);
            $table->text('note')->nullable();
            $table->enum('status', ['open', 'completed'])->default('open');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique('external_member_id');
            $table->index(['status', 'due_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('attendance_followups');
    }
}
