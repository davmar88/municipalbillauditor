@extends('site.layout')

@php
    $playUrl = auth()->check() ? route('play') : route('register');
@endphp

@section('title', config('app.name').': take the farm, write the rules, guard the treasury')
@section('description', 'A free online strategy game for your phone. Join a faction, rule the valley from the Farmhouse, catch the rulers rewriting the laws, run your own shop, and rise up.')
@section('canonical', route('home'))

@section('content')
    {{-- The poster, melting into the page on every side --}}
    <section class="relative overflow-hidden">
        <picture>
            <source media="(max-width: 700px)" srcset="/images/poster-640.jpg">
            <img src="/images/poster.jpg" width="1168" height="784" fetchpriority="high"
                alt="A pig stands on a stack of hay bales under a tattered red flag, with horses, a cow, sheep and hens around it, as storm clouds break into a golden sunset over the farm."
                class="poster-melt mx-auto block h-auto w-full max-w-[1280px]">
        </picture>
        <div class="relative mx-auto -mt-[7%] flex max-w-[1200px] flex-wrap items-center justify-center gap-3 px-[clamp(16px,4vw,40px)] pb-6">
            <a href="{{ $playUrl }}" class="glow flex h-12 items-center rounded-md bg-brand px-6 text-[17px] font-bold text-ground no-underline hover:text-ground">Play the test season</a>
            <a href="{{ route('puzzle') }}" class="flex h-12 items-center rounded-md border border-rule bg-ground/70 px-5 font-semibold text-ink no-underline hover:border-action hover:text-ink">Try today's puzzle</a>
            <a href="{{ route('wiki') }}" class="flex h-12 items-center rounded-md border border-rule bg-ground/70 px-5 font-semibold text-ink no-underline hover:border-action hover:text-ink">How the game works</a>
        </div>
    </section>

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-glow">
        <div class="bg-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
        <div class="relative mx-auto grid max-w-[1200px] items-center gap-12 px-[clamp(16px,4vw,40px)] py-[clamp(48px,8vw,104px)] lg:grid-cols-[1.1fr_0.9fr]">
            <div class="flex flex-col gap-6">
                <p class="font-mono text-[13px] tracking-[0.14em] text-action">THE ANIMALS RUN THE FARM NOW</p>
                <h1 class="text-[clamp(40px,6vw,72px)] leading-[1.02] font-extrabold tracking-tight">
                    Take the farm.<br>
                    Write the rules.<br>
                    <span class="text-gradient">Guard the treasury.</span>
                </h1>
                <p class="max-w-[560px] text-[19px] text-ink-2">
                    Pick an animal, join a faction and fight for the Farmhouse. Whoever holds it writes the laws on the Barn Wall, and once a week the rulers may secretly change one. Catch them, and the valley rises.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ $playUrl }}" class="glow flex h-12 items-center rounded-md bg-brand px-6 text-[17px] font-bold text-ground no-underline hover:text-ground">Play the test season</a>
                    <a href="{{ route('puzzle') }}" class="flex h-12 items-center rounded-md border border-rule px-5 font-semibold text-ink no-underline hover:border-action hover:text-ink">Try today's puzzle</a>
                    <a href="{{ route('wiki') }}" class="flex h-12 items-center rounded-md border border-rule px-5 font-semibold text-ink no-underline hover:border-action hover:text-ink">How the game works</a>
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
                ['Spend your horseshoes', 'You hold up to '.config('game.horseshoes.max').', one comes back every 45 minutes, and every 24 hours you are filled back up to '.config('game.horseshoes.max').'. Gather, raid, defend or craft.'],
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

    {{-- The valleys right now: animals, who's online, factions and what they hold --}}
    @if (! empty($valleys))
        <section id="valleys" class="mx-auto max-w-[1200px] px-[clamp(16px,4vw,40px)] py-[clamp(48px,7vw,88px)]">
            <h2 class="text-[clamp(28px,3.6vw,44px)] leading-[1.1] font-extrabold tracking-tight">The valleys right now</h2>
            <p class="mt-3 max-w-[760px] text-ink-2">Every valley takes up to {{ number_format(config('game.valleys.full_at')) }} active animals. When one is full, newcomers start in the next. Online means seen in the last {{ \App\Game\Census::ONLINE_MINUTES }} minutes.</p>

            <div class="mt-8 overflow-x-auto rounded-xl border border-rule-soft bg-paper">
                <table class="w-full min-w-[640px] text-left text-[15px]">
                    <thead class="border-b border-rule-soft font-mono text-[12px] tracking-[0.1em] text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3">VALLEY</th>
                            <th scope="col" class="px-4 py-3 text-right">ANIMALS</th>
                            <th scope="col" class="px-4 py-3 text-right">ONLINE NOW</th>
                            <th scope="col" class="px-4 py-3 text-right">FACTIONS</th>
                            <th scope="col" class="px-4 py-3">RULED BY</th>
                            <th scope="col" class="px-4 py-3 text-right">CORN</th>
                            <th scope="col" class="px-4 py-3 text-right">APPLES</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($valleys as $valley)
                            <tr class="border-b border-rule-soft last:border-0">
                                <th scope="row" class="px-4 py-3 font-bold">{{ $valley['name'] }}</th>
                                <td class="figures px-4 py-3 text-right">{{ number_format($valley['animals']) }}</td>
                                <td class="figures px-4 py-3 text-right"><span class="mr-1.5 inline-block size-2 rounded-full bg-emerald-500 align-middle" aria-hidden="true"></span>{{ number_format($valley['online']) }}</td>
                                <td class="figures px-4 py-3 text-right">{{ number_format(count($valley['factions'])) }}</td>
                                <td class="px-4 py-3 text-ink-2">{{ $valley['house'] ?? 'Nobody yet' }}</td>
                                <td class="figures px-4 py-3 text-right">{{ number_format($valley['corn']) }}</td>
                                <td class="figures px-4 py-3 text-right">{{ number_format($valley['apples']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @foreach ($valleys as $valley)
                <div class="mt-10 grid gap-6 lg:grid-cols-2">
                    <div>
                        <h3 class="text-[22px] font-bold">Factions of {{ $valley['name'] }}</h3>
                        @if (empty($valley['factions']))
                            <p class="mt-3 text-ink-2">No factions yet. Found the first one.</p>
                        @else
                            <div class="mt-3 overflow-x-auto rounded-xl border border-rule-soft bg-paper">
                                <table class="w-full text-left text-[15px]">
                                    <thead class="border-b border-rule-soft font-mono text-[12px] tracking-[0.1em] text-muted">
                                        <tr>
                                            <th scope="col" class="px-4 py-3">FACTION</th>
                                            <th scope="col" class="px-4 py-3 text-right">MEMBERS</th>
                                            <th scope="col" class="px-4 py-3 text-right">LAND HELD</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach (array_slice($valley['factions'], 0, 15) as $faction)
                                            <tr class="border-b border-rule-soft last:border-0">
                                                <th scope="row" class="px-4 py-2.5 font-semibold"><span class="mr-2 inline-block size-2.5 rounded-full align-middle" style="background: {{ $faction['colour'] }}" aria-hidden="true"></span>{{ $faction['name'] }}</th>
                                                <td class="figures px-4 py-2.5 text-right">{{ number_format($faction['members']) }}</td>
                                                <td class="figures px-4 py-2.5 text-right">{{ number_format($faction['land']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if (count($valley['factions']) > 15)
                                <p class="mt-2 text-sm text-ink-2">And {{ count($valley['factions']) - 15 }} more.</p>
                            @endif
                        @endif
                    </div>
                    <div>
                        <h3 class="text-[22px] font-bold">What {{ $valley['name'] }} holds</h3>
                        <p class="mt-1 text-sm text-ink-2">Carried by animals and kept in faction stores.</p>
                        @if (empty($valley['materials']))
                            <p class="mt-3 text-ink-2">Nothing gathered yet.</p>
                        @else
                            <ul class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach ($valley['materials'] as $material => $amount)
                                    <li class="rounded-lg border border-rule-soft bg-paper px-4 py-2.5">
                                        <span class="figures block text-[20px] font-bold">{{ number_format($amount) }}</span>
                                        <span class="text-sm text-ink-2">{{ ucfirst($material) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    {{-- Closing --}}
    <section class="mx-auto flex max-w-[860px] flex-col items-center gap-6 px-[clamp(16px,4vw,40px)] py-[clamp(56px,8vw,104px)] text-center">
        <h2 class="text-[clamp(30px,4vw,48px)] leading-[1.08] font-extrabold tracking-tight">The first season of <span class="text-gradient">Hollowmere</span> is open</h2>
        <p class="text-[18px] text-ink-2">It is free while we test it. Later seasons add real shops: makers will be able to cash out what they earn.</p>
        <a href="{{ $playUrl }}" class="glow flex h-12 items-center rounded-md bg-brand px-6 text-[17px] font-bold text-ground no-underline hover:text-ground">Make your animal</a>
    </section>
@endsection
