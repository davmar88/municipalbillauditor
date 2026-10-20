@extends('site.layout')

@section('title', 'Updates and fixes | '.config('app.name'))
@section('description', "What's new in Animal Farm and what we've fixed, newest first.")
@section('canonical', route('updates'))

@section('content')
    <article class="mx-auto flex max-w-[760px] flex-col gap-8 px-[clamp(16px,4vw,40px)] py-[clamp(40px,6vw,72px)]">
        <header class="flex flex-col gap-3">
            <h1 class="text-[clamp(32px,4.4vw,52px)] leading-[1.08] font-extrabold tracking-tight">Updates and fixes</h1>
            <p class="text-lg text-ink-2">What's new in the game and what we've fixed, newest first. Found something broken? Report it from the menu in the game.</p>
        </header>

        @forelse ($days as $day => $updates)
            <section class="flex flex-col gap-3">
                <h2 class="text-[22px] font-bold">{{ \Carbon\Carbon::parse($day)->format('j F Y') }}</h2>
                <ul class="flex flex-col gap-3">
                    @foreach ($updates as $update)
                        <li class="rounded-xl border border-rule-soft bg-paper px-5 py-4">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 font-mono text-[11px] font-bold tracking-[0.1em] {{ $update->kind === 'fix' ? 'bg-sky-500/15 text-sky-300' : 'bg-emerald-500/15 text-emerald-300' }}">{{ $update->kind === 'fix' ? 'FIXED' : 'NEW' }}</span>
                                <span class="text-[17px] font-bold">{{ $update->title }}</span>
                            </p>
                            <p class="mt-1.5 text-ink-2">{{ $update->body }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="text-ink-2">Nothing posted yet.</p>
        @endforelse
    </article>
@endsection
