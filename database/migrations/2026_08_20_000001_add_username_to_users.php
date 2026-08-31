<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The @handle used to assign work. Unique so a mention is
            // unambiguous; nullable only until the backfill below runs.
            $table->string('username')->nullable()->unique()->after('email');
        });

        User::whereNull('username')->get()->each(function (User $user) {
            $user->forceFill(['username' => $this->uniqueHandleFor($user)])->save();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }

    /** "Zayar Win" → zayarwin, then zayarwin2… if taken. */
    private function uniqueHandleFor(User $user): string
    {
        $base = Str::of($user->name)->lower()->replaceMatches('/[^a-z0-9]/', '')->value();

        if ($base === '') {
            $base = Str::of((string) $user->email)->before('@')
                ->lower()->replaceMatches('/[^a-z0-9]/', '')->value();
        }

        $base = $base !== '' ? $base : 'user';
        $handle = $base;
        $suffix = 2;

        while (User::where('username', $handle)->exists()) {
            $handle = $base.$suffix++;
        }

        return $handle;
    }
};
