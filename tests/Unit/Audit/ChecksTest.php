<?php

namespace Tests\Unit\Audit;

use App\Audit\Auditor;
use App\Audit\Checks\EstimatedReadingCheck;
use App\Audit\Checks\OutageCheck;
use App\Audit\Checks\SpikeCheck;
use App\Audit\Checks\TariffCheck;
use App\Audit\Data\CheckContext;
use App\Audit\Data\OutageWindow;
use App\Audit\Enums\CheckType;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\PropertyType;
use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ChecksTest extends TestCase
{
    private function householdContext(array $outages = [], string $area = 'Parkhurst'): CheckContext
    {
        return new CheckContext(
            bill: Bills::householdSeptember(),
            history: Bills::householdHistory(),
            propertyType: PropertyType::Residential,
            outageArea: $area,
            outages: $outages,
        );
    }

    private function septemberOutage(): OutageWindow
    {
        return new OutageWindow(
            Service::Water,
            new DateTimeImmutable('2026-09-12'),
            new DateTimeImmutable('2026-09-15'),
            'Parkhurst',
            'Johannesburg Water notice',
        );
    }

    public function test_estimate_far_above_usual_use_is_a_likely_error_with_a_redoable_calculation(): void
    {
        $report = (new EstimatedReadingCheck)->run($this->householdContext());

        $this->assertCount(1, $report->findings);
        $finding = $report->findings[0];
        $this->assertSame(CheckType::EstimatedReading, $finding->check);
        $this->assertSame(Confidence::Likely, $finding->confidence);
        $this->assertSame('924.00', (string) $finding->amount);
        $this->assertSame('(38 − 16) kL × R42.00 = R924.00', $finding->calculation);
        $this->assertStringContainsString('more than double your usual 16 kL', $finding->explanation);
        $this->assertSame('meter_photo', $finding->action);
        $this->assertContains(['Your usual use', '16 kL a month, from 5 actual readings'], $finding->evidence);
    }

    public function test_estimate_streak_and_last_actual_reading_are_evidence(): void
    {
        $ctx = new CheckContext(Bills::agentUnitSeptember(), Bills::agentUnitHistory(), PropertyType::SectionalUnit);

        $finding = (new EstimatedReadingCheck)->run($ctx)->findings[0];

        $this->assertSame('Your water reading was estimated for the third month running', $finding->title);
        $this->assertSame('798.00', (string) $finding->amount);
        $this->assertSame('(31 − 12) kL × R42.00 = R798.00', $finding->calculation);
        $this->assertContains(['Estimated bills in a row', 'Jul, Aug and Sep'], $finding->evidence);
        $this->assertSame(3, $finding->data['streak']);
    }

    public function test_estimate_in_line_with_usual_use_passes(): void
    {
        $bill = Bills::month(2026, 9, water: 18, waterType: ReadingType::Estimated, waterRate: 42);
        $report = (new EstimatedReadingCheck)->run(new CheckContext($bill, Bills::householdHistory()));

        $this->assertSame([], $report->findings);
        $this->assertStringContainsString('in line with your usual use', $report->passed[0]);
    }

    public function test_estimate_without_history_asks_for_a_meter_photo_instead_of_guessing(): void
    {
        $report = (new EstimatedReadingCheck)->run(new CheckContext(Bills::householdSeptember()));

        $finding = $report->findings[0];
        $this->assertSame(Confidence::Possible, $finding->confidence);
        $this->assertNull($finding->amount);
        $this->assertSame('meter_photo', $finding->action);
    }

    public function test_outage_during_an_estimated_period_asks_the_customer_to_confirm(): void
    {
        $report = (new OutageCheck)->run($this->householdContext([$this->septemberOutage()]));

        $finding = $report->findings[0];
        $this->assertSame(Confidence::Question, $finding->confidence);
        $this->assertSame('89.60', (string) $finding->amount);
        $this->assertSame('16 kL ÷ 30 days × 4 days × R42.00 = R89.60', $finding->calculation);
        $this->assertSame('Was the water off at the property from 12 Sep to 15 Sep?', $finding->question);
        $this->assertContains(['Days billed without supply', '4 of 30'], $finding->evidence);
    }

    public function test_outage_with_an_actual_reading_is_not_an_overcharge(): void
    {
        $bill = Bills::month(2026, 9, water: 12, waterRate: 42);
        $ctx = new CheckContext($bill, Bills::householdHistory(), PropertyType::Residential, 'Parkhurst', [$this->septemberOutage()]);

        $report = (new OutageCheck)->run($ctx);

        $this->assertSame([], $report->findings);
        $this->assertStringContainsString('the reading is actual', $report->passed[0]);
    }

    public function test_outages_outside_the_period_or_without_a_suburb(): void
    {
        $august = new OutageWindow(Service::Water, new DateTimeImmutable('2026-08-02'), new DateTimeImmutable('2026-08-03'));
        $report = (new OutageCheck)->run($this->householdContext([$august]));
        $this->assertSame(['No outages were reported in Parkhurst during this billing period.'], $report->passed);

        $report = (new OutageCheck)->run($this->householdContext([$this->septemberOutage()], area: ''));
        $this->assertSame([], $report->findings);
        $this->assertSame(['Add your suburb so we can check for outages.'], $report->skipped);
    }

    public function test_residential_scheme_on_a_business_tariff_is_costed_from_the_tariff_table(): void
    {
        $history = [];
        $month = new DateTimeImmutable('2026-08-01');

        for ($i = 0; $i < 11; $i++) {
            $at = $month->modify("-{$i} months");
            $history[] = Bills::businessTariffBill((int) $at->format('Y'), (int) $at->format('n'));
        }

        $ctx = new CheckContext(
            bill: Bills::businessTariffBill(2026, 9),
            history: $history,
            propertyType: PropertyType::BulkResidential,
            tariffRates: Bills::tariffRates(),
        );

        $finding = (new TariffCheck)->run($ctx)->findings[0];

        $this->assertSame(Confidence::Likely, $finding->confidence);
        $this->assertSame('Billed on a business tariff instead of bulk residential', $finding->title);
        // The design's claim: R7,500 a month, R90,000 over the year on the wrong tariff.
        $this->assertSame('7500.00', (string) $finding->amount);
        $this->assertSame(12, $finding->data['months']);
        $this->assertSame('90000.00', $finding->data['back_refund_estimate']);
        $this->assertSame('500 kL × (R52.00 − R42.00) + 5,000 kWh × (R3.50 − R3.00) = R7,500.00', $finding->calculation);
        $this->assertContains(['Bills on this tariff', '12 in a row, since Oct 2025'], $finding->evidence);
    }

    public function test_tariff_without_rates_is_still_flagged_but_not_costed(): void
    {
        $ctx = new CheckContext(Bills::businessTariffBill(2026, 9), propertyType: PropertyType::Residential);

        $finding = (new TariffCheck)->run($ctx)->findings[0];

        $this->assertNull($finding->amount);
        $this->assertStringContainsString('once we have the current rates', $finding->explanation);
    }

    public function test_unknown_property_on_a_business_tariff_becomes_a_question(): void
    {
        $finding = (new TariffCheck)->run(new CheckContext(Bills::businessTariffBill(2026, 9)))->findings[0];

        $this->assertSame(Confidence::Question, $finding->confidence);
        $this->assertSame('What kind of property is this?', $finding->question);
        $this->assertSame(['residential', 'Home'], $finding->answerOptions[0]);
    }

    public function test_business_on_a_residential_tariff_is_not_raised(): void
    {
        $bill = Bills::month(2026, 9, water: 10, waterRate: 42);
        $report = (new TariffCheck)->run(new CheckContext($bill, propertyType: PropertyType::Business));

        $this->assertSame([], $report->findings);
        $this->assertSame([], $report->passed);
    }

    public function test_matching_tariff_passes(): void
    {
        $bill = Bills::month(2026, 9, water: 10, waterRate: 42, tariff: TariffCategory::Residential);
        $report = (new TariffCheck)->run(new CheckContext($bill, propertyType: PropertyType::Residential));

        $this->assertSame(['The tariff is residential, which matches a residential property.'], $report->passed);
    }

    public function test_spike_against_history_asks_before_calling_it_an_error(): void
    {
        $ctx = new CheckContext(Bills::agentUnitSeptember(), Bills::agentUnitHistory(), PropertyType::SectionalUnit);

        $report = (new SpikeCheck)->run($ctx);

        $this->assertCount(1, $report->findings);
        $finding = $report->findings[0];
        $this->assertSame('Electricity is 2.4 times your average', $finding->title);
        $this->assertSame(Confidence::Possible, $finding->confidence);
        $this->assertTrue($finding->amountIsUpperBound);
        $this->assertSame('2010.00', (string) $finding->amount);
        $this->assertSame('(1,140 − 470) kWh × R3.00 = R2,010.00', $finding->calculation);
        $this->assertSame('Did anything change at this property this month?', $finding->question);
    }

    public function test_spike_needs_enough_history(): void
    {
        $bill = Bills::month(2026, 9, electricity: 900, electricityRate: 3);
        $report = (new SpikeCheck)->run(new CheckContext($bill, array_slice(Bills::agentUnitHistory(), 0, 2)));

        $this->assertSame([], $report->findings);
        $this->assertStringContainsString('at least 3 past bills', $report->skipped[0]);
    }

    public function test_auditor_lists_the_household_findings_most_certain_first(): void
    {
        $report = (new Auditor)->audit($this->householdContext([$this->septemberOutage()]));

        $this->assertSame(
            [CheckType::EstimatedReading, CheckType::OutageCharge],
            array_map(fn ($f) => $f->check, $report->findings),
        );
        $this->assertContains('The tariff is residential, which matches a residential property.', $report->passed);
    }
}
