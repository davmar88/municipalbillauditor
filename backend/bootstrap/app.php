<?php

use App\Support\UploadLimits;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Token-only API: never redirect guests to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One generic message for every 404 so ownership and model names
        // are never leaked.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });

        // A request bigger than PHP's post_max_size arrives empty. For a file
        // upload, say what went wrong in the usual validation shape so both
        // apps can show it next to the file field.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (str_starts_with(strtolower((string) $request->header('Content-Type')), 'multipart/form-data')) {
                return response()->json([
                    'message' => UploadLimits::BILL_FILE_TOO_BIG,
                    'errors' => ['file' => [UploadLimits::BILL_FILE_TOO_BIG]],
                ], 422);
            }

            return response()->json(['message' => 'That request is too big to process.'], 413);
        });

        // POPIA: query exceptions include bound values (bill contents,
        // encrypted fields). Log the error without them.
        $exceptions->report(function (QueryException $e) {
            Log::error('Database query failed.', [
                'code' => $e->getCode(),
                'sql' => $e->getSql(),
            ]);

            return false;
        });
    })->create();
