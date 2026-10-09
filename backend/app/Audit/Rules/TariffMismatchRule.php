<?php

namespace App\Audit\Rules;

use App\Audit\AuditContext;
use App\Audit\FindingData;
use App\Enums\FindingRule;
use App\Enums\PropertyType;
use App\Enums\Service;
use App\Enums\Severity;
use App\Models\Bill;
use App\Models\Finding;
use App\Models\LineItem;

/**
 * A line item's tariff category clearly names a customer class that
 * conflicts with the property type. A commercial/industrial property on a
 * residential tariff is never flagged (that error favours the customer).
 */
final class TariffMismatchRule extends BaseRule
{
    public function rule(): FindingRule
    {
        return FindingRule::TariffMismatch;
    }

    public function evaluate(AuditContext $context): array
    {
        $config = config('audit.tariff_mismatch');
        $propertyType = $context->property->property_type;
        $findings = [];
        $seen = [];

        foreach ($context->lineItems as $line) {
            /** @var LineItem $line */
            $tariff = trim((string) $line->tariff_category);
            if ($tariff === '' || isset($seen[$line->service->value])) {
                continue;
            }

            $matches = $this->matchClasses($tariff, $config['classes']);

            $business = $config['business_on_residential'];
            $singleDwelling = $config['single_dwelling_on_bulk'];

            if (isset($matches['business']) && ! isset($matches['residential'])
                && in_array($propertyType->value, $business['property_types'], true)) {
                $finding = $this->businessFinding($line, $tariff, $propertyType, $matches['business'], $business);
            } elseif (isset($matches['residential']) && ! isset($matches['bulk']) && ! isset($matches['business'])
                && in_array($propertyType->value, $singleDwelling['property_types'], true)) {
                $finding = $this->singleDwellingFinding($line, $tariff, $propertyType, $matches['residential'], $singleDwelling);
            } else {
                continue;
            }

            $seen[$line->service->value] = true;
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Case-insensitive whole-word matching. Returns class => first matched keyword.
     *
     * @param  array<string, list<string>>  $classes
     * @return array<string, string>
     */
    public function matchClasses(string $tariff, array $classes): array
    {
        $matches = [];
        foreach ($classes as $class => $keywords) {
            foreach ($keywords as $keyword) {
                if ($this->mentions($tariff, $keyword)) {
                    $matches[$class] = $keyword;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Whether the tariff text names the keyword as a whole word. Spaces and
     * dashes inside a keyword match any spaces or dashes ("non-residential"
     * also matches "Non Residential"). A keyword negated with "non" does not
     * count: "Non-Residential" is not a residential tariff.
     */
    private function mentions(string $tariff, string $keyword): bool
    {
        $words = preg_split('/[\s\p{Pd}]+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return false;
        }

        $separator = '[\s\p{Pd}]*';
        $body = implode($separator, array_map(fn (string $word) => preg_quote($word, '/'), $words));
        $pattern = '/(?<![\pL\pN])(non'.$separator.')?'.$body.'(?![\pL\pN])/iu';

        if (preg_match_all($pattern, $tariff, $found) < 1) {
            return false;
        }

        // Group 1 is the "non" in front of a match; any match without one counts.
        return in_array('', $found[1], true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function businessFinding(LineItem $line, string $tariff, PropertyType $type, string $keyword, array $config): FindingData
    {
        $name = $line->service->label();

        return new FindingData(
            rule: $this->rule(),
            service: $line->service,
            severity: Severity::from($config['severity']),
            confidence: (float) $config['confidence'],
            title: 'Your '.$name.' may be on a business tariff',
            explanation: $this->sentences([
                'Your '.$name.' is charged on what looks like a business tariff ("'.$tariff.'" on your bill), but you told us this property is '.$type->phrase().'.',
                'Business tariffs are often more expensive than residential ones, so you may be paying more than you should.',
                'Check the tariff on your bill. If the property is used as a home, ask the municipality to move it to the correct residential tariff and to credit you for the difference.',
            ]),
            estimatedOverchargeCents: null,
            evidence: [
                'service' => $line->service->value,
                'tariff_category' => $tariff,
                'property_type' => $type->value,
                'matched_keyword' => $keyword,
            ],
            lineItemId: $line->id,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function singleDwellingFinding(LineItem $line, string $tariff, PropertyType $type, string $keyword, array $config): FindingData
    {
        $name = $line->service->label();

        return new FindingData(
            rule: $this->rule(),
            service: $line->service,
            severity: Severity::from($config['severity']),
            confidence: (float) $config['confidence'],
            title: 'Check which '.$name.' tariff applies to your property',
            explanation: $this->sentences([
                'Your '.$name.' is charged on what looks like a tariff for a single home ("'.$tariff.'" on your bill). You told us this property is '.$type->phrase().'.',
                'Some municipalities use a different tariff for complexes and sectional title schemes, and others don\'t, so this may well be correct.',
                'If you\'re not sure, check your municipality\'s tariff list or ask your body corporate or the municipality to confirm.',
            ]),
            estimatedOverchargeCents: null,
            evidence: [
                'service' => $line->service->value,
                'tariff_category' => $tariff,
                'property_type' => $type->value,
                'matched_keyword' => $keyword,
            ],
            lineItemId: $line->id,
        );
    }

    public function letterParagraph(Finding $finding, Bill $bill): string
    {
        $service = $finding->service ?? Service::Water;
        $tariff = (string) ($finding->evidence['tariff_category'] ?? '');
        $type = PropertyType::tryFrom((string) ($finding->evidence['property_type'] ?? '')) ?? $bill->property->property_type;
        $amount = $this->rand($this->serviceAmountCents($bill, $service));

        if ($finding->severity === Severity::Low) {
            return 'The '.$service->label().' charges ('.$amount.') are billed on the "'.$tariff.'" tariff. The property is '
                .$type->phrase().'. Please confirm that this is the correct tariff for the property and, if it is not, correct it.';
        }

        return 'The '.$service->label().' charges ('.$amount.') are billed on what appears to be a business tariff ("'.$tariff.'"). The property is '
            .$type->phrase().' and should be billed on the applicable residential tariff. I ask that the tariff be corrected and the difference credited to my account.';
    }
}
