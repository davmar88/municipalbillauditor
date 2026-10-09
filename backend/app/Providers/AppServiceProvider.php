<?php

namespace App\Providers;

use App\Audit\AuditService;
use App\Audit\Rules\ArithmeticMismatchRule;
use App\Audit\Rules\ConsecutiveEstimatesRule;
use App\Audit\Rules\ConsumptionSpikeRule;
use App\Audit\Rules\EstimatedReadingRule;
use App\Audit\Rules\OutageChargeRule;
use App\Audit\Rules\TariffMismatchRule;
use App\Extraction\BillExtractor;
use App\Extraction\ClaudeBillExtractor;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\Outage;
use App\Models\Property;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Audit rules, in the order findings are first created.
     *
     * @var list<class-string>
     */
    public const AUDIT_RULES = [
        EstimatedReadingRule::class,
        ConsecutiveEstimatesRule::class,
        TariffMismatchRule::class,
        OutageChargeRule::class,
        ConsumptionSpikeRule::class,
        ArithmeticMismatchRule::class,
    ];

    /**
     * Route parameters that must belong to the authenticated user.
     *
     * @var array<string, class-string<Model>>
     */
    private const OWNED_BINDINGS = [
        'property' => Property::class,
        'bill' => Bill::class,
        'outage' => Outage::class,
        'finding' => Finding::class,
        'dispute' => Dispute::class,
    ];

    public function register(): void
    {
        $this->app->singleton(AuditService::class, fn ($app) => new AuditService(
            array_map(fn (string $rule) => $app->make($rule), self::AUDIT_RULES),
        ));

        $this->app->bind(BillExtractor::class, fn () => new ClaudeBillExtractor(
            apiKey: config('services.anthropic.key'),
            model: (string) config('services.anthropic.model', 'claude-opus-5-5'),
            maxTokens: (int) config('services.anthropic.max_tokens', 16000),
        ));
    }

    public function boot(): void
    {
        // POPIA: keep function arguments (account numbers, bill contents,
        // tokens) out of exception traces and therefore out of the logs.
        ini_set('zend.exception_ignore_args', '1');

        // 6 requests per minute per IP across register and login.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(6)->by('auth|'.$request->ip()));

        // DELETE /me checks your password. Someone holding a stolen token
        // must not be able to guess it (it may be used elsewhere too).
        RateLimiter::for('delete-account', function (Request $request) {
            $user = 'delete-account|user|'.$request->user()?->getAuthIdentifier();

            return [
                Limit::perMinute(5)->by($user.'|minute'),
                Limit::perHour(20)->by($user.'|hour'),
                Limit::perMinute(5)->by('delete-account|ip|'.$request->ip()),
            ];
        });

        // Scoped route model binding: another user's record is a 404, so
        // ownership is never leaked.
        foreach (self::OWNED_BINDINGS as $parameter => $model) {
            Route::bind($parameter, function (string $value) use ($model) {
                $user = request()->user();
                if ($user === null || ! ctype_digit($value)) {
                    throw (new ModelNotFoundException)->setModel($model);
                }

                return $model::query()->ownedBy($user)->findOrFail((int) $value);
            });
        }
    }
}
