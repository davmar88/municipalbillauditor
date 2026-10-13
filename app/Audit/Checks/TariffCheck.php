<?php

namespace App\Audit\Checks;

use App\Audit\Data\CheckContext;
use App\Audit\Data\CheckReport;
use App\Audit\Data\Finding;
use App\Audit\Enums\CheckType;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\PropertyType;
use App\Audit\Enums\TariffCategory;
use App\Audit\Money;

/**
 * The tariff category on the bill, compared with the kind of property.
 *
 * Only a residential property billed on a business tariff is raised: a
 * business on a residential tariff is not overcharged, so it is not ours to
 * flag.
 */
final class TariffCheck implements Check
{
    public function run(CheckContext $ctx): CheckReport
    {
        $report = new CheckReport;
        $bill = $ctx->bill;
        $billed = $bill->tariff;

        if ($billed === TariffCategory::Unknown) {
            $report->skipped[] = "We couldn't find a tariff category on this bill.";

            return $report;
        }

        $printed = $bill->tariffLabel !== '' ? $bill->tariffLabel : $billed->label();
        $propertyType = $ctx->propertyType;

        if ($propertyType === PropertyType::Unknown) {
            if ($billed === TariffCategory::Business) {
                $report->findings[] = new Finding(
                    check: CheckType::WrongTariff,
                    service: null,
                    confidence: Confidence::Question,
                    title: 'Is this property a business?',
                    explanation: "The bill uses the {$printed} tariff. If the property is residential, a business tariff "
                        .'means paying more for the same water and electricity.',
                    evidence: [['Tariff on the bill', $printed]],
                    question: 'What kind of property is this?',
                    answerOptions: array_map(fn (PropertyType $type) => [$type->value, $type->label()], PropertyType::known()),
                    data: ['billed_category' => $billed->value, 'printed_tariff' => $printed],
                );
            } else {
                $report->passed[] = 'The tariff is '.strtolower($printed).'.';
            }

            return $report;
        }

        $expected = $propertyType->expectedTariff();

        if ($billed === TariffCategory::Business && $propertyType->isResidential()) {
            $report->findings[] = $this->wrongTariff($ctx, $expected, $printed);
        } elseif ($billed === $expected || ($billed === TariffCategory::Residential && $propertyType === PropertyType::BulkResidential)) {
            $report->passed[] = 'The tariff is '.strtolower($printed).', which matches '.$propertyType->description().'.';
        }

        return $report;
    }

    private function wrongTariff(CheckContext $ctx, TariffCategory $expected, string $printed): Finding
    {
        $bill = $ctx->bill;
        $billed = $bill->tariff;
        $parts = [];
        $prices = [];
        $total = Money::zero();
        $missingRates = false;

        foreach ($bill->readings as $reading) {
            if (! $reading->service->isMetered()) {
                continue;
            }

            $used = $reading->used();
            $billedRate = $ctx->rate($billed, $reading->service);
            $fairRate = $ctx->rate($expected, $reading->service);

            if ($used === null || $billedRate === null || $fairRate === null) {
                $missingRates = true;

                continue;
            }

            $difference = $billedRate->minus($fairRate);

            if (! $difference->isPositive()) {
                continue;
            }

            $unit = $reading->unitLabel();
            $total = $total->plus(Money::money($used->multipliedBy($difference)));
            $parts[] = Money::quantity($used)." {$unit} × (".Money::format($billedRate).' − '.Money::format($fairRate).')';
            $prices[] = [
                $reading->service->label()." price per {$unit}",
                Money::format($billedRate).' business, '.Money::format($fairRate).' '.strtolower($expected->label()),
            ];
        }

        $amount = $parts !== [] ? $total : null;

        // How long the property has been on this tariff: this bill plus the
        // unbroken run of earlier bills on it.
        $onTariff = [$bill];

        foreach ($ctx->history as $past) {
            if ($past->tariff !== $billed) {
                break;
            }

            $onTariff[] = $past;
        }

        $months = count($onTariff);
        $since = end($onTariff)->month();
        $explanation = 'This is '.$ctx->propertyType->description().", but the bill uses the {$printed} tariff. "
            .'Business tariffs cost more for the same water and electricity.';

        if ($amount === null) {
            $explanation .= " We'll work out the overcharge once we have the current rates for both tariffs.";
        }

        $evidence = [
            ['Tariff on the bill', $printed],
            ['Should be', $expected->label()],
            ['Bills on this tariff', $months > 1 && $since !== null ? "{$months} in a row, since ".$since->format('M Y') : "{$months} in a row"],
            ...$prices,
        ];

        return new Finding(
            check: CheckType::WrongTariff,
            service: null,
            confidence: Confidence::Likely,
            title: 'Billed on a business tariff instead of '.strtolower($expected->label()),
            explanation: $explanation,
            amount: $amount,
            evidence: $evidence,
            calculation: $parts !== [] ? implode(' + ', $parts).' = '.Money::format($amount) : '',
            data: [
                'billed_category' => $billed->value,
                'expected_category' => $expected->value,
                'printed_tariff' => $printed,
                'months' => $months,
                'monthly_overcharge' => $amount !== null ? (string) $amount : null,
                'back_refund_estimate' => $amount !== null ? (string) Money::money($amount->multipliedBy($months)) : null,
                'missing_rates' => $missingRates,
            ],
        );
    }
}
