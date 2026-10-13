@php
    $checkUrl = auth()->check() ? route('bills.index') : route('register');
    $onHome = request()->routeIs('home');
    $section = fn (string $id) => $onHome ? "#{$id}" : route('home')."#{$id}";
    $contact = config('auditor.contact_email');
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
        <meta property="og:image" content="{{ asset('og-image.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="A municipal bill with the estimated water reading circled in orange">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="theme-color" content="#16171D">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite('resources/css/site.css')

        @stack('jsonld')
    </head>
    <body>
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-10 focus:rounded-md focus:bg-paper focus:px-4 focus:py-2">Skip to content</a>

        <header class="border-b border-rule">
            <div class="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-x-6 gap-y-3 px-[clamp(16px,4vw,40px)] py-3.5">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-ink no-underline hover:text-ink" aria-label="{{ config('app.name') }} home">
                    @include('marketing.partials.logo', ['size' => 30, 'ground' => '#16171D', 'stroke' => '#FFFFFF'])
                    <span class="flex flex-col leading-none">
                        <span class="font-mono text-[10px] tracking-[0.12em] text-muted">MUNICIPAL</span>
                        <span class="mt-[3px] text-[19px] font-bold">Bill Auditor</span>
                    </span>
                </a>
                <nav aria-label="Main" class="flex flex-wrap items-center gap-x-5 gap-y-1 text-[15px]">
                    <a href="{{ $section('errors') }}" class="hidden py-2.5 text-ink no-underline hover:underline sm:inline">What we check</a>
                    <a href="{{ $section('how') }}" class="hidden py-2.5 text-ink no-underline hover:underline sm:inline">How it works</a>
                    <a href="{{ $section('pricing') }}" class="hidden py-2.5 text-ink no-underline hover:underline sm:inline">Pricing</a>
                    <a href="{{ $section('faq') }}" class="hidden py-2.5 text-ink no-underline hover:underline md:inline">FAQ</a>
                    @guest
                        <a href="{{ route('login') }}" class="py-2.5 text-ink no-underline hover:underline">Log in</a>
                    @endguest
                    <a href="{{ $checkUrl }}" class="flex h-11 items-center rounded-md bg-ink px-4 font-semibold text-ground no-underline hover:bg-ink-2 hover:text-ground">Check my bill</a>
                </nav>
            </div>
        </header>

        <main id="main">
            @yield('content')
        </main>

        <footer class="border-t border-rule bg-night py-14 text-[15px] text-night-text">
            <div class="mx-auto flex max-w-[1200px] flex-col gap-10 px-[clamp(16px,4vw,40px)]">
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(180px,100%),1fr))] gap-8">
                    <div class="flex flex-col gap-2.5">
                        <span class="flex flex-col leading-none text-white">
                            <span class="font-mono text-[10px] tracking-[0.12em] text-night-muted">MUNICIPAL</span>
                            <span class="mt-[3px] text-[19px] font-bold">Bill Auditor</span>
                        </span>
                        <span class="text-sm text-night-muted">Checks and disputes for municipal bills in South Africa.</span>
                    </div>
                    <nav aria-label="Service" class="flex flex-col gap-1">
                        <span class="pb-1.5 text-[13px] font-bold tracking-[0.06em] text-white">SERVICE</span>
                        <a href="{{ $section('errors') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">What we check</a>
                        <a href="{{ $section('how') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">How it works</a>
                        <a href="{{ $section('pricing') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Pricing</a>
                    </nav>
                    <nav aria-label="Who it's for" class="flex flex-col gap-1">
                        <span class="pb-1.5 text-[13px] font-bold tracking-[0.06em] text-white">FOR</span>
                        <a href="{{ route('register') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Households</a>
                        <a href="{{ route('register', ['type' => 'agent']) }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Landlords and agents</a>
                        <a href="{{ route('register', ['type' => 'business']) }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Businesses and body corporates</a>
                    </nav>
                    <nav aria-label="Metros" class="flex flex-col gap-1">
                        <span class="pb-1.5 text-[13px] font-bold tracking-[0.06em] text-white">METROS</span>
                        @foreach ($footerMetros as $footerMetro)
                            <a href="{{ route('metro.show', $footerMetro) }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">{{ $footerMetro->short_name }}</a>
                        @endforeach
                    </nav>
                    <nav aria-label="Company" class="flex flex-col gap-1">
                        <span class="pb-1.5 text-[13px] font-bold tracking-[0.06em] text-white">COMPANY</span>
                        <a href="{{ route('privacy') }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Privacy and POPIA</a>
                        @if ($contact)
                            <a href="mailto:{{ $contact }}" class="py-1.5 text-night-text no-underline hover:text-white hover:underline">Contact</a>
                        @endif
                    </nav>
                </div>
                <div class="border-t border-night-rule pt-6 text-sm text-night-muted">© {{ now()->year }} {{ config('auditor.company_name') ?: config('app.name') }}</div>
            </div>
        </footer>
    </body>
</html>
