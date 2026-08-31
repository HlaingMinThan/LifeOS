<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            // The team owner: assignment is one-way, owner → member.
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Null until the invitee registers/accepts — invites are by email,
            // so a person can be invited before they have an account.
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('status')->default('pending'); // pending | accepted | revoked
            $table->string('token', 64)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // One standing invite per address per owner.
            $table->unique(['owner_id', 'email']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
