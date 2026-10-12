<?php

namespace App\Console\Commands;

use App\Enums\FeeKind;
use App\Enums\FeeStatus;
use App\Enums\OrganisationKind;
use App\Models\FeeInvoice;
use App\Models\Organisation;
use App\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Runs on the 1st of each month. Raises each agent's and business's monthly
 * fee and charges it to their saved card, or emails them a link to pay.
 * Safe to run again: an organisation gets one fee per month.
 */
#[Signature('fees:invoice {--month= : The month to invoice, as YYYY-MM. Defaults to this month.}')]
#[Description("Raise this month's fee for every managing agent and business")]
class InvoiceMonthlyFees extends Command
{
    public function handle(PaymentService $payments): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'))
            : CarbonImmutable::today()->startOfMonth();
        $raised = 0;

        $organisations = Organisation::query()
            ->whereIn('kind', [OrganisationKind::Agent, OrganisationKind::Business])
            ->orderBy('id')
            ->get();

        foreach ($organisations as $organisation) {
            $invoice = $this->raise($organisation, $month);

            if ($invoice === null) {
                continue;
            }

            $raised++;
            $payments->chargeSavedCard($invoice);
        }

        $this->info($raised === 1 ? '1 fee raised.' : "{$raised} fees raised.");

        return self::SUCCESS;
    }

    /**
     * The month's invoice, or null when it was raised already.
     */
    private function raise(Organisation $organisation, CarbonImmutable $month): ?FeeInvoice
    {
        $exists = $organisation->feeInvoices()
            ->where('kind', FeeKind::MonthlyFee)
            ->whereDate('period_month', $month->toDateString())
            ->exists();

        if ($exists) {
            return null;
        }

        try {
            return $organisation->feeInvoices()->create([
                'kind' => FeeKind::MonthlyFee,
                'description' => 'Monthly fee for '.$month->format('F Y'),
                'amount' => $organisation->monthlyFee(),
                'status' => FeeStatus::Issued,
                'period_month' => $month,
                'issued_on' => CarbonImmutable::today(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another run raised it a moment ago.
            return null;
        }
    }
}
