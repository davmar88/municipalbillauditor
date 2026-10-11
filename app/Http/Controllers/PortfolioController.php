<?php

namespace App\Http\Controllers;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Deadlines\Stage;
use App\Audit\Enums\CheckType;
use App\Audit\Money;
use App\Enums\BillStatus;
use App\Enums\FindingState;
use App\Http\Presenters\BillPresenter;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A managing agent's view of every unit: what was checked this cycle, what
 * is flagged or in dispute, and what is due next.
 */
class PortfolioController extends Controller
{
    /** A unit without a bill this recent is chased as missing. */
    private const CYCLE_DAYS = 45;

    private const STATE_ORDER = ['escalated' => 0, 'flags' => 1, 'in_dispute' => 2, 'missing' => 3, 'pending' => 4, 'clear' => 5];

    public function __invoke(Request $request): Response
    {
        $organisation = $request->user()->organisation;
        $today = CarbonImmutable::today();

        $properties = $organisation->properties()
            ->with(['metro', 'latestBill.findings'])
            ->orderBy('name')
            ->orderBy('unit')
            ->get();

        $disputes = $organisation->disputes()
            ->whereNotNull('submitted_on')
            ->whereNull('credit_on')
            ->whereNull('closed_on')
            ->with(['property.metro', 'bill', 'findings'])
            ->latest('id')
            ->get()
            ->unique('property_id')
            ->keyBy('property_id');

        $rows = $properties
            ->map(fn (Property $property) => $this->row($property, $disputes->get($property->id), $today))
            ->sort(fn (array $a, array $b) => [$a['sortDate'] ?? '9999', self::STATE_ORDER[$a['state']]] <=> [$b['sortDate'] ?? '9999', self::STATE_ORDER[$b['state']]])
            ->values();

        $flagged = $rows->where('state', 'flags');
        $soon = $today->addDays(7)->toDateString();
        $due = $rows->filter(fn (array $row) => $row['sortDate'] !== null && $row['sortDate'] <= $soon)->values();

        // The same open flags the headline counts: on units not yet in dispute.
        $flaggedIds = $flagged->pluck('propertyId')->all();
        $openFindings = $properties
            ->filter(fn (Property $p) => in_array($p->id, $flaggedIds, true))
            ->map(fn (Property $p) => $p->latestBill?->findings ?? collect())
            ->flatten(1)
            ->filter(fn (Finding $f) => $f->state !== FindingState::Dismissed);

        $credited = $organisation->disputes()->whereYear('credit_on', $today->year)->get();

        return Inertia::render('portfolio/index', [
            'kpis' => [
                'units' => $properties->count(),
                'checked' => $rows->whereNotIn('state', ['missing', 'pending'])->count(),
                'missing' => $rows->where('state', 'missing')->count(),
                'openFlags' => $flagged->sum('flagCount'),
                'flaggedUnits' => $flagged->count(),
                'atStake' => Money::format(Money::sum($flagged->pluck('atStakeValue')->filter()->map(fn ($v) => Money::of($v)))),
                'dueSoon' => $due->count(),
                'firstDue' => $due->first()['deadlineLabel'] ?? null,
                'creditedThisYear' => Money::format(Money::sum($credited->map(fn (Dispute $d) => Money::of($d->credit_amount ?? 0)))),
                'resolvedThisYear' => $credited->count(),
            ],
            'rows' => $rows->map(fn (array $row) => collect($row)->except(['sortDate', 'atStakeValue'])->all())->values(),
            'due' => $due->take(8)->map(fn (array $row) => [
                'propertyId' => $row['propertyId'],
                'label' => $row['label'],
                'date' => $row['sortDate'],
                'action' => $row['deadlineLabel'],
                'overdue' => $row['sortDate'] < $today->toDateString(),
                'href' => $row['href'],
            ])->values(),
            'flagsByType' => collect(CheckType::cases())->map(fn (CheckType $type) => [
                'type' => $type->value,
                'label' => $type->label(),
                'count' => $openFindings->filter(fn (Finding $f) => $f->check_type === $type)->count(),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Property $property, ?Dispute $dispute, CarbonImmutable $today): array
    {
        $bill = $property->latestBill;
        $recent = $bill?->bill_date !== null && $bill->bill_date->greaterThanOrEqualTo($today->subDays(self::CYCLE_DAYS));
        $row = [
            'propertyId' => $property->id,
            'label' => $property->label(),
            'metro' => $property->metro->short_name,
            'billTotal' => $bill?->total_due !== null ? Money::format($bill->total_due) : null,
            'billMonth' => $bill?->monthLabel(),
            'state' => 'clear',
            'chip' => 'All clear',
            'flagCount' => 0,
            'atStake' => null,
            'atStakeValue' => null,
            'deadlineLabel' => null,
            'sortDate' => null,
            'href' => $bill ? route('bills.show', $bill) : route('bills.index'),
        ];

        if ($dispute !== null) {
            $stage = $dispute->stage($today);
            $status = $dispute->status($today);
            $escalated = in_array($stage, [Stage::ReplyOverdue, Stage::WithCouncillor, Stage::CouncillorOverdue, Stage::WithPublicProtector], true);

            return [
                ...$row,
                'state' => $escalated ? 'escalated' : 'in_dispute',
                'chip' => $dispute->lodged_on === null ? 'With our team' : $stage->label(),
                'atStake' => Money::format($dispute->disputed_total),
                'deadlineLabel' => match (true) {
                    $dispute->lodged_on === null => 'Lodging',
                    $stage === Stage::AwaitingReply => 'Reply due '.$status->nextDate?->format('j M'),
                    $stage === Stage::ReplyOverdue => 'Escalate to councillor',
                    $stage === Stage::WithCouncillor => 'Public Protector step '.$status->nextDate?->format('j M'),
                    $stage === Stage::CouncillorOverdue => 'Go to the Public Protector',
                    default => $stage->label(),
                },
                'sortDate' => $status->nextDate?->format('Y-m-d') ?? ($stage->needsAction() ? $today->toDateString() : null),
                'href' => route('disputes.show', $dispute),
            ];
        }

        if (! $recent) {
            return [...$row, 'state' => 'missing', 'chip' => 'Bill missing', 'deadlineLabel' => 'Request a copy'];
        }

        if ($bill->status !== BillStatus::Checked) {
            return [...$row, 'state' => 'pending', 'chip' => $bill->status->label()];
        }

        $open = $bill->findings->filter(fn (Finding $f) => $f->state !== FindingState::Dismissed);

        if ($open->isEmpty()) {
            return $row;
        }

        $deadline = CaseClock::disputeDeadline($bill->bill_date, $property->metro->rules());
        $upTo = BillPresenter::totals($open)['upTo'];

        return [
            ...$row,
            'state' => 'flags',
            'chip' => $open->count() === 1 ? '1 flag' : $open->count().' flags',
            'flagCount' => $open->count(),
            'atStake' => $upTo,
            'atStakeValue' => $this->sumAmounts($open),
            'deadlineLabel' => 'Dispute by '.$deadline->format('j M'),
            'sortDate' => $deadline->format('Y-m-d'),
        ];
    }

    /**
     * @param  Collection<int, Finding>  $findings
     */
    private function sumAmounts(Collection $findings): ?string
    {
        $amounts = $findings->filter(fn (Finding $f) => $f->amount !== null);

        return $amounts->isEmpty() ? null : (string) Money::sum($amounts->map(fn (Finding $f) => Money::of($f->amount)));
    }
}
