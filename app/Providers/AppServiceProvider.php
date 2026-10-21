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

        // The mailbox the game sends from, when the owner saved one on the admin page.
        $this->app->afterResolving('mail.manager', function () {
            $settings = $this->app->make(Settings::class);
            $host = $settings->get('mail_host');

            if ($host === null) {
                return;
            }

            $port = (int) $settings->get('mail_port', '465');
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => $port,
                'mail.mailers.smtp.scheme' => $port === 465 ? 'smtps' : 'smtp',
                'mail.mailers.smtp.username' => $settings->get('mail_username'),
                'mail.mailers.smtp.password' => $settings->get('mail_password'),
                'mail.from.address' => $settings->get('mail_from_address', $settings->get('mail_username')),
            ]);
        });

        // The owner can do anything the team can, such as checking designs.
        Gate::define('staff', fn (User $user): bool => (bool) $user->is_staff || (bool) $user->is_admin);
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
