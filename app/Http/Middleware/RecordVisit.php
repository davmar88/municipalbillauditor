<?php

namespace App\Http\Middleware;

use App\Models\Visit;
use App\Support\Geo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notes each page opened and each thing done on the site, for the owner's
 * Visitors page: who (when signed in), the country, and what. The internet
 * address is only used to find the country and to tell visitors apart for
 * the day; it is never stored. Written after the response has gone out.
 */
class RecordVisit
{
    /** Robots and link previews: not visitors. */
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview|curl|wget|python|httpclient|headless|lighthouse|monitor/i';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->worthNoting($request, $response)) {
            return;
        }

        try {
            $referrer = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);

            Visit::query()->create([
                'visitor' => substr(hash_hmac('sha256', $request->ip().'|'.$request->userAgent().'|'.now()->toDateString(), (string) config('app.key')), 0, 16),
                'user_id' => $request->user()?->id,
                'country' => Geo::country($request->ip()),
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                // Only where they came from when it's another site.
                'referrer' => is_string($referrer) && $referrer !== $request->getHost() ? mb_substr($referrer, 0, 120) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Counting visitors must never break the site.
        }
    }

    private function worthNoting(Request $request, Response $response): bool
    {
        if ($request->isMethod('HEAD') || $request->isMethod('OPTIONS') || $response->isServerError()) {
            return false;
        }

        // The owner's and the team's own pages, and robots.
        if ($request->is('admin', 'admin/*', 'staff', 'staff/*', 'up', 'robots.txt', 'sitemap.xml', 'broadcasting/*', 'build/*')
            || preg_match(self::BOTS, (string) $request->userAgent())) {
            return false;
        }

        // A page asking for a fresh bit of itself, a prefetch, or a background fetch: not a visit.
        if ($request->isMethod('GET') && ($request->headers->has('X-Inertia-Partial-Data') || $request->headers->has('Purpose')
            || $request->headers->get('Sec-Purpose') !== null || ($request->expectsJson() && ! $request->headers->has('X-Inertia')))) {
            return false;
        }

        return true;
    }
}
