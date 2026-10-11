<?php

namespace Tests\Feature;

use App\Enums\DisputeMode;
use App\Enums\OrganisationKind;
use App\Models\Dispute;
use App\Models\User;
use App\Services\BillIntake;
use App\Services\DisputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bills are personal financial data: one account never sees another's.
 */
class AccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_account_cannot_reach_a_bill_or_its_dispute(): void
    {
        Storage::fake('local');
        $owner = User::factory()->forKind(OrganisationKind::Household)->create();
        $bill = app(BillIntake::class)->createSample($owner->organisation, $owner);
        $bill->update(['file_path' => 'bills/1/secret.pdf', 'file_name' => 'bill.pdf', 'file_mime' => 'application/pdf']);
        Storage::disk('local')->put('bills/1/secret.pdf', 'PDF');
        $disputes = app(DisputeService::class);
        $dispute = $disputes->submit($disputes->draft($bill, $owner), DisputeMode::Self, $owner);
        $finding = $bill->findings->first();
        $property = $bill->property;

        $stranger = User::factory()->forKind(OrganisationKind::Agent)->create();
        $this->actingAs($stranger);

        $this->get(route('bills.show', $bill))->assertForbidden();
        $this->get(route('bills.file', $bill))->assertForbidden();
        $this->get(route('bills.details.edit', $bill))->assertForbidden();
        $this->post(route('bills.recheck', $bill))->assertForbidden();
        $this->delete(route('bills.destroy', $bill))->assertForbidden();
        $this->post(route('findings.dismiss', $finding))->assertForbidden();
        $this->get(route('disputes.create', $bill))->assertForbidden();
        $this->get(route('disputes.show', $dispute))->assertForbidden();
        $this->post(route('disputes.events.store', $dispute), ['kind' => 'note', 'happened_on' => now()->toDateString()])->assertForbidden();
        $this->put(route('properties.update', $property), ['name' => 'Mine now', 'metro_id' => $property->metro_id, 'property_type' => 'residential'])->assertForbidden();
        $this->delete(route('properties.destroy', $property))->assertForbidden();

        // A property from another account can't be used for an upload either.
        $this->post(route('bills.store'), ['property_id' => $property->id])->assertSessionHasErrors('property_id');

        // Lists only ever show the account's own records.
        $this->get(route('bills.index'))->assertInertia(fn ($page) => $page->has('bills', 0));
        $this->get(route('disputes.index'))->assertInertia(fn ($page) => $page->has('disputes', 0));

        // The owner still can.
        $this->actingAs($owner)->get(route('bills.file', $bill))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, Dispute::count());
    }
}
