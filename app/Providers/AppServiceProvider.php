<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Game\Translation\ClaudeTranslator;
use App\Game\Translation\NullTranslator;
use App\Game\Translation\Translator;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        // Chat messages are translated by Claude when an API key is set, on
        // the admin page or in the server's .env. Without one, the
        // Translate button says it isn't available.
        $this->app->bind(Translator::class, function ($app) {
            $config = config('game.translator');
            $key = $app->make(Settings::class)->get('anthropic_api_key', $config['api_key']);

            if (blank($key)) {
                return new NullTranslator;
            }

            return new ClaudeTranslator(
                client: new AnthropicClient(
                    apiKey: $key,
                    requestOptions: ['timeout' => $config['timeout'], 'maxRetries' => 2],
                ),
                model: $config['model'],
                effort: $config['effort'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('staff', fn (User $user): bool => (bool) $user->is_staff);
        Gate::define('admin', fn (User $user): bool => (bool) $user->is_admin);
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
