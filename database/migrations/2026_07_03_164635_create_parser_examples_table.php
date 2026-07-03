<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_examples', function (Blueprint $table) {
            $table->id();
            $table->text('raw_text');
            $table->json('corrected_json'); // the parse the user confirmed after fixing
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_examples');
    }
};
