<?php

namespace App\Audit\Fees;

use App\Audit\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;

/**
 * What each customer pays.
 *
 * Mirrors the pricing model in pricing/bill-auditor-pricing-model.xlsx:
 * businesses pay a success fee on the back-refund plus the first 12 months of
 * savings, invoiced only once money reaches the municipal account; agents pay
 * per unit per month; households pay a flat fee for a managed dispute.
 */
final class Pricing
{
    public const SAVING_MONTHS = 12;

    public static function feeOn(BigNumber|int|string $amount, BigNumber|int|string $rate): BigDecimal
    {
        return Money::money(Money::of($amount)->multipliedBy($rate));
    }

    public static function successFee(
        BigNumber|int|string|null $backRefund,
        BigNumber|int|string|null $monthlySaving,
        BigNumber|int|string $rate,
        int $months = self::SAVING_MONTHS,
    ): SuccessFee {
        $backRefund = Money::money($backRefund ?? 0);
        $monthlySaving = Money::money($monthlySaving ?? 0);
        $rate = Money::of($rate);
        $monthlySavingFee = self::feeOn($monthlySaving, $rate);
        $backRefundFee = self::feeOn($backRefund, $rate);
        $savingsTotal = $monthlySaving->multipliedBy($months);

        // The savings fee is invoiced month by month, so its total is the sum
        // of the monthly invoices rather than a separately rounded figure.
        $savingsFeeTotal = $monthlySavingFee->multipliedBy($months);

        return new SuccessFee(
            rate: $rate,
            backRefund: $backRefund,
            backRefundFee: $backRefundFee,
            monthlySaving: $monthlySaving,
            savingMonths: $months,
            savingsTotal: $savingsTotal,
            monthlySavingFee: $monthlySavingFee,
            savingsFeeTotal: $savingsFeeTotal,
            totalWon: $backRefund->plus($savingsTotal),
            totalFee: $backRefundFee->plus($savingsFeeTotal),
        );
    }

    public static function subscriptionFee(int $units, BigNumber|int|string $pricePerUnit): BigDecimal
    {
        return Money::money(Money::of($pricePerUnit)->multipliedBy($units));
    }

    /**
     * The pricing model's "One example business client" rows: the back-refund
     * if an error is found (monthly bill x 12 x share of a year's bill) and the
     * success fee on it.
     *
     * @return array{0: BigDecimal, 1: BigDecimal}
     */
    public static function exampleBusinessClient(
        BigNumber|int|string $monthlyBill,
        BigNumber|int|string $backRefundShare,
        BigNumber|int|string $feeRate,
    ): array {
        $refund = Money::money(Money::of($monthlyBill)->multipliedBy(12)->multipliedBy($backRefundShare));

        return [$refund, self::feeOn($refund, $feeRate)];
    }
}
