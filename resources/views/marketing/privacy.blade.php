@extends('marketing.layout')

@php
    $company = config('auditor.company_name') ?: '[Company name]';
    $officer = config('auditor.information_officer') ?: '[Information officer, name and contact]';
    $contact = config('auditor.contact_email');
@endphp

@section('title', 'Privacy and POPIA | Municipal Bill Auditor')
@section('description', 'What we collect when we check and dispute your municipal bill, why, who sees it, and how to have it deleted.')
@section('canonical', route('privacy'))

@section('content')
    <article class="mx-auto flex max-w-[760px] flex-col gap-8 px-[clamp(16px,4vw,40px)] py-[clamp(40px,6vw,72px)]">
        <header class="flex flex-col gap-3">
            <h1 class="text-[clamp(32px,4.4vw,52px)] leading-[1.08] font-extrabold tracking-tight">Privacy and POPIA</h1>
            <p class="text-lg text-ink-2">A municipal bill holds your name, address, account number and what you use. This page says what we do with it, in plain terms, as the Protection of Personal Information Act (POPIA) requires.</p>
        </header>

        @php
            $sections = [
                ['Who is responsible', [
                    "{$company} is the responsible party for the personal information on this site. Our information officer is {$officer}.",
                ]],
                ['What we collect', [
                    'Your name, email address and password, to run your account.',
                    'If you sign in with Google, Google tells us your name and email address, and that it has checked the email is yours. We get nothing else from your Google account, and never your Google password.',
                    'The bills you send us, and anything on them: your account number, address, meter readings, tariff and charges.',
                    'Meter photos you add, your answers to our questions, and the disputes we write for you.',
                    'For managing agents and businesses, the names, units and account numbers of the properties you add.',
                ]],
                ['Why we use it', [
                    'Only to check your bills, write and lodge your disputes, follow them up, and tell you when a credit appears.',
                    'We compare each bill with your own past bills. We do not sell your information or use it for advertising.',
                ]],
                ['Who sees it', [
                    'You, and anyone else on your account.',
                    'Our team, when a dispute or a bill needs a person to look at it.',
                    'The municipality, which receives your dispute by email, with the attachments you approve, once you have given us permission to send disputes for you.',
                    'Anthropic, which reads the bill and the municipality\'s replies for us when automatic reading is switched on. It receives the files and returns what they say.',
                    'Our email provider, which delivers the dispute emails and passes the municipality\'s replies back to us.',
                    'Paystack, which takes card payments for top-ups and monthly fees. We never see or store your card number.',
                    'Google, only if you choose to sign in with Google.',
                ]],
                ['How we keep it safe', [
                    'Bills and meter photos are stored privately, never at a public link, and are only shown to people signed in to your account.',
                    'Connections are encrypted, and sessions are encrypted at rest.',
                ]],
                ['How long we keep it', [
                    'We keep your bills while your account is open, because each new bill is checked against your history.',
                    'You can delete a bill, a property or all your bills at any time in the app. Deleting a bill deletes its file, findings and any dispute about it.',
                    'When the last person on an account deletes their profile, the whole account and its bills are deleted.',
                ]],
                ['Your rights', [
                    'You may ask what information we hold about you, ask us to correct or delete it, and object to how we use it.',
                    'You may also complain to the Information Regulator of South Africa (inforegulator.org.za).',
                ]],
            ];
        @endphp

        @foreach ($sections as [$heading, $paragraphs])
            <section class="flex flex-col gap-3">
                <h2 class="text-2xl font-bold">{{ $heading }}</h2>
                @if (count($paragraphs) > 1)
                    <ul class="flex list-disc flex-col gap-2 pl-6 text-ink-2">
                        @foreach ($paragraphs as $paragraph)
                            <li>{{ $paragraph }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-ink-2">{{ $paragraphs[0] }}</p>
                @endif
            </section>
        @endforeach

        @if ($contact)
            <p class="rounded-lg border border-rule bg-paper p-5">Questions or requests: <a href="mailto:{{ $contact }}" class="font-semibold">{{ $contact }}</a></p>
        @endif
    </article>
@endsection
