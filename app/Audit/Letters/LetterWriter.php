<?php

namespace App\Audit\Letters;

use App\Audit\Dates;
use App\Audit\Enums\CheckType;
use App\Audit\Enums\PropertyType;
use App\Audit\Enums\TariffCategory;
use App\Audit\Money;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Dispute and escalation letters.
 *
 * The letters state facts from the bill and the customer's answers, and ask
 * for specific corrections. They don't cite legislation: anything a metro
 * expects in a dispute is added per metro by staff, as an extra paragraph.
 */
final class LetterWriter
{
    public static function dispute(LetterInput $input): Letter
    {
        $month = $input->billMonth?->format('F Y') ?? 'latest';
        $dated = $input->billDate !== null ? ' (bill dated '.Dates::long($input->billDate).')' : '';
        $subject = "Dispute of the {$month} account{$dated}, account {$input->accountNumber}";

        $claims = [];
        $requests = [];

        foreach ($input->items as $item) {
            [$claim, $request] = self::claimAndRequest($item, $input);
            $claims[] = $claim;

            if (! in_array($request, $requests, true)) {
                $requests[] = $request;
            }
        }

        $requests[] = 'send a corrected account';
        $requests[] = 'acknowledge this dispute with a reference number';

        $disputed = Money::money(Money::sum(array_filter(array_map(fn (LetterItem $i) => $i->amount, $input->items))));
        $undisputed = null;

        if ($input->totalDue !== null) {
            $undisputed = BigDecimal::max(Money::money($input->totalDue->minus($disputed)), Money::money(0));
        }

        $lines = ["To: {$input->addressee}", "From: {$input->senderName}, account {$input->accountNumber}"];

        if ($input->propertyAddress !== '') {
            $lines[] = "Property: {$input->propertyAddress}";
        }

        $lines[] = 'Date: '.Dates::long($input->letterDate);
        array_push($lines, '', "Subject: {$subject}", '', 'I dispute these charges on the account:', '');

        foreach ($claims as $index => $claim) {
            $lines[] = ($index + 1).". {$claim}";
        }

        array_push($lines, '', 'Please:');

        foreach ($requests as $request) {
            $lines[] = "- {$request}";
        }

        if (trim($input->extraParagraph) !== '') {
            array_push($lines, '', trim($input->extraParagraph));
        }

        if ($undisputed !== null) {
            array_push($lines, '', 'I will keep paying the undisputed amount of '.Money::format($undisputed).' while this dispute is resolved.');
        }

        array_push($lines, '', 'Yours faithfully,', $input->senderName);

        return new Letter($subject, implode("\n", $lines), $disputed, $undisputed);
    }

    public static function councillor(
        string $metroName,
        string $senderName,
        string $accountNumber,
        string $propertyAddress,
        DateTimeImmutable $lodgedOn,
        DateTimeImmutable $replyDueOn,
        string $reference,
        BigDecimal $disputedTotal,
        DateTimeImmutable $letterDate,
    ): Letter {
        $ref = $reference !== '' ? " (reference {$reference})" : '';
        $subject = "Unresolved billing dispute, account {$accountNumber}";
        $lines = ['To: Ward councillor', "From: {$senderName}, account {$accountNumber}"];

        if ($propertyAddress !== '') {
            $lines[] = "Property: {$propertyAddress}";
        }

        array_push(
            $lines,
            'Date: '.Dates::long($letterDate),
            '',
            "Subject: {$subject}",
            '',
            "I lodged a billing dispute with {$metroName} on ".Dates::long($lodgedOn)."{$ref} about "
                .Money::format($disputedTotal).' on my account. A reply was due by '.Dates::long($replyDueOn)
                .', and I have not received one.',
            '',
            'Please help me get the dispute resolved. My original dispute and the bill are attached.',
            '',
            'Yours faithfully,',
            $senderName,
        );

        return new Letter($subject, implode("\n", $lines), Money::money($disputedTotal), null);
    }

    /**
     * @return array{0: string, 1: string} The claim sentence and what to ask for.
     */
    private static function claimAndRequest(LetterItem $item, LetterInput $input): array
    {
        $data = $item->data;
        $name = $item->service?->label() ?? 'Charges';
        $lower = strtolower($name);
        $amount = $item->amount !== null ? ', '.Money::format($item->amount) : '';

        switch ($item->check) {
            case CheckType::EstimatedReading:
                $claim = "{$name}{$amount}. The reading is marked as estimated";

                if (isset($data['usual'])) {
                    $claim .= ' and assumes '.Money::quantity($data['billed'])." {$data['unit']} against my usual "
                        .Money::quantity($data['usual'])." {$data['unit']}";
                }

                $claim .= '.';

                if ((int) ($data['streak'] ?? 1) >= 2) {
                    $claim .= " It has now been estimated for {$data['streak']} bills in a row.";
                }

                if ($input->hasMeterPhoto) {
                    $claim .= ' A dated photo of my meter is attached.';
                }

                return [$claim, "replace the estimated {$lower} reading with an actual reading"];

            case CheckType::OutageCharge:
                $when = self::dayMonth($data['starts_on'] ?? null).' to '.self::dayMonth($data['ends_on'] ?? null);
                $claim = $item->answer === 'yes'
                    ? "{$name}{$amount}. Supply was off from {$when}, but {$lower} was billed for those days."
                    : "{$name}{$amount}. An outage was reported in my area from {$when}, and {$lower} was billed for those days.";

                return [$claim, "reverse the {$lower} charges for the days without supply"];

            case CheckType::WrongTariff:
                $expected = TariffCategory::tryFrom((string) ($data['expected_category'] ?? ''))?->label() ?? 'correct';
                $printed = $data['printed_tariff'] ?? 'business';
                $property = $input->propertyType->isResidential()
                    ? $input->propertyType->description()
                    : PropertyType::Residential->description();
                $claim = "Tariff category{$amount}. The account is billed on the {$printed} tariff, but the property is {$property}.";
                $months = (int) ($data['months'] ?? 1);

                if ($months > 1) {
                    $claim .= " The same tariff appears on my last {$months} bills.";
                }

                return [$claim, 'move the account to the '.strtolower($expected).' tariff and credit the overcharge on past bills'];

            case CheckType::UsageSpike:
                $upTo = $item->amount !== null ? ', up to '.Money::format($item->amount) : '';
                $claim = "{$name}{$upTo}. The actual reading of ".Money::quantity($data['billed'])." {$data['unit']} is "
                    ."{$data['ratio']} times my average of ".Money::quantity($data['average'])." {$data['unit']}, "
                    .'with no change in use at the property.';

                return [$claim, "test the {$lower} meter for accuracy and correct the reading"];
        }
    }

    private static function dayMonth(?string $iso): string
    {
        return $iso !== null ? Dates::dayMonth(new DateTimeImmutable($iso)) : '';
    }
}
