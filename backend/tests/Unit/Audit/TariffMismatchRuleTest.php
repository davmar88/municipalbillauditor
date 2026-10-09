<?php

namespace Tests\Unit\Audit;

use App\Audit\Rules\TariffMismatchRule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuditScenario;
use Tests\TestCase;

class TariffMismatchRuleTest extends TestCase
{
    private function evaluate(string $propertyType, ?string $tariff, string $service = 'water'): array
    {
        $s = new AuditScenario($propertyType);
        $bill = $s->bill('2026-01-01', '2026-01-31', [
            ['service' => $service, 'tariff_category' => $tariff, 'consumption' => 12, 'amount_cents' => 36000],
        ]);

        return (new TariffMismatchRule)->evaluate($s->context($bill));
    }

    public function test_residential_property_on_business_tariff_is_high(): void
    {
        $findings = $this->evaluate('residential', 'Business');

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('tariff_mismatch', $f->rule->value);
        $this->assertSame('high', $f->severity->value);
        $this->assertSame(0.75, $f->confidence);
        $this->assertNull($f->estimatedOverchargeCents);
        $this->assertSame([
            'service' => 'water',
            'tariff_category' => 'Business',
            'property_type' => 'residential',
            'matched_keyword' => 'business',
        ], $f->evidence);
        $this->assertStringContainsString('may', $f->explanation);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function businessTariffs(): array
    {
        return [
            'sectional title, commercial' => ['sectional_title', 'Commercial water'],
            'bulk residential, industrial' => ['bulk_residential', 'INDUSTRIAL'],
            'residential, small business' => ['residential', 'Small business'],
        ];
    }

    #[DataProvider('businessTariffs')]
    public function test_any_residential_type_on_any_business_keyword_is_high(string $type, string $tariff): void
    {
        $findings = $this->evaluate($type, $tariff, 'electricity');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity->value);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function noFinding(): array
    {
        return [
            // That error favours the customer: never flagged.
            'commercial on residential' => ['commercial', 'Residential'],
            'industrial on domestic' => ['industrial', 'Domestic'],
            'residential on residential' => ['residential', 'Residential'],
            'sectional title on bulk residential' => ['sectional_title', 'Residential bulk'],
            'sectional title on sectional title tariff' => ['sectional_title', 'Sectional title residential'],
            'body corporate tariff' => ['bulk_residential', 'Domestic - body corporate'],
            'no tariff' => ['residential', null],
            'empty tariff' => ['residential', '  '],
            'unknown class' => ['residential', 'Standard'],
            'ambiguous: residential and business' => ['residential', 'Residential/Business'],
            'keyword inside another word' => ['residential', 'Businesslike'],
            'commercial on business' => ['commercial', 'Business'],
            // "Non-" negates the keyword after it.
            'residential on non-commercial' => ['residential', 'Non-commercial'],
            'sectional title on non-business' => ['sectional_title', 'Non business'],
            'commercial on non-residential' => ['commercial', 'Non-Residential'],
            'industrial on non-domestic' => ['industrial', 'Non-domestic'],
        ];
    }

    #[DataProvider('noFinding')]
    public function test_no_finding(string $type, ?string $tariff): void
    {
        $this->assertSame([], $this->evaluate($type, $tariff));
    }

    public function test_sectional_title_on_single_dwelling_residential_tariff_is_low(): void
    {
        $findings = $this->evaluate('sectional_title', 'Residential');

        $this->assertCount(1, $findings);
        $this->assertSame('low', $findings[0]->severity->value);
        $this->assertSame(0.4, $findings[0]->confidence);
        $this->assertSame('residential', $findings[0]->evidence['matched_keyword']);
        $this->assertStringContainsString('may well be correct', $findings[0]->explanation);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonResidentialTariffs(): array
    {
        return [
            'residential, Non-Residential' => ['residential', 'Non-Residential'],
            'sectional title, Non-Residential' => ['sectional_title', 'Non-Residential'],
            'bulk residential, NON RESIDENTIAL' => ['bulk_residential', 'NON RESIDENTIAL'],
            'residential, en dash' => ['residential', 'Non–residential water'],
            'residential, one word' => ['residential', 'Nonresidential'],
            'sectional title, Non-domestic' => ['sectional_title', 'Non-domestic'],
        ];
    }

    /**
     * "Non-Residential" names the business class. It must never be read as
     * the residential class it negates.
     */
    #[DataProvider('nonResidentialTariffs')]
    public function test_a_non_residential_tariff_is_a_business_tariff(string $type, string $tariff): void
    {
        $findings = $this->evaluate($type, $tariff);

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity->value);
        $this->assertContains($findings[0]->evidence['matched_keyword'], ['non-residential', 'non-domestic']);
        $this->assertStringContainsString('business tariff', $findings[0]->explanation);
        $this->assertStringNotContainsString('single home', $findings[0]->explanation);
    }

    public function test_negated_keywords_do_not_count_for_their_class(): void
    {
        $rule = new TariffMismatchRule;
        $classes = config('audit.tariff_mismatch.classes');

        $this->assertSame(['business' => 'non-residential'], $rule->matchClasses('Non-Residential', $classes));
        $this->assertSame([], $rule->matchClasses('Non-commercial', $classes));
        $this->assertSame(['residential' => 'residential'], $rule->matchClasses('Residential (non-bulk)', $classes));
        // The keyword still counts when it also appears on its own.
        $this->assertSame(['business' => 'business', 'residential' => 'residential'], $rule->matchClasses('Non-business / Residential / Business', $classes));
    }

    public function test_sectional_title_on_a_non_bulk_residential_tariff_is_low(): void
    {
        $findings = $this->evaluate('sectional_title', 'Residential non-bulk');

        $this->assertCount(1, $findings);
        $this->assertSame('low', $findings[0]->severity->value);
        $this->assertSame('residential', $findings[0]->evidence['matched_keyword']);
    }

    public function test_one_finding_per_service(): void
    {
        $s = new AuditScenario('residential');
        $bill = $s->bill('2026-01-01', '2026-01-31', [
            ['service' => 'water', 'tariff_category' => 'Business', 'amount_cents' => 36000],
            ['service' => 'water', 'tariff_category' => 'Business', 'amount_cents' => 10000],
            ['service' => 'electricity', 'tariff_category' => 'Commercial', 'amount_cents' => 90000],
        ]);

        $findings = (new TariffMismatchRule)->evaluate($s->context($bill));

        $this->assertSame(['water', 'electricity'], array_map(fn ($f) => $f->service->value, $findings));
    }
}
