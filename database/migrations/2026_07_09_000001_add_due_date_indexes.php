<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->index('due_date');
        });
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
        });
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
        });
    }
};
