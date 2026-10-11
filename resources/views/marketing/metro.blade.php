@extends('marketing.layout')

@php
    $checkUrl = auth()->check() ? route('bills.index') : route('register');
    $article = str_starts_with($metro->name, 'e') ? 'an' : 'a';
    $place = $metro->area_name ? "{$metro->short_name} ({$metro->area_name})" : $metro->short_name;
@endphp

@section('title', "{$metro->short_name} billing disputes | Municipal Bill Auditor")
@section('description', "Wrong {$metro->name} bill? We check it free for estimated readings, wrong tariffs and outage charges, then write your dispute and chase it to the credit.")
@section('canonical', route('metro.show', $metro))

@include('marketing.partials.jsonld', ['data' => [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Service',
            'serviceType' => "Municipal bill disputes in {$metro->short_name}",
            'provider' => ['@type' => 'Organization', 'name' => config('app.name'), 'url' => route('home')],
            'areaServed' => ['@type' => 'City', 'name' => $metro->area_name ?? $metro->short_name],
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'ZAR', 'description' => 'Free bill check'],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => "{$metro->short_name} billing disputes", 'item' => route('metro.show', $metro)],
            ],
        ],
    ],
]])

@section('content')
    <section aria-labelledby="hero-h" class="pt-[clamp(40px,6vw,72px)] pb-[clamp(48px,7vw,88px)]">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-6 px-[clamp(16px,4vw,40px)]">
            <nav aria-label="Breadcrumb" class="text-sm text-muted">
                <a href="{{ route('home') }}">Home</a> <span aria-hidden="true">/</span> {{ $metro->short_name }}
            </nav>
            <h1 id="hero-h" class="max-w-[900px] text-[clamp(36px,5vw,64px)] leading-[1.04] font-extrabold tracking-[-0.02em] text-balance">Dispute {{ $article }} {{ $metro->name }} bill</h1>
            <p class="max-w-[640px] text-[clamp(18px,1.6vw,20px)] text-ink-2">Send us your {{ $place }} municipal bill. We check it for estimated readings, wrong tariffs, outage charges and spikes, then write the dispute to {{ $metro->billing_addressee }} and follow it until the credit is on your account.</p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ $checkUrl }}" class="flex h-14 items-center gap-2.5 rounded-lg bg-action px-6 text-lg font-semibold text-white no-underline hover:bg-action-ink hover:text-white">
                    @include('marketing.partials.camera')
                    Check my bill free
                </a>
                <a href="#letter" class="flex h-14 items-center rounded-lg border border-[#a9b1bb] bg-paper px-[22px] text-lg font-semibold text-ink no-underline hover:border-ink hover:text-ink">See a sample letter</a>
            </div>
        </div>
    </section>

    <section id="process" aria-labelledby="process-h" class="bg-paper py-[clamp(56px,7vw,88px)]">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-10 px-[clamp(16px,4vw,40px)]">
            <div class="flex max-w-[760px] flex-col gap-3.5">
                <h2 id="process-h" class="text-[clamp(28px,3.6vw,44px)] leading-[1.1] font-extrabold tracking-tight text-balance">How a {{ $metro->short_name }} billing dispute works</h2>
                @unless ($metro->rules_verified)
                    <p class="rounded-md bg-flag-soft px-4 py-3 text-base text-flag-ink">We're still confirming {{ $metro->name }}'s own deadlines, so the days below are the cautious working times we use. When we check your bill, we show the date that applies to you.</p>
                @endunless
            </div>
            <ol class="grid grid-cols-[repeat(auto-fit,minmax(min(250px,100%),1fr))] gap-6">
                <li class="flex flex-col gap-2 border-t-4 border-action pt-4">
                    <span class="font-mono text-sm font-semibold text-action">{{ $metro->dispute_window_days }} DAYS</span>
                    <h3 class="text-xl font-bold">Dispute soon after the bill</h3>
                    <p class="text-base text-ink-2">We aim to lodge within {{ $metro->dispute_window_days }} days of the bill date, while the reading and the dates are fresh.</p>
                </li>
                <li class="flex flex-col gap-2 border-t-4 border-action pt-4">
                    <span class="font-mono text-sm font-semibold text-action">WHERE</span>
                    <h3 class="text-xl font-bold">Lodged with billing queries</h3>
                    <p class="text-base text-ink-2">The letter goes to {{ $metro->billing_addressee }}@if ($metro->billing_email) (<span class="font-mono text-sm">{{ $metro->billing_email }}</span>)@endif, with your bill and meter photo attached.</p>
                </li>
                <li class="flex flex-col gap-2 border-t-4 border-action pt-4">
                    <span class="font-mono text-sm font-semibold text-action">{{ $metro->reply_window_days }} DAYS</span>
                    <h3 class="text-xl font-bold">We wait for a reply</h3>
                    <p class="text-base text-ink-2">We record the reference number and give {{ $metro->short_name }} {{ $metro->reply_window_days }} days to reply before we follow up.</p>
                </li>
                <li class="flex flex-col gap-2 border-t-4 border-dashed border-[#a9b1bb] pt-4">
                    <span class="font-mono text-sm font-semibold text-muted">ONLY IF NEEDED</span>
                    <h3 class="text-xl font-bold">Ward councillor, then the Public Protector</h3>
                    <p class="text-base text-ink-2">No reply? We write to your ward councillor. If it's still not resolved {{ $metro->councillor_window_days }} days later, we take it to the Public Protector.</p>
                </li>
            </ol>
        </div>
    </section>

    <section id="letter" aria-labelledby="letter-h" class="scroll-mt-4 py-[clamp(56px,7vw,88px)]">
        <div class="mx-auto grid max-w-[1200px] grid-cols-[repeat(auto-fit,minmax(min(420px,100%),1fr))] items-start gap-12 px-[clamp(16px,4vw,40px)]">
            <div class="flex flex-col gap-3.5">
                <h2 id="letter-h" class="text-[clamp(28px,3.6vw,44px)] leading-[1.1] font-extrabold tracking-tight text-balance">A sample {{ $metro->short_name }} dispute letter</h2>
                <p class="text-lg text-ink-2">This is the letter we'd write for the example bill on our home page: a water reading that was estimated, and water billed while the supply was off. Yours is built from your own bill, and nothing is sent until you approve it.</p>
                <p class="text-base text-ink-2">It asks for the things that get a bill corrected: an actual reading, the outage days reversed, a corrected account and a reference number. It also says you'll keep paying the undisputed part.</p>
            </div>
            <figure class="m-0 flex flex-col gap-2">
                <div class="rounded bg-paper p-6 font-mono text-[13px] leading-[1.6] whitespace-pre-wrap shadow-[0_1px_2px_rgba(18,22,28,.08),0_16px_36px_rgba(18,22,28,.10)] sm:p-8">{{ $letter->body }}</div>
                <figcaption class="text-sm text-muted">Example figures. Square brackets show where your details go.</figcaption>
            </figure>
        </div>
    </section>

    @include('marketing.partials.proof', ['heading' => "Billing errors in {$metro->short_name} can be fixed."])

    <section id="errors" aria-labelledby="errors-h" class="bg-paper py-[clamp(56px,7vw,88px)]">
        <div class="mx-auto flex max-w-[1200px] flex-col gap-10 px-[clamp(16px,4vw,40px)]">
            <h2 id="errors-h" class="max-w-[760px] text-[clamp(28px,3.6vw,44px)] leading-[1.1] font-extrabold tracking-tight text-balance">What we check on {{ $article }} {{ $metro->short_name }} bill</h2>
            @include('marketing.partials.checks')
        </div>
    </section>

    <section id="faq" aria-labelledby="faq-h" class="py-[clamp(56px,7vw,88px)]">
        <div class="mx-auto flex max-w-[1200px] flex-wrap items-start gap-x-16 gap-y-10 px-[clamp(16px,4vw,40px)]">
            <div class="flex flex-[1_1_320px] flex-col gap-3.5">
                <h2 id="faq-h" class="text-[clamp(28px,3.6vw,44px)] leading-[1.1] font-extrabold tracking-tight text-balance">Questions about {{ $metro->short_name }} bill disputes</h2>
                @if ($otherMetros->isNotEmpty())
                    <p class="text-base text-ink-2">Billed somewhere else? See
                        @foreach ($otherMetros as $other)
                            <a href="{{ route('metro.show', $other) }}" class="font-semibold">{{ $other->short_name }}</a>@if (! $loop->last){{ $loop->remaining === 1 ? ' or ' : ', ' }}@endif
                        @endforeach.
                    </p>
                @endif
            </div>
            @include('marketing.partials.faq')
        </div>
    </section>

    @include('marketing.partials.cta')
@endsection
