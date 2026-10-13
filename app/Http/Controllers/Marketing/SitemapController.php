<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function sitemap(): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ...Municipality::landingPages()
                ->map(fn (Municipality $m) => ['loc' => route('metro.show', $m), 'priority' => '0.8'])->all(),
            ['loc' => route('privacy'), 'priority' => '0.3'],
        ];

        return response()
            ->view('marketing.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $lines = app()->isProduction()
            ? ['User-agent: *', 'Disallow: /dashboard', 'Disallow: /bills', 'Disallow: /disputes', 'Disallow: /portfolio', 'Disallow: /properties', 'Disallow: /settings', 'Disallow: /staff', '', 'Sitemap: '.route('sitemap')]
            // Keep staging and local copies out of search results.
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n")->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
