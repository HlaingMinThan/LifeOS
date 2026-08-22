<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Long, and encrypted at rest via the model cast — hence text.
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_bot_username')->nullable();
            $table->string('telegram_chat_id')->nullable();
            // Identifies the owner in the webhook URL, so it must be unique.
            $table->string('telegram_webhook_secret', 64)->nullable()->unique();
            $table->timestamp('telegram_linked_at')->nullable();
            $table->timestamp('telegram_prompt_dismissed_at')->nullable();
        });

        // Move the single .env bot onto the first account so the live bot keeps
        // working across this deploy instead of going dark until someone
        // re-runs the setup wizard.
        $token = config('lifeos.telegram.token');
        $chatId = config('lifeos.telegram.chat_id');
        $ownerId = DB::table('users')->orderBy('id')->value('id');

        if ($token && $chatId && $ownerId) {
            DB::table('users')->where('id', $ownerId)->update([
                'telegram_bot_token' => Crypt::encryptString($token),
                'telegram_chat_id' => (string) $chatId,
                'telegram_webhook_secret' => Str::random(48),
                'telegram_linked_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_bot_token', 'telegram_bot_username', 'telegram_chat_id',
                'telegram_webhook_secret', 'telegram_linked_at', 'telegram_prompt_dismissed_at',
            ]);
        });
    }
};
