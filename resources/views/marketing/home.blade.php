@extends('marketing.layout')

@section('title', 'Dispute a wrong municipal bill | '.config('app.name'))
@section('description', 'Wrong municipal bill? Send a photo. Prepaid, pay as you go: we find estimated readings, wrong tariffs and outage charges, then write and chase your dispute. Every municipality in South Africa.')
@section('canonical', route('home'))

@php
    $checkUrl = auth()->check() ? route('bills.index') : route('register');
@endphp

@include('marketing.partials.jsonld', ['data' => [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => route('home').'#organization',
            'name' => config('app.name'),
            'url' => route('home'),
            'logo' => asset('apple-touch-icon.png'),
        ],
        [
            '@type' => 'Service',
            'serviceType' => 'Municipal bill checks and disputes',
            'provider' => ['@id' => route('home').'#organization'],
            'areaServed' => ['@type' => 'Country', 'name' => 'South Africa'],
            'offers' => ['@type' => 'Offer', 'price' => $sitePrices['billCheckAmount'], 'priceCurrency' => 'ZAR', 'description' => 'Bill check'],
        ],
        [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($item) => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $faq),
        ],
    ],
]])

@section('content')
    <section aria-labelledby="hero-h" class="relative isolate overflow-hidden pt-[clamp(48px,7vw,88px)] pb-[clamp(56px,8vw,104px)]">
        <div aria-hidden="true" class="bg-glow absolute inset-0 -z-10"></div>
        <div aria-hidden="true" class="bg-grid absolute inset-0 -z-10"></div>
        <div class="mx-auto grid max-w-[1200px] grid-cols-[repeat(auto-fit,minmax(min(480px,100%),1fr))] items-center gap-14 px-[clamp(16px,4vw,40px)]">
            <div class="flex flex-col gap-6">
                <span class="flex flex-wrap items-center gap-2 font-mono text-xs leading-[18px] font-semibold tracking-[0.1em] text-cyan">
                    <span class="rounded-full border border-cyan/40 bg-cyan/10 px-2.5 py-0.5">PREPAID</span>
                    MUNICIPAL BILL CHECKS AND DISPUTES · SOUTH AFRICA
                </span>
                <h1 id="hero-h" class="text-[clamp(38px,4.6vw,64px)] leading-[1.04] font-extrabold tracking-[-0.02em] text-balance">Wrong municipal bill? We find the error and <span class="text-gradient">get it fixed.</span></h1>
                <p class="max-w-[560px] text-[clamp(18px,1.6vw,20px)] leading-[1.55] text-ink-2">Send us a photo of your bill. We check every line for estimated readings, wrong tariffs, outage charges and sudden spikes. Then we email the dispute to your municipality, read its replies and chase it until the credit is on your account. A prepaid service: top up, and pay only for the checks and disputes you use.</p>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ $checkUrl }}" class="glow flex h-14 items-center gap-2.5 rounded-lg bg-action px-6 text-lg font-semibold text-ground no-underline hover:bg-action-ink hover:text-ground">
                        @include('marketing.partials.camera')
                        {{ $checkLabel }}
                    </a>
                    <a href="#how" class="flex h-14 items-center rounded-lg border border-dots bg-paper/70 px-[22px] text-lg font-semibold text-ink no-underline hover:border-action hover:text-ink">See how it works</a>
                </div>
                <ul class="flex flex-wrap gap-x-5 gap-y-2 text-[15px] leading-[22px] text-ink-2">
                    @foreach ([
                        ['Prepaid: '.($sitePrices['welcome'] ? "{$sitePrices['welcome']} of credit on us" : "{$sitePrices['billCheck']} a bill check"), 'text-cyan'],
                        ['No monthly or setup fees', 'text-action'],
                        ['You approve every letter', 'text-orchid'],
                        ['Protected under POPIA', 'text-indigo'],
                    ] as [$tick, $tone])
                        <li class="flex items-center gap-1.5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $tone }}"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                            {{ $tick }}
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- What a check covers, without made-up figures. --}}
            <div role="img" aria-label="Every bill is checked for estimated meter readings, the wrong tariff, charges during outages and usage spikes, then disputed if you want" class="relative flex flex-col items-center">
                <div class="border-brand glow w-full max-w-[480px] rounded-2xl p-6 sm:p-7">
                    <div class="flex items-center justify-between gap-3 border-b border-rule pb-4">
                        <span class="font-mono text-xs font-semibold tracking-[0.12em] text-muted">EVERY BILL, LINE BY LINE</span>
                        <span class="bg-brand rounded-full px-2.5 py-0.5 font-mono text-xs font-bold text-ground">PREPAID</span>
                    </div>
                    <ul class="flex flex-col">
                        @foreach ([
                            ['Meter readings', 'Read, or estimated (E)?', 'text-cyan', 'bg-cyan/12', '<path d="M12 14l4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>'],
                            ['Tariff category', 'Right for your property?', 'text-indigo', 'bg-indigo/12', '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><circle cx="7.5" cy="7.5" r="1"/>'],
                            ['Outage days', 'Billed while supply was off?', 'text-orchid', 'bg-orchid/12', '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>'],
                            ['Usage history', 'Far above your usual?', 'text-action', 'bg-action/12', '<path d="M22 7l-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>'],
                        ] as [$what, $question, $tone, $wash, $icon])
                            <li class="flex items-center gap-3.5 border-b border-dotted border-dots py-3.5 last:border-0">
                                <span class="flex size-10 flex-none items-center justify-center rounded-xl {{ $wash }} {{ $tone }}">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
                                </span>
                                <span class="flex flex-1 flex-col leading-tight">
                                    <span class="font-bold">{{ $what }}</span>
                                    <span class="text-sm text-ink-2">{{ $question }}</span>
                                </span>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $tone }}"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex flex-wrap items-center gap-1.5 rounded-xl bg-ground/80 p-3 text-sm">
                        <span class="text-muted">Then, if you want:</span>
                        @foreach (['Dispute written', 'Emailed', 'Chased', 'Credit'] as $stage)
                            @if (! $loop->first)
                                <span aria-hidden="true" class="text-action">→</span>
                            @endif
                            <span class="rounded-md border border-rule bg-paper px-2 py-0.5 font-semibold">{{ $stage }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="relative -mt-5 flex w-full max-w-[480px] flex-wrap justify-end gap-2.5 pr-3">
                    <span class="flex -rotate-1 items-center gap-2 rounded-lg border border-flag/60 bg-paper px-3.5 py-2 text-[15px] shadow-[0_8px_20px_rgba(0,0,0,.45)]">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="text-flag-light"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        Your dispute deadline, shown
                    </span>
                </div>
            </div>
        </div>
    </section>

    @include('marketing.partials.proof')

    <section id="errors" aria-labelledby="errors-h" class="scroll-mt-4 bg-paper py-[clamp(64px,8vw,96px)]">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-12 px-[clamp(16px,4vw,40px)]">
            <div class="flex max-w-[760px] flex-col gap-3.5">
                <h2 id="errors-h" class="text-[clamp(32px,4vw,48px)] leading-[1.08] font-extrabold tracking-tight text-balance">The four municipal billing errors we check for</h2>
                <p class="text-lg text-ink-2">Today these get fixed one case at a time, by councillors, ratepayer groups and the Public Protector. Many follow patterns we can spot on the bill itself.</p>
            </div>
            @include('marketing.partials.checks')
        </div>
    </section>

    <section id="how" aria-labelledby="how-h" class="scroll-mt-4 py-[clamp(64px,8vw,96px)]">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-12 px-[clamp(16px,4vw,40px)]">
            <div class="flex max-w-[760px] flex-col gap-3.5">
                <h2 id="how-h" class="text-[clamp(32px,4vw,48px)] leading-[1.08] font-extrabold tracking-tight text-balance">How we fix a wrong municipal bill</h2>
                <p class="text-lg text-ink-2">From the photo of your bill to the credit on your account, this is exactly what happens.</p>
            </div>
            @include('marketing.partials.steps')
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(300px,100%),1fr))] gap-4">
                @foreach ([
                    ['Deadlines set for your municipality', 'Each municipality has its own dispute window. We show your date as soon as we check, and remind you before it closes.', 'bg-cyan'],
                    ['Keep paying the undisputed part', 'We work out that amount, so your account stays in good standing while the dispute runs.', 'bg-orchid'],
                    ['Refunds arrive as account credit', 'Municipalities usually correct the bill rather than pay cash. We tell you when the credit lands.', 'bg-indigo'],
                ] as [$title, $text, $dot])
                    <div class="flex flex-col gap-1.5 rounded-lg border border-rule bg-paper p-5">
                        <h3 class="flex items-center gap-2.5 text-lg font-bold"><span aria-hidden="true" class="size-2.5 flex-none rounded-full {{ $dot }}"></span>{{ $title }}</h3>
                        <p class="text-base text-ink-2">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section id="pricing" aria-labelledby="pricing-h" class="relative isolate scroll-mt-4 overflow-hidden bg-paper py-[clamp(64px,8vw,96px)]">
        <div aria-hidden="true" class="bg-glow-low absolute inset-0 -z-10"></div>
        <div class="mx-auto flex max-w-[1200px] flex-col gap-12 px-[clamp(16px,4vw,40px)]">
            <div class="flex max-w-[760px] flex-col gap-3.5">
                <span class="font-mono text-xs font-semibold tracking-[0.12em] text-cyan">A PREPAID SERVICE</span>
                <h2 id="pricing-h" class="text-[clamp(32px,4vw,48px)] leading-[1.08] font-extrabold tracking-tight text-balance">Prepaid. Pay as you go. <span class="text-gradient">No monthly or setup fees.</span></h2>
                <p class="text-lg text-ink-2">Top up your credit by card, and each bill check or dispute comes off it. One home, a rental portfolio or a business: the same price per job, and you always see it before we start.</p>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(320px,100%),1fr))] gap-6">
                <article class="border-brand glow flex flex-col gap-5 rounded-[10px] p-7">
                    <h3 class="flex items-center justify-between gap-3 text-xl font-bold">Bill check <span class="bg-brand rounded-full px-2.5 py-0.5 font-mono text-xs font-bold text-ground">PREPAID</span></h3>
                    <p class="flex flex-col"><span class="text-gradient font-mono text-5xl leading-none font-semibold">{{ $prices['billCheck'] }}</span><span class="mt-2 text-base text-muted">a bill, from prepaid credit</span></p>
                    <ul class="flex flex-1 flex-col gap-2 text-base">
                        <li>We read every line and run all four checks</li>
                        <li>You see what's wrong, what it's worth and your deadline</li>
                        <li>If we can't read your bill, the check is refunded</li>
                    </ul>
                    <a href="{{ $checkUrl }}" class="flex h-12 items-center justify-center rounded-lg bg-action px-5 font-semibold text-ground no-underline hover:bg-action-ink hover:text-ground">{{ $checkLabel }}</a>
                </article>
                <article class="flex flex-col gap-5 rounded-[10px] border border-rule bg-well p-7">
                    <h3 class="text-xl font-bold">Dispute</h3>
                    <p class="flex flex-col"><span class="font-mono text-5xl leading-none font-semibold text-orchid">{{ $prices['dispute'] }}</span><span class="mt-2 text-base text-muted">a dispute, from prepaid credit, only when you want one</span></p>
                    <ul class="flex flex-1 flex-col gap-2 text-base">
                        <li>The letter, written from your bill's evidence</li>
                        <li>Emailed to your municipality, with its replies read for you</li>
                        <li>Chased and escalated until the credit shows</li>
                    </ul>
                </article>
                <article class="flex flex-col gap-5 rounded-[10px] border border-rule bg-well p-7">
                    <h3 class="text-xl font-bold">How prepaid works</h3>
                    <ul class="flex flex-1 flex-col gap-2 text-base">
                        @foreach (array_filter([
                            ["Top up from {$prices['smallestTopUp']} by card. Credit doesn't expire.", 'text-cyan'],
                            ['No monthly fees, no setup fees, nothing to cancel', 'text-action'],
                            ["Landlords and businesses pay the same per bill: forward every unit's bill to one address", 'text-indigo'],
                            $prices['welcome'] ? ["{$prices['welcome']} of credit to start, on us", 'text-orchid'] : null,
                        ]) as [$line, $tone])
                            <li class="flex gap-2">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="mt-1 flex-none {{ $tone }}"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                                {{ $line }}
                            </li>
                        @endforeach
                    </ul>
                    <p class="flex flex-wrap gap-x-5 gap-y-2">
                        <a href="{{ route('register', ['type' => 'agent']) }}" class="font-semibold">For rental portfolios</a>
                        <a href="{{ route('register', ['type' => 'business']) }}" class="font-semibold">For businesses</a>
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section id="metros" aria-labelledby="metros-h" class="scroll-mt-4 py-[clamp(64px,8vw,96px)]">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-8 px-[clamp(16px,4vw,40px)]">
            <div class="flex max-w-[760px] flex-col gap-3.5">
                <h2 id="metros-h" class="text-[clamp(28px,3.4vw,40px)] leading-[1.1] font-extrabold tracking-tight text-balance">Every municipality in South Africa</h2>
                <p class="text-lg text-ink-2">All 257: the 8 metros and every local and district municipality. We read which one bills you from your bill and find where its account queries go, so you never need to know where to send anything. Each has its own dispute process and deadlines, and we follow them.</p>
            </div>
            <h3 class="text-xl font-bold">Billing disputes in {{ $metros->pluck('short_name')->join(', ', ' and ') }}</h3>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(300px,100%),1fr))] gap-4">
                @foreach ($metros as $metro)
                    <a href="{{ route('metro.show', $metro) }}" class="flex min-h-[88px] items-center justify-between gap-3 rounded-lg border border-rule bg-paper px-6 text-ink no-underline transition-shadow hover:border-action hover:text-ink hover:shadow-[0_0_28px_rgb(179_154_255/0.25)]">
                        <span class="flex flex-col">
                            <span class="text-xl font-bold">{{ $metro->short_name }}</span>
                            <span class="text-[15px] text-muted">Dispute {{ str_starts_with($metro->name, 'e') ? 'an' : 'a' }} {{ $metro->name }} bill{{ $metro->area_name ? " ({$metro->area_name})" : '' }}</span>
                        </span>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#B39AFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                @endforeach
            </div>
            <p class="text-base text-ink-2">Billed by another municipality? <a href="{{ $checkUrl }}" class="font-semibold">Send us your bill</a>: we cover it too.</p>
        </div>
    </section>

    <section id="faq" aria-labelledby="faq-h" class="scroll-mt-4 bg-paper py-[clamp(64px,8vw,96px)]">
        <div class="mx-auto flex max-w-[1200px] flex-wrap items-start gap-x-16 gap-y-10 px-[clamp(16px,4vw,40px)]">
            <div class="flex flex-[1_1_320px] flex-col gap-3.5">
                <h2 id="faq-h" class="text-[clamp(32px,4vw,48px)] leading-[1.08] font-extrabold tracking-tight text-balance">Questions about disputing a municipal bill</h2>
                @if (config('auditor.contact_email'))
                    <p class="text-lg text-ink-2">Something else? <a href="mailto:{{ config('auditor.contact_email') }}" class="font-semibold">Ask us</a>.</p>
                @endif
            </div>
            @include('marketing.partials.faq')
        </div>
    </section>

    @include('marketing.partials.cta')
@endsection
