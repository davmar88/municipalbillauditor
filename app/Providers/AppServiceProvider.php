<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Models\Metro;
use App\Models\User;
use App\Services\Reading\BillReader;
use App\Services\Reading\ClaudeBillReader;
use App\Services\Reading\ManualOnlyReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bills are read by Claude when an API key is configured. Without
        // one, every bill waits for its details to be typed in.
        $this->app->bind(BillReader::class, function () {
            $config = config('auditor.reader');

            if (blank($config['api_key'])) {
                return new ManualOnlyReader;
            }

            return new ClaudeBillReader(
                client: new AnthropicClient(
                    apiKey: $config['api_key'],
                    requestOptions: ['timeout' => $config['timeout'], 'maxRetries' => 2],
                ),
                model: $config['model'],
                effort: $config['effort'],
                maxTokens: $config['max_tokens'],
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

        // Every public page links to the metros we cover from its footer.
        View::composer('marketing.layout', fn ($view) => $view->with(
            'footerMetros',
            Metro::query()->where('live', true)->orderBy('sort')->get(),
        ));
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
