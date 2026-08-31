<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            // Free-form and AI-named, not a lookup table: the user renames a
            // category by rewriting the string on every entry that carries it.
            $table->string('category')->nullable()->after('note');
            $table->index(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'category']);
            $table->dropColumn('category');
        });
    }
};
