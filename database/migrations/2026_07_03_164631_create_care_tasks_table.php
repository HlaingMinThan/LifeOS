<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('schedule_type'); // daily | weekly | random
            $table->time('time_of_day')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable(); // 0=Sun … 6=Sat
            $table->unsignedTinyInteger('random_min_days')->nullable();
            $table->unsignedTinyInteger('random_max_days')->nullable();
            $table->dateTime('next_run_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_tasks');
    }
};
