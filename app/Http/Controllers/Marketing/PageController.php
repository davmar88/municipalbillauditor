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
use App\Http\Controllers\Controller;
use App\Models\Metro;
use App\Support\SiteContent;
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
            'metros' => Metro::query()->where('live', true)->orderBy('sort')->get(),
            'proof' => SiteContent::visibleProof(),
            'checks' => SiteContent::checks(),
            'steps' => SiteContent::steps(),
            'faq' => SiteContent::faq(),
            'prices' => $this->prices(),
        ]);
    }

    public function metro(Metro $metro): View
    {
        abort_unless($metro->live, 404);

        return view('marketing.metro', [
            'metro' => $metro,
            'letter' => $this->sampleLetter($metro),
            'proof' => SiteContent::visibleProof($metro->slug),
            'checks' => SiteContent::checks(),
            'steps' => SiteContent::steps(),
            'faq' => SiteContent::faq(),
            'otherMetros' => Metro::query()->where('live', true)->whereKeyNot($metro->id)->orderBy('sort')->get(),
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
    private function sampleLetter(Metro $metro): Letter
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
     * @return array{household: string, unit: string, successFee: string}
     */
    private function prices(): array
    {
        return [
            'household' => Str::replaceLast('.00', '', Money::format(config('auditor.prices.household_dispute'))),
            'unit' => Str::replaceLast('.00', '', Money::format(config('auditor.prices.per_unit_month'))),
            'successFee' => rtrim(rtrim((string) Money::of(config('auditor.prices.success_fee_rate'))->multipliedBy(100), '0'), '.').'%',
        ];
    }
}
