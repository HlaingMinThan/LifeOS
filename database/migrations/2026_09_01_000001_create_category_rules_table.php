<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Which recurring merchant this speaks for: "contact:12" when the
            // entries link a contact, else "title:max energy". See
            // LedgerEntry::clusterKey() — the one place the key is derived.
            $table->string('cluster_key');

            // What the user called it. NULL means they dismissed the
            // suggestion: the cluster is remembered so it is not raised again,
            // but nothing is filed under it.
            $table->string('category')->nullable();

            // Kept for display, so a rule reads as "Max Energy → Fuel" rather
            // than as an opaque key.
            $table->string('label');

            $table->timestamps();

            $table->unique(['user_id', 'cluster_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_rules');
    }
};
