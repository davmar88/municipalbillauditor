<?php

namespace App\Services;

use App\Enums\DeadlineType;
use App\Enums\DisputeStatus;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lodge and escalation deadlines (docs/api.md, Dashboard).
 */
class DeadlineService
{
    /**
     * All deadlines, overdue included, sorted by due date.
     *
     * @return Collection<int, array{type: string, due_on: string, bill_id: int, dispute_id: int|null, property_nickname: string, label: string}>
     */
    public function upcoming(User $user, ?int $limit = 20): Collection
    {
        $deadlines = $this->lodgeDeadlines($user)
            ->concat($this->escalateDeadlines($user))
            ->sortBy(fn (array $d) => [$d['due_on'], $d['type'], $d['bill_id'], $d['dispute_id'] ?? 0])
            ->values();

        return $limit === null ? $deadlines : $deadlines->take($limit)->values();
    }

    /**
     * Deadlines due from today up to and including today + $days.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dueWithin(User $user, int $days, ?Carbon $today = null): Collection
    {
        $today = ($today ?? Carbon::today())->toDateString();
        $until = Carbon::parse($today)->addDays($days)->toDateString();

        return $this->upcoming($user, null)
            ->filter(fn (array $d) => $d['due_on'] >= $today && $d['due_on'] <= $until)
            ->values();
    }

    /**
     * Bills with at least one open medium/high finding that are not in a
     * dispute yet. A bill whose dispute is still a draft keeps its lodge
     * deadline (the draft has not been lodged), with the draft's id.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lodgeDeadlines(User $user): Collection
    {
        return Bill::query()
            ->ownedBy($user)
            ->whereNotNull('bill_date')
            ->where(fn (Builder $q) => $q
                ->whereHas('findings', fn (Builder $f) => $f->openAndCounted())
                ->orWhereHas('disputes', fn (Builder $d) => $d->where('status', DisputeStatus::Draft->value)))
            ->with(['property', 'disputes', 'findings'])
            ->get()
            ->map(function (Bill $bill) {
                $lodged = $bill->disputes->contains(fn (Dispute $d) => $d->status !== DisputeStatus::Draft);
                if ($lodged) {
                    return null;
                }

                $draft = $bill->disputes->sortByDesc('id')->first(fn (Dispute $d) => $d->status === DisputeStatus::Draft);
                $hasOpen = $bill->findings->contains(fn (Finding $f) => $f->counts());
                if ($draft === null && ! $hasOpen) {
                    return null;
                }

                return [
                    'type' => DeadlineType::LodgeDispute->value,
                    'due_on' => $bill->disputeDeadline()->toDateString(),
                    'bill_id' => $bill->id,
                    'dispute_id' => $draft?->id,
                    'property_nickname' => $bill->property->nickname,
                    'label' => 'Lodge a dispute for the '.Dates::short($bill->bill_date).' bill',
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Lodged, unresolved disputes that can still be escalated, due when
     * the municipality's response is due.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function escalateDeadlines(User $user): Collection
    {
        return Dispute::query()
            ->ownedBy($user)
            ->whereIn('status', array_map(fn (DisputeStatus $s) => $s->value, DisputeStatus::active()))
            ->whereNotNull('response_due_at')
            ->with('bill.property')
            ->get()
            ->filter(fn (Dispute $d) => $d->hasNextStep())
            ->map(fn (Dispute $d) => [
                'type' => DeadlineType::Escalate->value,
                'due_on' => $d->response_due_at->toDateString(),
                'bill_id' => $d->bill_id,
                'dispute_id' => $d->id,
                'property_nickname' => $d->bill->property->nickname,
                'label' => "Escalate if the municipality hasn't responded",
            ])
            ->values();
    }
}
