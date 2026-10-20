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
        $urls = [route('home'), route('puzzle'), route('privacy'), route('terms'), route('updates'), route('fair-play'), route('wiki')];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(fn (string $url) => '<url><loc>'.e($url).'</loc></url>', $urls))
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow: /play\nDisallow: /staff\nSitemap: ".route('sitemap')."\n", 200, ['Content-Type' => 'text/plain']);
    }
}
