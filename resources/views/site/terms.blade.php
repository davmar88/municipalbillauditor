@extends('site.layout')

@php
    $company = config('game.company_name') ?: 'Animal Farm';
    $team = config('game.information_officer') ?: 'The Animal Farm Dev Team';
    $contact = config('game.contact_email');
    $version = config('game.terms_version');
@endphp

@section('title', 'Terms and Conditions | '.config('app.name'))
@section('description', 'The rules for playing Animal Farm and buying Pineapples: what you agree to, what you get, and why purchases are final.')
@section('canonical', route('terms'))

@section('content')
    <article class="mx-auto flex max-w-[760px] flex-col gap-8 px-[clamp(16px,4vw,40px)] py-[clamp(40px,6vw,72px)]">
        <header class="flex flex-col gap-3">
            <h1 class="text-[clamp(32px,4.4vw,52px)] leading-[1.08] font-extrabold tracking-tight">Terms and Conditions</h1>
            <p class="text-lg text-ink-2">These terms are the agreement between you and {{ $company }} ("we", "us"), run by {{ $team }}, for playing the game at this site and for anything you buy in it. Please read them. By making an animal, or by buying Pineapples, you agree to them.</p>
            <p class="text-sm text-ink-2">Version {{ $version }}.</p>
        </header>

        @php
            $sections = [
                ['1. The short version', [
                    'Animal Farm is a game. Pineapples, Apples, Corn, Rosettes, items, homes, land and everything else in it are game content, not money or property.',
                    'Buying Pineapples gives you a licence to use game content. It is not a deposit, an investment or a purchase of anything with value outside the game.',
                    'Purchases are final. We do not give refunds, except where the law says we must or where we decide to in a particular case.',
                    'Play fairly. Cheating, abuse and fraud can cost you your animal and your game content, without a refund.',
                ]],
                ['2. Who can play', [
                    'You must be 18 or older. You confirm your age when you make your animal, and we may close an account we believe belongs to someone younger.',
                    'You need an account in your own name with a working email address. One account per person, and one animal per account.',
                    'You are responsible for everything done with your account. Keep your password and sign-in safe, and tell us straight away if someone else gets in.',
                ]],
                ['3. The game changes', [
                    'The game is in development and changes often. We may add, change, rebalance or remove anything in it at any time: prices, rewards, odds, rules, maps, valleys, seasons, factions, items and the value of anything in the game.',
                    'Seasons end. When a season ends, things tied to it may reset or lapse, as the game explains.',
                    'We open new valleys when one is full, and newcomers start in the valley the game places them in.',
                    'We do not promise the game will always be available, free of errors, or kept running for any length of time. We may pause it for maintenance, or close it. If we close the game for good, we will try to give notice on the site first.',
                ]],
                ['4. Pineapples, Apples and other game content', [
                    'Pineapples are bought with real money through PayPal. You can swap Pineapples for packs of Apples, plant Apple trees with them, and use them in other ways the game offers. Apples are spent in the game: in shops that take them, on the Orchard, on bounties, given to a faction, or swapped for Corn.',
                    'All game content, Pineapples and Apples included, is a limited, personal, non-transferable, revocable licence to use it in the game while your account is in good standing. You do not own it, and it has no value outside the game.',
                    'Game content cannot be exchanged for money, sold, traded or transferred outside the game, except through a cash-out the game itself offers, under its own rules (section 7).',
                    'Prices are shown in US dollars before you pay. PayPal or your bank may add fees or convert your currency at their own rates; those are between you and them.',
                    'Game content you spend or lose in play (in a raid, a robbery, a fire, a failed arrest, a bad trade, an expired season and so on) is gone. That is part of the game, not a fault, and it is not refunded.',
                ]],
                ['5. Paying, and no refunds', [
                    'Before you pay, you tick a box to agree to these terms and to confirm that the purchase is final and that you will not get a refund. You also agree that we deliver the Pineapples straight away, as soon as PayPal confirms the payment.',
                    'Because Pineapples are digital content delivered at once and used up in play, all purchases are final. To the fullest extent the law allows, we do not refund purchases, and we do not refund game content that is used, lost, spent, taken by other players, reset with a season or removed for breaking these terms.',
                    'Your rights under South African law, including the Consumer Protection Act and the Electronic Communications and Transactions Act, are not taken away by these terms where the law does not allow it. If a law gives you a right to a refund or to cancel, you have that right, and we will honour it. Where the law allows a cooling-off right to be excluded for digital content you asked us to supply straight away, you agree that it does not apply.',
                    'If you paid and your Pineapples did not arrive, or you were charged twice or the wrong amount, contact us. We will check it with PayPal and put it right: by delivering what you paid for, or by refunding the payment that went wrong.',
                    'Do not dispute or charge back a payment with PayPal or your bank without contacting us first. If you do, we may take back the Pineapples and anything bought with them, and suspend the account while it is resolved.',
                    'We may refuse or cancel a payment we believe is fraudulent, made with someone else\'s account or card, or made to break these terms.',
                ]],
                ['6. Playing with others', [
                    'Animal Farm is a game of rival factions. Other players can raid your land, loot your faction, set your home alight, put bounties on you and outbid you in the market, within the game\'s rules. Losing to another player in the game is not a reason for a refund or a complaint against us.',
                    'Trades, gifts, faction stores and bounties between players are agreements between those players. We are not part of them, and we do not undo them, except to fix a fault in the game or to deal with cheating or fraud.',
                    'Factions make their own rules. We do not enforce a faction\'s rules between its members beyond what the game does by itself.',
                ]],
                ['7. Cash-outs', [
                    'Where the game offers cash-outs, you may ask to cash some Apples out for money, at the rate, limits and fees the game shows at the time. Cash-outs open only when we say so, and we may pause, change or close them.',
                    'To cash out you must give your full legal name, ID or passport number and the PayPal account to pay, and they must be your own. We check every cash-out before paying and may refuse, delay or reverse one we believe comes from cheating, fraud, money laundering, chargebacks, a second account or anything else that breaks these terms.',
                    'A cash-out is paid through PayPal. PayPal\'s own fees and rules apply, and we are not responsible for a payment PayPal holds, delays or returns.',
                    'Cash-outs are not wages, winnings or investment returns. You are responsible for any tax on money you receive.',
                ]],
                ['8. Fair play', [
                    'Do not cheat. That includes using bots, scripts, automation or modified clients; exploiting a bug instead of reporting it; running more than one account; sharing or selling accounts; and arranging with others to get around the game\'s limits.',
                    'Do not harass, threaten, bully or impersonate anyone; post hate speech, sexual content, personal information about others, spam or links to scams; or try to get money or personal details from other players.',
                    'Do not attack the site: no hacking, probing, overloading or interfering with the game, its servers or other players\' accounts.',
                    'Do not trade game content for real money or other goods outside the game.',
                    'If you find a bug that gives you something you should not have, tell us. Keeping or using it is cheating.',
                ]],
                ['9. What you create', [
                    'You may design items, write in chat and messages, name your animal and faction, and write laws and rules. You keep the rights you have in what you create, and you give us a free, worldwide, lasting licence to use, show, copy, change and remove it to run, improve and promote the game.',
                    'You promise that what you create is yours to share, and that it does not break anyone\'s rights or the law. We check designs before they can be made, and we may remove anything at any time.',
                ]],
                ['10. If you break these terms', [
                    'We may warn you, silence you, take back game content gained by breaking them, reverse trades, cancel cash-outs, suspend your account or close it for good, as we judge fair for what happened. If we close an account for breaking these terms, its game content is lost and not refunded.',
                    'If you think we got it wrong, contact us and we will look again.',
                ]],
                ['11. Closing your account', [
                    'You can delete your profile at any time in Settings. Your animal and its game content are deleted with it and cannot be brought back or refunded.',
                    'We may close accounts that have not been used for a long time, after trying to warn you by email.',
                ]],
                ['12. Our responsibility', [
                    'The game is offered "as is" and "as available". To the fullest extent the law allows, we make no promises about it beyond those in these terms.',
                    'To the fullest extent the law allows, we are not liable for any indirect or consequential loss, for loss of game content, data or profits, or for anything caused by other players, by PayPal, Google or other services, or by events beyond our reasonable control.',
                    'Where the law allows us to limit our liability, the most we will be liable for, in total, is what you paid us in the 6 months before the claim.',
                    'Nothing in these terms limits liability that the law does not allow to be limited, such as for gross negligence or fraud.',
                    'You agree to cover us for loss we suffer because you broke these terms or the law while using the game.',
                ]],
                ['13. Privacy', [
                    'How we handle your personal information is set out in our Privacy and POPIA page, which is part of these terms.',
                ]],
                ['14. Changes to these terms', [
                    'We may change these terms. We will show the date of the version at the top of this page, and tell players in the game when something important changes. If you keep playing after a change, you agree to the new terms. Each purchase is made under the terms in force when you make it.',
                ]],
                ['15. The law', [
                    'These terms are governed by the laws of the Republic of South Africa, and the courts of South Africa have jurisdiction, without taking away any right you have to approach a consumer tribunal or court where you live.',
                    'If any part of these terms cannot be enforced, the rest still applies. If we do not enforce a term straight away, we can still enforce it later.',
                    'These terms, the Privacy and POPIA page and the rules the game shows on its own pages are the whole agreement between you and us about the game.',
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

        <p class="text-ink-2">
            See also our <a href="{{ route('privacy') }}">Privacy and POPIA</a> page.
            @if ($contact)
                Questions, payment problems or complaints: <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
            @endif
        </p>
    </article>
@endsection
