<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('bucket')->default('personal'); // work | personal | money_task
            $table->string('status')->default('open'); // open | done
            $table->date('due_date')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
