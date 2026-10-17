@extends('site.layout')

@php
    $company = config('game.company_name') ?: '[Company name]';
    $officer = config('game.information_officer') ?: '[Information officer, name and contact]';
    $contact = config('game.contact_email');
@endphp

@section('title', 'Privacy and POPIA | '.config('app.name'))
@section('description', 'What we collect when you play, why, who sees it, and how to have it deleted.')
@section('canonical', route('privacy'))

@section('content')
    <article class="mx-auto flex max-w-[760px] flex-col gap-8 px-[clamp(16px,4vw,40px)] py-[clamp(40px,6vw,72px)]">
        <header class="flex flex-col gap-3">
            <h1 class="text-[clamp(32px,4.4vw,52px)] leading-[1.08] font-extrabold tracking-tight">Privacy and POPIA</h1>
            <p class="text-lg text-ink-2">This page says what we do with your information when you play, in plain terms, as the Protection of Personal Information Act (POPIA) requires.</p>
        </header>

        @php
            $sections = [
                ['Who is responsible', [
                    "{$company} is the responsible party for the personal information on this site. Our information officer is {$officer}.",
                ]],
                ['Who can play', [
                    'The test season is for adults aged 18 and older. You confirm your age when you make your animal.',
                ]],
                ['What we collect', [
                    'Your name, email address and password, to run your account.',
                    'If you sign in with Google, Google tells us your name and email address, and that it has checked the email is yours. We get nothing else from your Google account, and never your Google password.',
                    "Your animal and everything it does in the game: its name, faction, items, shop, chat messages and the game's records of battles, laws and crimes.",
                    'We do not collect your location, your phone contacts or your phone number.',
                ]],
                ['Why we use it', [
                    'Only to run the game, keep it fair and safe, and answer you when you contact us. We do not sell your information or use it for advertising.',
                ]],
                ['Who sees it', [
                    'Other players see your animal, its faction, its shop and what you write in chat channels they can read.',
                    'Our team, when we moderate items, chat or reports.',
                    'Anthropic, when a player taps Translate on a chat message: it receives that message and returns the translation.',
                    'Google, only if you choose to sign in with Google.',
                ]],
                ['How long we keep it', [
                    'We keep your account while it is open. When you delete your profile in Settings, your account and your animal are deleted.',
                ]],
                ['Your rights', [
                    'You may ask what information we hold about you, ask us to correct or delete it, and object to how we use it.',
                    'You may also complain to the Information Regulator of South Africa (inforegulator.org.za).',
                ]],
            ];
        @endphp

        @foreach ($sections as [$heading, $points])
            <section class="flex flex-col gap-3">
                <h2 class="text-[22px] font-bold">{{ $heading }}</h2>
                <ul class="flex list-disc flex-col gap-2 pl-5 text-ink-2">
                    @foreach ($points as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if ($contact)
            <p class="text-ink-2">Questions or requests: <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>
        @endif
    </article>
@endsection
