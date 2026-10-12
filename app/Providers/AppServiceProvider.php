<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Http\Controllers\Marketing\PageController;
use App\Models\Municipality;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaystackGateway;
use App\Services\Reading\BillReader;
use App\Services\Reading\ClaudeBillReader;
use App\Services\Reading\ManualOnlyReader;
use App\Services\Replies\ClaudeReplyReader;
use App\Services\Replies\ManualReplyReader;
use App\Services\Replies\ReplyReader;
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
        $this->app->bind(PaymentGateway::class, fn () => new PaystackGateway(
            secretKey: (string) config('services.paystack.secret_key'),
            baseUrl: (string) config('services.paystack.base_url'),
            timeout: (int) config('services.paystack.timeout'),
        ));

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

        // Municipal replies to disputes, read with the same client. Without
        // a key our team reads them.
        $this->app->bind(ReplyReader::class, function () {
            $config = config('auditor.reader');

            if (blank($config['api_key'])) {
                return new ManualReplyReader;
            }

            return new ClaudeReplyReader(
                client: new AnthropicClient(
                    apiKey: $config['api_key'],
                    requestOptions: ['timeout' => $config['timeout'], 'maxRetries' => 2],
                ),
                model: $config['reply_model'],
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

        // Every public page links to the metro landing pages from its footer.
        View::composer('marketing.layout', fn ($view) => $view->with(
            'footerMetros',
            Municipality::landingPages(),
        ));

        // Prices come from config, so the public pages never quote an old
        // one. "Free" only appears when there is welcome credit.
        View::composer('marketing.*', function ($view) {
            $prices = PageController::prices();

            $view->with('sitePrices', $prices)->with('checkLabel', $prices['welcome'] !== null ? 'Check my bill free' : 'Check my bill');
        });
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
