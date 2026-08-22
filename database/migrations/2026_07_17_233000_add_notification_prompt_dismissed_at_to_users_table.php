<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Stamped when the user enables push or taps "Not now" on the Home
            // nudge — so the prompt is asked once, not on every visit.
            $table->timestamp('notification_prompt_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_prompt_dismissed_at');
        });
    }
};
