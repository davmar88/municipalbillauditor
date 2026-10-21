<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\User;
use App\Models\Visit;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's Visitors page: how many people came, from which countries,
 * which pages they opened, and who did what, over the last day, week or
 * month (visits are kept 90 days).
 */
class VisitorController extends Controller
{
    /** What a page or an action is called on the Visitors page, by route. */
    private const LABELS = [
        'home' => 'Landing page',
        'wiki' => 'How the game works',
        'puzzle' => "Today's puzzle",
        'puzzle.solve' => 'Solved the puzzle',
        'register' => 'Sign-up page',
        'register.store' => 'Signed up',
        'login' => 'Log-in page',
        'login.store' => 'Logged in',
        'logout' => 'Logged out',
        'google.redirect' => 'Signed in with Google',
        'password.request' => 'Forgot password page',
        'password.email' => 'Asked for a password reset',
        'password.update' => 'Reset their password',
        'verification.verify' => 'Confirmed their email',
        'verification.notice' => 'Waiting to confirm email',
        'play.start' => 'Make your animal',
        'play.start.store' => 'Made their animal',
        'play' => 'Map',
        'play.today' => 'Today',
        'play.wall' => 'The Wall',
        'play.market' => 'Market',
        'play.faction' => 'Faction',
        'play.me' => 'Me',
        'play.chat.store' => 'Sent a chat message',
        'play.messages.store' => 'Sent a private message',
        'play.walk-to' => 'Walked',
        'play.walk' => 'Walked',
        'play.gather' => 'Gathered',
        'play.crime' => 'Robbed or burned',
        'play.attack' => 'Raided',
        'play.buy' => 'Bought in the Market',
        'play.apples.order' => 'Started a Pineapple purchase',
        'play.apples.capture' => 'Bought Pineapples',
        'play.faction.join' => 'Joined a faction',
        'play.faction.store' => 'Founded a faction',
        'play.design' => 'Made a design',
    ];

    public function index(Request $request): Response
    {
        $days = in_array((int) $request->query('days'), [1, 7, 30, 90], true) ? (int) $request->query('days') : 7;
        $since = now()->subDays($days);
        $visits = fn () => Visit::query()->where('created_at', '>=', $since);

        $countries = $visits()
            ->select('country', DB::raw('COUNT(DISTINCT visitor) AS visitors'), DB::raw('COUNT(*) AS views'))
            ->groupBy('country')
            ->orderByDesc('visitors')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'code' => $row->country,
                'name' => Geo::name($row->country),
                'flag' => Geo::flag($row->country),
                'visitors' => (int) $row->visitors,
                'views' => (int) $row->views,
            ]);

        $pages = $visits()
            ->where('method', 'GET')
            ->select('route', 'path', DB::raw('COUNT(*) AS views'), DB::raw('COUNT(DISTINCT visitor) AS visitors'))
            ->groupBy('route', 'path')
            ->orderByDesc('views')
            ->limit(15)
            ->get()
            ->map(fn ($row) => ['what' => $this->label($row->route, $row->path), 'path' => $row->path, 'views' => (int) $row->views, 'visitors' => (int) $row->visitors]);

        $referrers = $visits()
            ->whereNotNull('referrer')
            ->select('referrer', DB::raw('COUNT(DISTINCT visitor) AS visitors'))
            ->groupBy('referrer')
            ->orderByDesc('visitors')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['site' => $row->referrer, 'visitors' => (int) $row->visitors]);

        $perDay = $visits()
            ->select(DB::raw('DATE(created_at) AS day'), DB::raw('COUNT(DISTINCT visitor) AS visitors'), DB::raw('COUNT(*) AS views'))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => (string) $row->day, 'visitors' => (int) $row->visitors, 'views' => (int) $row->views]);

        $who = $request->integer('user') ?: null;
        $recent = Visit::query()
            ->with('user:id,name,email')
            ->when($who, fn ($query) => $query->where('user_id', $who))
            ->latest('id')
            ->limit(150)
            ->get();
        $animals = Player::query()->whereIn('user_id', $recent->pluck('user_id')->filter()->unique())->pluck('name', 'user_id');

        return Inertia::render('admin/visitors', [
            'days' => $days,
            'totals' => [
                'visitors' => (int) $visits()->distinct()->count('visitor'),
                'views' => (int) $visits()->where('method', 'GET')->count(),
                'actions' => (int) $visits()->where('method', '!=', 'GET')->count(),
                'players' => (int) $visits()->whereNotNull('user_id')->distinct()->count('user_id'),
                'countries' => (int) $visits()->whereNotNull('country')->distinct()->count('country'),
                // From the accounts themselves: most sign up with Google, which never opens the sign-up form.
                'signups' => (int) User::query()->where('created_at', '>=', $since)->count(),
            ],
            'perDay' => $perDay,
            'countries' => $countries,
            'pages' => $pages,
            'referrers' => $referrers,
            'filteredBy' => $who ? ($animals[$who] ?? $recent->first()?->user?->name ?? 'that player') : null,
            'recent' => $recent->map(fn (Visit $visit) => [
                'id' => $visit->id,
                'at' => $visit->created_at->toIso8601String(),
                'userId' => $visit->user_id,
                'who' => $visit->user_id !== null ? ($animals[$visit->user_id] ?? $visit->user?->name ?? 'A deleted account') : null,
                'flag' => Geo::flag($visit->country),
                'country' => Geo::name($visit->country),
                'action' => $visit->method !== 'GET',
                'what' => $this->label($visit->route, $visit->path),
                'path' => $visit->path,
            ]),
            'geoReady' => DB::table('ip_countries')->exists(),
        ]);
    }

    /** A page or action in plain words: "Map", "Gathered", or failing that, its route in words. */
    private function label(?string $route, string $path): string
    {
        if ($route === null) {
            return $path;
        }

        return self::LABELS[$route] ?? ucfirst(str_replace(['.', '-'], [' ', ' '], preg_replace('/^play\./', '', $route) ?? $route));
    }
}
