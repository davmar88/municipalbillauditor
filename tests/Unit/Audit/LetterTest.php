<?php

namespace Tests\Unit\Audit;

use App\Audit\Enums\CheckType;
use App\Audit\Enums\PropertyType;
use App\Audit\Enums\Service;
use App\Audit\Letters\LetterInput;
use App\Audit\Letters\LetterItem;
use App\Audit\Letters\LetterWriter;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class LetterTest extends TestCase
{
    private function householdLetter(string $outageAnswer = 'yes', bool $photo = true): LetterInput
    {
        return new LetterInput(
            addressee: 'Billing queries, City of Johannesburg',
            senderName: 'Thandi Mokoena',
            accountNumber: '5021 4478 2210',
            propertyAddress: '38 Jacaranda Street, Parkhurst',
            billDate: new DateTimeImmutable('2026-10-03'),
            billMonth: new DateTimeImmutable('2026-09-30'),
            totalDue: BigDecimal::of('2996.45'),
            items: [
                new LetterItem(CheckType::EstimatedReading, Service::Water, BigDecimal::of('924.00'), [
                    'billed' => '38', 'usual' => '16.0', 'unit' => 'kL', 'streak' => 1,
                ]),
                new LetterItem(CheckType::OutageCharge, Service::Water, BigDecimal::of('89.60'), [
                    'starts_on' => '2026-09-12', 'ends_on' => '2026-09-15',
                ], $outageAnswer),
            ],
            letterDate: new DateTimeImmutable('2026-10-11'),
            propertyType: PropertyType::Residential,
            hasMeterPhoto: $photo,
        );
    }

    public function test_dispute_letter_states_each_claim_and_the_undisputed_amount(): void
    {
        $letter = LetterWriter::dispute($this->householdLetter());

        $this->assertSame('Dispute of the September 2026 account (bill dated 3 October 2026), account 5021 4478 2210', $letter->subject);
        $this->assertSame('1013.60', (string) $letter->disputedTotal);
        $this->assertSame('1982.85', (string) $letter->undisputedAmount);
        $this->assertStringContainsString(
            '1. Water, R924.00. The reading is marked as estimated and assumes 38 kL against my usual 16 kL. A dated photo of my meter is attached.',
            $letter->body,
        );
        $this->assertStringContainsString('2. Water, R89.60. Supply was off from 12 September to 15 September, but water was billed for those days.', $letter->body);
        $this->assertStringContainsString('- replace the estimated water reading with an actual reading', $letter->body);
        $this->assertStringContainsString('- acknowledge this dispute with a reference number', $letter->body);
        $this->assertStringContainsString('I will keep paying the undisputed amount of R1,982.85 while this dispute is resolved.', $letter->body);
    }

    public function test_unsure_outage_answers_are_worded_as_reported_not_as_fact(): void
    {
        $letter = LetterWriter::dispute($this->householdLetter('not_sure', photo: false));

        $this->assertStringContainsString('An outage was reported in my area from 12 September to 15 September', $letter->body);
        $this->assertStringNotContainsString('photo of my meter', $letter->body);
    }

    public function test_tariff_letter_asks_for_the_right_category_and_back_credit(): void
    {
        $letter = LetterWriter::dispute(new LetterInput(
            addressee: 'Billing queries, City of Tshwane',
            senderName: 'Silverleaf Gardens Body Corporate',
            accountNumber: '[account]',
            propertyAddress: '',
            billDate: new DateTimeImmutable('2026-10-03'),
            billMonth: new DateTimeImmutable('2026-09-30'),
            totalDue: null,
            items: [new LetterItem(CheckType::WrongTariff, null, BigDecimal::of('7500.00'), [
                'expected_category' => 'bulk_residential', 'printed_tariff' => 'Business', 'months' => 12,
            ])],
            letterDate: new DateTimeImmutable('2026-10-11'),
            propertyType: PropertyType::BulkResidential,
        ));

        $this->assertStringContainsString(
            'Tariff category, R7,500.00. The account is billed on the Business tariff, but the property is a residential scheme on one bulk account. The same tariff appears on my last 12 bills.',
            $letter->body,
        );
        $this->assertStringContainsString('- move the account to the bulk residential tariff and credit the overcharge on past bills', $letter->body);
        $this->assertNull($letter->undisputedAmount);
        $this->assertStringNotContainsString('Property:', $letter->body);
    }
}
