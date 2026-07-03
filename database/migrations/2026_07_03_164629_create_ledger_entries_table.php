<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction'); // payable | receivable
            $table->string('title');
            $table->unsignedBigInteger('amount_mmk');
            $table->decimal('amount_usd', 12, 2)->nullable();
            $table->string('status')->default('open'); // open | paid | cancelled
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
