<?php

namespace App\Http\Controllers\Marketing;

use App\Audit\Enums\CheckType;
use App\Audit\Enums\PropertyType;
use App\Audit\Enums\Service;
use App\Audit\Letters\Letter;
use App\Audit\Letters\LetterInput;
use App\Audit\Letters\LetterItem;
use App\Audit\Letters\LetterWriter;
use App\Audit\Money;
use App\Enums\Job;
use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Services\Billing\Wallet;
use App\Support\SiteContent;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The public pages. They are plain server-rendered HTML so search engines
 * and link previews see the whole page without running JavaScript.
 */
class PageController extends Controller
{
    public function home(): View
    {
        return view('marketing.home', [
            'metros' => Municipality::landingPages(),
            'proof' => SiteContent::visibleProof(),
            'checks' => SiteContent::checks(),
            'steps' => SiteContent::steps(),
            'faq' => SiteContent::faq(),
            'prices' => self::prices(),
        ]);
    }

    public function metro(Municipality $metro): View
    {
        abort_unless($metro->live && $metro->has_landing_page, 404);

        return view('marketing.metro', [
            'metro' => $metro,
            'letter' => $this->sampleLetter($metro),
            'proof' => SiteContent::visibleProof($metro->slug),
            'checks' => SiteContent::checks(),
            'steps' => SiteContent::steps(),
            'faq' => SiteContent::faq(),
            'otherMetros' => Municipality::landingPages()->reject(fn (Municipality $m) => $m->is($metro))->values(),
        ]);
    }

    public function privacy(): View
    {
        return view('marketing.privacy');
    }

    /**
     * The letter a customer of this metro would get for the example bill on
     * the landing page: an estimated water reading and an outage.
     */
    private function sampleLetter(Municipality $metro): Letter
    {
        return LetterWriter::dispute(new LetterInput(
            addressee: $metro->billing_addressee,
            senderName: '[Your name]',
            accountNumber: '[account number]',
            propertyAddress: '[your address]',
            billDate: new DateTimeImmutable('2026-10-03'),
            billMonth: new DateTimeImmutable('2026-09-01'),
            totalDue: Money::of('2996.45'),
            items: [
                new LetterItem(CheckType::EstimatedReading, Service::Water, Money::of('924.00'), [
                    'billed' => '38', 'usual' => '16', 'unit' => 'kL', 'streak' => 1,
                ]),
                new LetterItem(CheckType::OutageCharge, Service::Water, Money::of('89.60'), [
                    'starts_on' => '2026-09-12', 'ends_on' => '2026-09-15',
                ], 'yes'),
            ],
            letterDate: new DateTimeImmutable('2026-10-11'),
            propertyType: PropertyType::Residential,
            hasMeterPhoto: true,
            extraParagraph: (string) $metro->letter_paragraph,
        ));
    }

    /**
     * The prices the public pages quote, from config.
     *
     * @return array{billCheck: string, billCheckAmount: string, dispute: string, agentMonth: string, businessMonth: string, includedChecks: int, smallestTopUp: string, welcome: ?string}
     */
    public static function prices(): array
    {
        $rand = fn (string $amount) => Str::replaceLast('.00', '', Money::format($amount));
        $packs = array_map(fn (string $a) => Money::of($a), config('auditor.prices.top_ups'));
        $welcome = Money::money((string) config('auditor.prices.welcome_credit'));

        return [
            'billCheck' => $rand(Job::BillCheck->price()),
            'billCheckAmount' => Job::BillCheck->price(),
            'dispute' => $rand(Job::Dispute->price()),
            'agentMonth' => $rand((string) config('auditor.prices.agent_month')),
            'businessMonth' => $rand((string) config('auditor.prices.business_month')),
            'includedChecks' => Wallet::includedChecks(),
            'smallestTopUp' => $packs === [] ? $rand(Job::BillCheck->price()) : $rand((string) BigDecimal::min(...$packs)),
            // Only mention free credit when there is some.
            'welcome' => $welcome->isPositive() ? $rand((string) $welcome) : null,
        ];
    }
}
