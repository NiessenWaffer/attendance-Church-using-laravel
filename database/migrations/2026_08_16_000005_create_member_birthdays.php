<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMemberBirthdays extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('member_birthdays')) {
            Schema::create('member_birthdays', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('member_code', 150)->unique();
                $table->date('birth_date');
                $table->timestamps();
                $table->index('birth_date');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('member_birthdays');
    }
}
