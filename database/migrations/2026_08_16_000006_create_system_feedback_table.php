<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSystemFeedbackTable extends Migration
{
    public function up()
    {
        Schema::create('system_feedback', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('username', 150)->nullable();
            $table->string('category', 50)->default('General');
            $table->string('rating', 20)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('Open');
            $table->timestamps();

            $table->index('category');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('system_feedback');
    }
}
