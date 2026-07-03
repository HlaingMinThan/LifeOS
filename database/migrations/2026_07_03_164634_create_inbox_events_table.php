<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_events', function (Blueprint $table) {
            $table->id();
            $table->text('raw_text');
            $table->json('parsed_json');
            $table->boolean('applied')->default(false);
            // What the apply step touched, so undo knows its exact target.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_events');
    }
};
