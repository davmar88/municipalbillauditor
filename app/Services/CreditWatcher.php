<?php

namespace App\Services;

use App\Audit\Enums\CheckType;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\TariffCategory;
use App\Audit\Money;
use App\Enums\DisputeEventKind;
use App\Models\Bill;
use App\Models\Charge;
use App\Models\Dispute;
use App\Models\Finding;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads each new bill for the outcome of earlier disputes: a credit on the
 * account, or a corrected tariff and the saving that follows.
 *
 * Refunds usually arrive as a credit on the municipal account rather than
 * cash, so this is how the customer sees a dispute work.
 */
class CreditWatcher
{
    public function __construct(
        private readonly DisputeService $disputes,
        private readonly CustomerMail $mail,
    ) {}

    public function inspect(Bill $bill): void
    {
        if ($bill->property_id === null || $bill->bill_date === null) {
            return;
        }

        $open = Dispute::query()
            ->where('property_id', $bill->property_id)
            ->whereNotNull('lodged_on')
            ->whereNull('closed_on')
            ->whereHas('bill', fn (Builder $q) => $q->where('bill_date', '<', $bill->bill_date))
            ->with(['findings', 'organisation', 'bill', 'property.municipality'])
            ->get();

        foreach ($open as $dispute) {
            $this->lookForCredit($dispute, $bill);
            $this->lookForTariffFix($dispute, $bill);
        }
    }

    private function lookForCredit(Dispute $dispute, Bill $bill): void
    {
        if ($dispute->credit_on !== null) {
            return;
        }

        $credits = $bill->charges()->where('amount', '<', 0)->get();

        if ($credits->isEmpty()) {
            return;
        }

        $total = Money::sum($credits->map(fn (Charge $c) => Money::of($c->amount)->abs()));

        $this->disputes->record($dispute, DisputeEventKind::CreditReceived, $bill->bill_date, [
            'amount' => (string) $total,
            'credit_bill_id' => $bill->id,
            'note' => 'Found on the '.$bill->monthLabel().' bill. Not the refund? Tell us and we\'ll keep looking.',
        ]);

        $this->mail->creditFound($dispute, (string) $total, $bill);
    }

    private function lookForTariffFix(Dispute $dispute, Bill $bill): void
    {
        $tariff = $dispute->findings->first(
            fn (Finding $f) => $f->check_type === CheckType::WrongTariff && $f->confidence === Confidence::Likely,
        );

        $expected = TariffCategory::tryFrom((string) ($tariff?->data['expected_category'] ?? ''));

        if ($expected === null || $bill->tariff_category !== $expected) {
            return;
        }

        if ($dispute->tariff_fixed_on === null) {
            $this->disputes->record($dispute, DisputeEventKind::TariffFixed, $bill->bill_date, [
                'amount' => $tariff->data['monthly_overcharge'] ?? null,
                'note' => 'The '.$bill->monthLabel().' bill uses the '.strtolower($expected->label()).' tariff.',
            ]);
        }
    }
}
