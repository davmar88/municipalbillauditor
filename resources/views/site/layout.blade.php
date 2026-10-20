@php
    $playUrl = auth()->check() ? route('play') : route('register');
    $contact = config('game.contact_email');
@endphp
<!DOCTYPE html>
<html lang="en-ZA">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title')</title>
        <meta name="description" content="@yield('description')">
        <link rel="canonical" href="@yield('canonical', url()->current())">

        <meta property="og:type" content="website">
        <meta property="og:locale" content="en_ZA">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="@yield('title')">
        <meta property="og:description" content="@yield('description')">
        <meta property="og:url" content="@yield('canonical', url()->current())">
        <meta name="twitter:card" content="summary">
        <meta name="theme-color" content="#16171D">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite('resources/css/site.css')
    </head>
    <body>
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-10 focus:rounded-md focus:bg-paper focus:px-4 focus:py-2">Skip to content</a>

        <div class="bg-banner text-sm text-white">
            <p class="mx-auto flex max-w-[1200px] flex-wrap items-center justify-center gap-x-2 gap-y-1 px-[clamp(16px,4vw,40px)] py-2 text-center">
                <span class="rounded-full bg-white/15 px-2 py-0.5 font-mono text-[11px] font-semibold tracking-[0.12em]">TEST SEASON</span>
                <span>The first season of the valley is free to play, for adults 18 and older.</span>
                <a href="{{ $playUrl }}" class="font-semibold text-white underline underline-offset-[3px] hover:text-white">Join it</a>
            </p>
        </div>

        <header class="border-b border-rule">
            <div class="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-x-6 gap-y-3 px-[clamp(16px,4vw,40px)] py-3.5">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-ink no-underline hover:text-ink" aria-label="{{ config('app.name') }} home">
                    @include('site.partials.logo', ['size' => 30])
                    <span class="flex flex-col leading-none">
                        <span class="font-mono text-[10px] tracking-[0.12em] text-muted">A FARM OF RIVAL FACTIONS</span>
                        <span class="mt-[3px] text-[19px] font-bold">Animal <span class="text-gradient">Farm</span></span>
                    </span>
                </a>
                <nav aria-label="Main" class="flex flex-wrap items-center gap-x-5 gap-y-1 text-[15px]">
                    <a href="{{ route('home') }}#how" class="hidden py-2.5 text-ink no-underline hover:underline sm:inline">How it plays</a>
                    <a href="{{ route('puzzle') }}" class="hidden py-2.5 text-ink no-underline hover:underline sm:inline">Spot the Edit</a>
                    @guest
                        <a href="{{ route('login') }}" class="py-2.5 text-ink no-underline hover:underline">Log in</a>
                    @endguest
                    <a href="{{ $playUrl }}" class="glow flex h-11 items-center rounded-md bg-ink px-4 font-semibold text-ground no-underline hover:bg-ink-2 hover:text-ground">Play</a>
                </nav>
            </div>
        </header>

        <main id="main">
            @yield('content')
        </main>

        <footer class="border-t border-rule bg-night py-14 text-[15px] text-night-text">
            <div class="mx-auto flex max-w-[1200px] flex-col gap-8 px-[clamp(16px,4vw,40px)]">
                <div class="flex flex-wrap items-start justify-between gap-8">
                    <div class="flex max-w-[420px] flex-col gap-2.5">
                        <span class="text-[19px] font-bold text-white">Animal <span class="text-gradient">Farm</span></span>
                        <span class="text-sm text-night-muted">An online strategy game set on a farm the humans left behind. Inspired by a classic fable. Not affiliated with or endorsed by the estate of George Orwell.</span>
                    </div>
                    <nav aria-label="Site" class="flex flex-col gap-1">
                        <a href="{{ route('puzzle') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Spot the Edit</a>
                        <a href="{{ route('privacy') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Privacy and POPIA</a>
                        <a href="{{ route('terms') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Terms and Conditions</a>
                        <a href="{{ route('updates') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Updates and fixes</a>
                        @if ($contact)
                            <a href="mailto:{{ $contact }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Contact</a>
                        @endif
                    </nav>
                </div>
                <div class="border-t border-night-rule pt-6 text-sm text-night-muted">© {{ now()->year }} {{ config('game.company_name') ?: config('app.name') }}</div>
            </div>
        </footer>
    </body>
</html>
