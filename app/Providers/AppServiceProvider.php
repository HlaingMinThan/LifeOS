<?php

namespace App\Providers;

use App\Services\Inbox\ClaudeParser;
use App\Services\Inbox\FakeParser;
use App\Services\Inbox\ParserContract;
use App\Services\Money\CategorizerContract;
use App\Services\Money\ClaudeCategorizer;
use App\Services\Money\FakeCategorizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ParserContract::class, fn () => match (config('lifeos.parser')) {
            'claude' => new ClaudeParser,
            default => new FakeParser,
        });

        // Same switch as the parser: "fake" keeps tests and no-credit local
        // dev off the API without a second setting to remember.
        $this->app->bind(CategorizerContract::class, fn () => match (config('lifeos.parser')) {
            'claude' => new ClaudeCategorizer,
            default => new FakeCategorizer,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
