<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            // An assigned todo lives in the assignee's own list (user_id), so it
            // shows up in their day, digest and reminders like any other. This
            // records who put it there — and is the only thing that grants the
            // assigner visibility, so their view stays limited to what they gave.
            $table->foreignId('assigned_by_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['assigned_by_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['assigned_by_id', 'status']);
            $table->dropConstrainedForeignId('assigned_by_id');
        });
    }
};
