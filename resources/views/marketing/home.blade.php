@extends('marketing.layout')

@section('title', 'Dispute a wrong municipal bill | Municipal Bill Auditor')
@section('description', 'Wrong municipal bill? Send a photo. We find estimated readings, wrong tariffs and outage charges, then write and chase your dispute. Every municipality in South Africa.')
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
    <section aria-labelledby="hero-h" class="pt-[clamp(48px,7vw,88px)] pb-[clamp(56px,8vw,104px)]">
        <div class="mx-auto grid max-w-[1200px] grid-cols-[repeat(auto-fit,minmax(min(480px,100%),1fr))] items-center gap-14 px-[clamp(16px,4vw,40px)]">
            <div class="flex flex-col gap-6">
                <span class="font-mono text-xs leading-[18px] font-semibold tracking-[0.1em] text-flag-ink">MUNICIPAL BILL CHECKS AND DISPUTES · SOUTH AFRICA</span>
                <h1 id="hero-h" class="text-[clamp(38px,4.6vw,64px)] leading-[1.04] font-extrabold tracking-[-0.02em] text-balance">Wrong municipal bill? We find the error and get it fixed.</h1>
                <p class="max-w-[560px] text-[clamp(18px,1.6vw,20px)] leading-[1.55] text-ink-2">Send us a photo of your bill. We check every line for estimated readings, wrong tariffs, outage charges and sudden spikes. Then we email the dispute to your municipality, read its replies and chase it until the credit is on your account. Every municipality in South Africa, by email.</p>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ $checkUrl }}" class="flex h-14 items-center gap-2.5 rounded-lg bg-action px-6 text-lg font-semibold text-ground no-underline hover:bg-action-ink hover:text-ground">
                        @include('marketing.partials.camera')
                        {{ $checkLabel }}
                    </a>
                    <a href="#how" class="flex h-14 items-center rounded-lg border border-dots bg-paper px-[22px] text-lg font-semibold text-ink no-underline hover:border-ink hover:text-ink">See how it works</a>
                </div>
                <ul class="flex flex-wrap gap-x-5 gap-y-2 text-[15px] leading-[22px] text-ink-2">
                    @foreach ([$sitePrices['welcome'] ? "{$sitePrices['welcome']} of credit on us" : "{$sitePrices['billCheck']} a bill check", 'You approve every letter', 'Your data protected under POPIA'] as $tick)
                        <li class="flex items-center gap-1.5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#B39AFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                            {{ $tick }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <div role="img" aria-label="An example municipal bill with the estimated water reading circled, the overcharge and the dispute deadline marked" class="flex flex-col items-center">
                <div class="w-full max-w-[470px] -rotate-[1.5deg] rounded bg-paper px-7 pt-7 pb-10 font-mono text-sm leading-5 border border-rule shadow-[0_24px_48px_rgba(0,0,0,.45)]">
                    <div class="flex items-baseline justify-between border-b-2 border-ink pb-2.5">
                        <span class="font-sans text-[15px] font-extrabold tracking-[0.06em]">STATEMENT OF ACCOUNT</span>
                        <span class="text-xs text-muted">SEP 2026</span>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-rule py-2.5 text-xs text-muted">
                        <span>ACC 5021 4478 2210</span>
                        <span>RESIDENTIAL</span>
                    </div>
                    <div class="flex items-center justify-between gap-2 border-b border-dotted border-dots py-3">
                        <span class="flex items-center gap-2">Water 38 kL
                            <span class="relative inline-block px-1 font-semibold text-flag-ink">(E)<span class="absolute -inset-x-[7px] -inset-y-1.5 -rotate-[5deg] rounded-[50%] border-[2.5px] border-flag"></span></span>
                            <span class="size-5 rounded-full bg-flag text-center font-sans text-xs leading-5 font-bold text-ground">1</span>
                        </span>
                        <span class="font-semibold text-flag-ink underline decoration-flag decoration-2 underline-offset-4">R1,596.00</span>
                    </div>
                    @foreach ([['Sanitation', 'R562.05'], ['Refuse removal', 'R318.40']] as [$line, $amount])
                        <div class="flex justify-between border-b border-dotted border-dots py-3"><span>{{ $line }}</span><span>{{ $amount }}</span></div>
                    @endforeach
                    <div class="flex justify-between border-b-2 border-ink py-3"><span>Property rates</span><span>R520.00</span></div>
                    <div class="flex justify-between pt-3 font-semibold"><span>TOTAL DUE</span><span>R2,996.45</span></div>
                </div>
                <div class="relative -mt-6 flex w-full max-w-[470px] flex-col items-end gap-2.5">
                    <div class="flex max-w-[340px] rotate-1 items-center gap-3 rounded-lg border-2 border-flag bg-paper px-4 py-3 shadow-[0_8px_20px_rgba(0,0,0,.45)]">
                        <span class="size-6 flex-none rounded-full bg-flag text-center text-[13px] leading-6 font-bold text-ground">1</span>
                        <span class="flex flex-col leading-[1.3]">
                            <span class="font-bold">Estimated, not read</span>
                            <span class="text-sm text-ink-2">38 kL billed, you usually use 16. <span class="font-mono font-semibold text-flag-ink">R924.00</span> too much.</span>
                        </span>
                    </div>
                    <div class="flex -rotate-1 items-center gap-2.5 rounded-lg border border-rule bg-night px-4 py-2.5 text-[15px] text-white">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#F59E6B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span>Dispute by <b>2 Nov</b> · 22 days left</span>
                    </div>
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
                    ['Deadlines set for your municipality', 'Each municipality has its own dispute window. We show your date as soon as we check, and remind you before it closes.'],
                    ['Keep paying the undisputed part', 'We work out that amount, so your account stays in good standing while the dispute runs.'],
                    ['Refunds arrive as account credit', 'Municipalities usually correct the bill rather than pay cash. We tell you when the credit lands.'],
                ] as [$title, $text])
                    <div class="flex flex-col gap-1.5 rounded-lg border border-rule bg-paper p-5">
                        <h3 class="text-lg font-bold">{{ $title }}</h3>
                        <p class="text-base text-ink-2">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section id="pricing" aria-labelledby="pricing-h" class="scroll-mt-4 bg-paper py-[clamp(64px,8vw,96px)]">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-12 px-[clamp(16px,4vw,40px)]">
            <div class="flex max-w-[760px] flex-col gap-3.5">
                <h2 id="pricing-h" class="text-[clamp(32px,4vw,48px)] leading-[1.08] font-extrabold tracking-tight text-balance">Pay as you go. No monthly or setup fees.</h2>
                <p class="text-lg text-ink-2">One home, a rental portfolio or a business: you pay the same price per job, from prepaid credit. You always see the price before we start a job.</p>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(320px,100%),1fr))] gap-6">
                <article class="flex flex-col gap-5 rounded-[10px] border-2 border-action bg-paper p-7">
                    <h3 class="text-xl font-bold">Bill check</h3>
                    <p class="flex flex-col"><span class="font-mono text-5xl leading-none font-semibold">{{ $prices['billCheck'] }}</span><span class="mt-2 text-base text-muted">a bill, from prepaid credit</span></p>
                    <ul class="flex flex-1 flex-col gap-2 text-base">
                        <li>We read every line and run all four checks</li>
                        <li>You see what's wrong, what it's worth and your deadline</li>
                        <li>If we can't read your bill, the check is refunded</li>
                    </ul>
                    <a href="{{ $checkUrl }}" class="flex h-12 items-center justify-center rounded-lg bg-action px-5 font-semibold text-ground no-underline hover:bg-action-ink hover:text-ground">{{ $checkLabel }}</a>
                </article>
                <article class="flex flex-col gap-5 rounded-[10px] border border-rule bg-well p-7">
                    <h3 class="text-xl font-bold">Dispute</h3>
                    <p class="flex flex-col"><span class="font-mono text-5xl leading-none font-semibold">{{ $prices['dispute'] }}</span><span class="mt-2 text-base text-muted">a dispute, only when you want one</span></p>
                    <ul class="flex flex-1 flex-col gap-2 text-base">
                        <li>The letter, written from your bill's evidence</li>
                        <li>Emailed to your municipality, with its replies read for you</li>
                        <li>Chased and escalated until the credit shows</li>
                    </ul>
                </article>
                <article class="flex flex-col gap-5 rounded-[10px] border border-rule bg-well p-7">
                    <h3 class="text-xl font-bold">How paying works</h3>
                    <ul class="flex flex-1 flex-col gap-2 text-base">
                        <li>Top up from {{ $prices['smallestTopUp'] }} by card. Credit doesn't expire.</li>
                        <li>No monthly fees, no setup fees, nothing to cancel</li>
                        <li>Landlords and businesses pay the same per bill: forward every unit's bill to one address</li>
                        @if ($prices['welcome'])
                            <li>{{ $prices['welcome'] }} of credit to start, on us</li>
                        @endif
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
                    <a href="{{ route('metro.show', $metro) }}" class="flex min-h-[88px] items-center justify-between gap-3 rounded-lg border border-rule bg-paper px-6 text-ink no-underline hover:border-action hover:text-ink">
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
