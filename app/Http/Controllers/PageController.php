<?php

namespace App\Http\Controllers;

use App\Game\Census;
use App\Models\GameUpdate;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** The public pages, server-rendered for search engines and WhatsApp previews. */
class PageController extends Controller
{
    public function home(): View
    {
        return view('site.home', ['valleys' => Census::valleys()]);
    }

    public function privacy(): View
    {
        return view('site.privacy');
    }

    public function terms(): View
    {
        return view('site.terms');
    }

    public function fairPlay(): View
    {
        return view('site.fair-play');
    }

    public function wiki(): View
    {
        return view('site.wiki');
    }

    public function updates(): View
    {
        return view('site.updates', [
            'days' => GameUpdate::query()->latest('published_on')->orderByDesc('kind')->orderBy('id')->limit(200)->get()
                ->groupBy(fn (GameUpdate $update) => $update->published_on->toDateString()),
        ]);
    }

    public function sitemap(): Response
    {
        // The pages that change most come first and say so.
        $today = now()->toDateString();
        $urls = [
            [route('home'), 'daily', '1.0'],
            [route('wiki'), 'weekly', '0.8'],
            [route('puzzle'), 'daily', '0.7'],
            [route('updates'), 'daily', '0.6'],
            [route('fair-play'), 'monthly', '0.4'],
            [route('privacy'), 'yearly', '0.2'],
            [route('terms'), 'yearly', '0.2'],
        ];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(fn (array $url) => '<url><loc>'.e($url[0]).'</loc><lastmod>'.$today.'</lastmod><changefreq>'.$url[1].'</changefreq><priority>'.$url[2].'</priority></url>', $urls))
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow: /play\nDisallow: /staff\nSitemap: ".route('sitemap')."\n", 200, ['Content-Type' => 'text/plain']);
    }
}
