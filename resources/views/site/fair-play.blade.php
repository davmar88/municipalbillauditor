@extends('site.layout')

@section('title', 'No cheating | '.config('app.name'))
@section('description', 'The fair play rules of Animal Farm: what counts as cheating, what happens to cheats, and how to report one.')
@section('canonical', route('fair-play'))

@section('content')
    <article class="mx-auto flex max-w-[760px] flex-col gap-8 px-[clamp(16px,4vw,40px)] py-[clamp(40px,6vw,72px)]">
        <header class="flex flex-col gap-3">
            <h1 class="text-[clamp(32px,4.4vw,52px)] leading-[1.08] font-extrabold tracking-tight">No cheating</h1>
            <p class="text-lg text-ink-2">Animal Farm is a game of rival factions, raids, crimes and deals, and plenty of it is meant to be ruthless. Within the rules, anything goes. These are the rules. Each has a code, so a report can say which one was broken.</p>
        </header>

        @foreach (config('fairplay.rules') as $code => $rule)
            <section class="flex flex-col gap-2 rounded-xl border border-rule-soft bg-paper px-5 py-4">
                <h2 class="flex flex-wrap items-center gap-2 text-[20px] font-bold">
                    <span class="rounded-md bg-ground px-2 py-0.5 font-mono text-[13px] tracking-[0.08em] text-action">{{ $code }}</span>
                    {{ $rule['title'] }}
                </h2>
                <p class="text-ink-2">{{ $rule['text'] }}</p>
                <ul class="flex list-disc flex-col gap-1 pl-5 text-[15px] text-ink-2">
                    @foreach ($rule['examples'] as $example)
                        <li>{{ $example }}</li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <section class="flex flex-col gap-3">
            <h2 class="text-[22px] font-bold">What isn't cheating</h2>
            <ul class="flex list-disc flex-col gap-2 pl-5 text-ink-2">
                <li>Raiding, looting, burning, plundering and robbing other players, your own faction's included, within the game's rules.</li>
                <li>Putting bounties on rivals, outbidding them in the Market, and making and breaking deals inside the game.</li>
                <li>Spending real money in the game's own shop.</li>
            </ul>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-[22px] font-bold">What happens to cheats</h2>
            <ul class="flex list-disc flex-col gap-2 pl-5 text-ink-2">
                @foreach (config('fairplay.penalties') as $penalty)
                    <li>{{ $penalty }}</li>
                @endforeach
            </ul>
            <p class="text-ink-2">We look at every report, and we decide what's fair for what happened. If you think we got it wrong, contact us and we'll look again.</p>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-[22px] font-bold">How to report a cheat</h2>
            <ol class="flex list-decimal flex-col gap-2 pl-5 text-ink-2">
                <li>In the game, tap the animal on the map, or open its profile, and choose <strong>Report for cheating</strong>.</li>
                <li>Pick the rule it broke, from the list above.</li>
                <li>Say what you saw, and when. A screenshot or a short screen recording helps a lot.</li>
            </ol>
            <p class="text-ink-2">Reports are private: the player you report isn't told who reported them. Please don't make reports to get back at someone; false reports are themselves against the rules.</p>
        </section>

        <p class="text-ink-2">These rules are part of our <a href="{{ route('terms') }}">Terms and Conditions</a>.</p>
    </article>
@endsection
