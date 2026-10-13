<?php

namespace App\Support;

use App\Audit\Money;

/**
 * Copy for the public pages, in one place so the page text and the
 * structured data search engines read always agree.
 */
final class SiteContent
{
    /**
     * Figures from the founder's research notes. They still need checking:
     * a figure without a source URL only shows while unverified claims are
     * switched on (local development by default).
     *
     * @return list<array{figure: string, text: string, source: ?string, metro: string}>
     */
    public static function proof(): array
    {
        return [
            ['figure' => '16,000', 'text' => 'inaccurate-bill queries a month, acknowledged by the City of Johannesburg across about 1.2 million accounts.', 'source' => null, 'metro' => 'johannesburg'],
            ['figure' => 'R2.6m', 'text' => "removed from one Johannesburg resident's bill, but only after the Public Protector stepped in.", 'source' => null, 'metro' => 'johannesburg'],
            ['figure' => 'R89,000', 'text' => 'refunded to a Tshwane property billed on a commercial tariff instead of bulk residential.', 'source' => null, 'metro' => 'tshwane'],
        ];
    }

    /**
     * @return list<array{figure: string, text: string, source: ?string, metro: string}>
     */
    public static function visibleProof(?string $municipality = null): array
    {
        $showUnverified = (bool) config('auditor.show_unverified_claims');

        return array_values(array_filter(
            self::proof(),
            fn (array $item) => ($item['source'] !== null || $showUnverified) && ($municipality === null || $item['metro'] === $municipality),
        ));
    }

    /**
     * @return list<array{number: string, title: string, snippet: array<string, string>, problem: string, fix: string}>
     */
    public static function checks(): array
    {
        return [
            [
                'number' => '01',
                'title' => 'Estimated meter readings',
                'snippet' => ['left' => 'WATER 1,204 → 1,242', 'marked' => '(E) 38 kL', 'right' => 'usual: 16 kL'],
                'problem' => 'When nobody reads your meter, the municipality bills a guess. A guess can run far above what you really use, month after month.',
                'fix' => 'We spot the "E" on your bill, compare the estimate with your real usage, and ask for a dated photo of your meter as proof.',
            ],
            [
                'number' => '02',
                'title' => 'Wrong tariff category',
                'snippet' => ['left' => 'TARIFF CATEGORY', 'marked' => 'Business', 'right' => 'should be: Bulk residential'],
                'problem' => 'A residential property billed as a business pays more for the same water and electricity, every month, until someone notices.',
                'fix' => 'We check your tariff against your property type, then claim back what you overpaid and get the lower rate applied from now on.',
            ],
            [
                'number' => '03',
                'title' => 'Charges during outages',
                'snippet' => ['left' => 'SUPPLY OFF 12–15 SEP', 'marked' => 'billed 30 of 30 days', 'right' => ''],
                'problem' => "If the water or the power was off, you shouldn't be billed as if you used it.",
                'fix' => 'We match your billing period against outages reported in your suburb, and ask you to confirm the dates before we claim.',
            ],
            [
                'number' => '04',
                'title' => 'Spikes against your history',
                'snippet' => ['left' => '', 'marked' => '2.4× your average', 'right' => ''],
                'problem' => 'A bill far above your usual can mean a faulty meter, a misread or a leak on your side.',
                'fix' => 'We compare the bill with your own past usage, and ask you what changed before we call it an error.',
            ],
        ];
    }

    /**
     * @return list<array{title: string, text: string}>
     */
    public static function steps(): array
    {
        return [
            ['title' => 'You send your bill', 'text' => 'Take a photo, upload the PDF, or forward your e-bill to your own Bill Auditor address.'],
            ['title' => 'We check every line', 'text' => "Meter readings, tariff, outage dates and your past bills. You see each problem, what it's worth in rand, and the evidence behind it."],
            ['title' => 'We write your dispute', 'text' => 'With your meter photo and bill attached. You see the price first, and nothing is sent until you approve it.'],
            ['title' => 'We email it to your municipality', 'text' => "We find the right address for you, whichever of South Africa's 257 municipalities bills you, and copy you in. Their replies come to us and we tell you what they say."],
            ['title' => 'We chase, escalate, then check for your credit', 'text' => 'No reply? We follow up, then escalate to the municipal manager, and write your Public Protector complaint if it comes to that. We read every new bill until the credit appears.'],
        ];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function faq(): array
    {
        return [
            ['question' => 'How long do I have to dispute my municipal bill?', 'answer' => 'It depends on your municipality. An eThekwini councillor advises disputing within 30 days of an incorrect bill, and other municipalities have their own rules. When we check your bill, we show the deadline that applies to you.'],
            ['question' => 'Which municipalities do you cover?', 'answer' => "All 257 in South Africa: the 8 metros and every local and district municipality. We read your municipality from your bill and find where its account queries go, so you don't need to know."],
            ['question' => 'What does it cost?', 'answer' => 'Households pay per job from prepaid credit: '.self::price('bill_check').' to check a bill and '.self::price('dispute').' for a dispute, which covers emailing it, reading the replies, chasing and escalating. Landlords, managing agents and businesses pay one flat monthly fee. You always see the price before we start.'],
            ['question' => 'Do I still have to pay while my bill is in dispute?', 'answer' => "Keep paying the part of the bill you're not disputing, so your account stays in good standing. We work out that amount for you."],
            ['question' => "What if the municipality doesn't reply?", 'answer' => "We follow up by email, then escalate to the municipal manager. If it still isn't resolved, we write your complaint to the Public Protector for you to lodge."],
            ['question' => 'Will I get my money back in cash?', 'answer' => 'Usually not. Municipalities tend to correct the bill with a credit on your account. We read your next bills and tell you when the credit appears.'],
            ['question' => "What if you flag something that isn't wrong?", 'answer' => "Every flag shows the figures behind it, and when we're not sure, we ask you first. If a flag is wrong, tell us in one tap and we won't count it."],
            ['question' => 'What do you do with my bill?', 'answer' => 'We use it only to check it and build your dispute. You can delete it at any time, and we handle your personal information under POPIA.'],
        ];
    }

    /** A job's price for the public pages, such as "R15". */
    private static function price(string $job): string
    {
        return str_replace('.00', '', Money::format((string) config("auditor.prices.jobs.{$job}")));
    }
}
