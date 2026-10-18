@extends('site.layout')

@php
    $playUrl = auth()->check() ? route('play') : route('register');
@endphp

@section('title', config('app.name').': take the farm, write the rules, guard the treasury')
@section('description', 'A free online strategy game for your phone. Join a faction, rule the valley from the Farmhouse, catch the rulers rewriting the laws, run your own shop, and rise up.')
@section('canonical', route('home'))

@section('content')
    {{-- The poster --}}
    <section class="relative isolate flex min-h-[680px] items-start overflow-hidden bg-[#0f0b17] md:min-h-[640px] lg:h-[min(86vh,820px)]">
        {{-- On a phone the scene sits under the words; on a wider screen the words sit over the sky. --}}
        <div class="absolute inset-x-0 bottom-0 h-[52%] md:inset-0 md:h-full">
            @include('site.partials.poster')
        </div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/40 via-transparent to-transparent md:bg-gradient-to-br md:from-black/70 md:via-black/20" aria-hidden="true"></div>
        <div class="relative mx-auto flex w-full max-w-[1200px] flex-col gap-5 px-[clamp(16px,4vw,40px)] pt-[clamp(40px,7vw,88px)] pb-12">
            <h1 class="text-[clamp(56px,8.5vw,108px)] leading-[0.9] font-black tracking-tight text-[#f3e6c4] uppercase [text-shadow:0_3px_0_rgba(0,0,0,0.45),0_0_40px_rgba(0,0,0,0.5)]"><span class="block">Revenue</span><span class="block">Guard</span></h1>
            <p class="flex max-w-[520px] items-center gap-4 font-mono text-[clamp(12px,1.4vw,15px)] tracking-[0.32em] text-[#f3e6c4]/90">
                <span class="h-px flex-1 bg-[#f3e6c4]/60" aria-hidden="true"></span>
                HOLLOWMERE · SEASON ONE
                <span class="h-px flex-1 bg-[#f3e6c4]/60" aria-hidden="true"></span>
            </p>
            <p class="max-w-[560px] text-[clamp(17px,2.1vw,25px)] leading-snug font-extrabold tracking-wide text-[#e0483b] uppercase [text-shadow:0_2px_0_rgba(0,0,0,0.5)]">
                The farm belongs to the animals.<br>
                The Farmhouse belongs to whoever takes it.
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <a href="{{ $playUrl }}" class="glow flex h-12 items-center rounded-md bg-brand px-6 text-[17px] font-bold text-ground no-underline hover:text-ground">Play the test season</a>
                <a href="{{ route('puzzle') }}" class="flex h-12 items-center rounded-md border border-[#f3e6c4]/50 bg-black/30 px-5 font-semibold text-[#f3e6c4] no-underline hover:border-[#f3e6c4] hover:text-[#f3e6c4]">Try today's puzzle</a>
            </div>
        </div>
    </section>

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-glow">
        <div class="bg-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
        <div class="relative mx-auto grid max-w-[1200px] items-center gap-12 px-[clamp(16px,4vw,40px)] py-[clamp(48px,8vw,104px)] lg:grid-cols-[1.1fr_0.9fr]">
            <div class="flex flex-col gap-6">
                <p class="font-mono text-[13px] tracking-[0.14em] text-action">THE ANIMALS RUN THE FARM NOW</p>
                <h2 class="text-[clamp(40px,6vw,72px)] leading-[1.02] font-extrabold tracking-tight">
                    Take the farm.<br>
                    Write the rules.<br>
                    <span class="text-gradient">Guard the treasury.</span>
                </h2>
                <p class="max-w-[560px] text-[19px] text-ink-2">
                    Pick an animal, join a faction and fight for the Farmhouse. Whoever holds it writes the laws on the Barn Wall, and once a week the rulers may secretly change one. Catch them, and the valley rises.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ $playUrl }}" class="glow flex h-12 items-center rounded-md bg-brand px-6 text-[17px] font-bold text-ground no-underline hover:text-ground">Play the test season</a>
                    <a href="{{ route('puzzle') }}" class="flex h-12 items-center rounded-md border border-rule px-5 font-semibold text-ink no-underline hover:border-action hover:text-ink">Try today's puzzle</a>
                </div>
                <p class="text-sm text-muted">Free. Plays in your phone's browser, about five minutes a visit. Adults 18 and older.</p>
            </div>

            {{-- The Wall, with one law quietly changed. --}}
            <figure class="border-brand rounded-xl p-6" aria-label="The Barn Wall">
                <figcaption class="mb-4 flex items-center justify-between font-mono text-[12px] tracking-[0.12em] text-muted">
                    <span>THE BARN WALL</span>
                    <span class="rounded-full bg-flag-soft px-2 py-0.5 text-flag-ink">CHANGED OVERNIGHT</span>
                </figcaption>
                <ol class="flex flex-col gap-3 text-[16px]">
                    <li class="flex gap-3"><span class="figures text-action">1</span><span>Every animal gives 8% of the Corn it gathers to the Farmhouse.</span></li>
                    <li class="flex gap-3"><span class="figures text-action">2</span><span>Every sale in the market pays <span class="rounded bg-flag-soft px-1 font-semibold text-flag-ink">4%</span> in Corn to the Farmhouse.</span></li>
                    <li class="flex gap-3"><span class="figures text-action">3</span><span>The Farmhouse hands back 50% of its grain tax as rations every morning.</span></li>
                    <li class="flex gap-3"><span class="figures text-action">4</span><span>No raids on House land for 4 hours after 22:00.</span></li>
                    <li class="flex gap-3"><span class="figures text-action">5</span><span>The Night Watch patrols the valley after dark.</span></li>
                </ol>
                <p class="mt-5 border-t border-rule-soft pt-4 text-sm text-ink-2">Yesterday the levy was 2%. A donkey with a saved copy of the Wall noticed at breakfast.</p>
            </figure>
        </div>
    </section>

    {{-- How it plays --}}
    <section id="how" class="mx-auto max-w-[1200px] px-[clamp(16px,4vw,40px)] py-[clamp(48px,7vw,88px)]">
        <h2 class="max-w-[760px] text-[clamp(28px,3.6vw,44px)] leading-[1.1] font-extrabold tracking-tight">Five minutes a visit, a new story every week</h2>
        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Spend your horseshoes', 'You hold up to ten, and one comes back every 45 minutes. Gather, raid, defend or craft.'],
                ['Read the Wall', 'Save a copy each day. If the House sneaks in a change, expose it within 72 hours for fame and Rosettes.'],
                ['Run your shop', 'Design hats, scarves and signs in the Workshop, make numbered copies, and sell them from your own shop.'],
                ['Rise up', 'Every unfair law adds to the unrest. At 60 anyone can call an uprising, and the Farmhouse changes hands.'],
            ] as [$step, $text])
                <div class="flex flex-col gap-3 rounded-xl border border-rule-soft bg-paper p-6">
                    <span class="figures text-gradient text-[28px] font-bold">{{ $loop->iteration }}</span>
                    <h3 class="text-[19px] font-bold">{{ $step }}</h3>
                    <p class="text-ink-2">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Factions and lone wolves --}}
    <section class="bg-glow-low border-y border-rule">
        <div class="mx-auto grid max-w-[1200px] gap-10 px-[clamp(16px,4vw,40px)] py-[clamp(48px,7vw,88px)] lg:grid-cols-2">
            <div class="flex flex-col gap-4">
                <h2 class="text-[clamp(28px,3.6vw,44px)] leading-[1.1] font-extrabold tracking-tight">Join a faction, or go it alone</h2>
                <p class="text-ink-2">Factions hold land, set their own rules for who may join, and raid each other to reach the Farmhouse. The faction that holds it is the House, and the House taxes everyone.</p>
                <p class="text-ink-2">Lone wolves answer to nobody. They loot faction treasuries and burn down rivals' homes, if the dogs and the Night Watch don't catch them first.</p>
            </div>
            <ul class="grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['Pig', 'Planner', 'Attacks you launch hit harder.'],
                    ['Dog', 'Guard', 'Your defence counts 30% more.'],
                    ['Horse', 'Builder', 'You craft with half the materials.'],
                    ['Sheep', 'Chorus', 'Your chants calm or stir the valley.'],
                    ['Goat', 'Auditor', 'You spot secret edits sooner.'],
                ] as [$animal, $role, $talent])
                    <li class="rounded-lg border border-rule-soft bg-paper px-4 py-3">
                        <span class="font-bold">{{ $animal }}</span> <span class="font-mono text-[12px] tracking-[0.1em] text-muted">{{ strtoupper($role) }}</span>
                        <p class="text-[15px] text-ink-2">{{ $talent }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Closing --}}
    <section class="mx-auto flex max-w-[860px] flex-col items-center gap-6 px-[clamp(16px,4vw,40px)] py-[clamp(56px,8vw,104px)] text-center">
        <h2 class="text-[clamp(30px,4vw,48px)] leading-[1.08] font-extrabold tracking-tight">The first season of <span class="text-gradient">Hollowmere</span> is open</h2>
        <p class="text-[18px] text-ink-2">It is free while we test it. Later seasons add real shops: makers will be able to cash out what they earn.</p>
        <a href="{{ $playUrl }}" class="glow flex h-12 items-center rounded-md bg-brand px-6 text-[17px] font-bold text-ground no-underline hover:text-ground">Make your animal</a>
    </section>
@endsection
