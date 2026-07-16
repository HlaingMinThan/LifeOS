<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table holding one person's Life OS. care_task_logs is absent on
     * purpose — it inherits its owner through care_task_id.
     */
    private const TABLES = [
        'contacts', 'ledger_entries', 'todos', 'care_tasks',
        'ideas', 'inbox_events', 'parser_examples',
    ];

    public function up(): void
    {
        // Everything written before the app knew about owners belongs to the
        // first account.
        $ownerId = DB::table('users')->orderBy('id')->value('id');

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('user_id')->nullable()->after('id')
                    ->constrained()->cascadeOnDelete();
            });

            if ($ownerId) {
                DB::table($table)->whereNull('user_id')->update(['user_id' => $ownerId]);
            } elseif (DB::table($table)->exists()) {
                // Tightening below would fail anyway; say why while it's still fixable.
                throw new RuntimeException(
                    "Cannot backfill {$table}: it has rows but there is no user to own them."
                );
            }
        }

        // Only tighten once every row has an owner — a null user_id after this
        // point is data nobody can reach.
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('user_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('user_id');
            });
        }
    }
};
