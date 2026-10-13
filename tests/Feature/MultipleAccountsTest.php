<?php

namespace Tests\Feature;

use App\Enums\CreditKind;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\BillIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One person, several accounts: a home, a rental portfolio, a business.
 * Each keeps its own credit, bills and forwarding address, and they switch
 * between them in the dashboard.
 */
class MultipleAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function personWithHomeAndBusiness(): array
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $home = $user->organisation;
        $business = Organisation::factory()->business()->create(['name' => 'Protea Heights Body Corporate']);
        $user->organisations()->attach($business);

        return [$user, $home, $business];
    }

    public function test_the_switcher_lists_every_account_and_switching_changes_what_they_see(): void
    {
        [$user, $home, $business] = $this->personWithHomeAndBusiness();
        $homeBill = app(BillIntake::class)->createSample($home, $user);

        $this->actingAs($user)->get(route('bills.index'))->assertInertia(fn (Assert $page) => $page
            ->where('organisation.id', $home->id)
            ->has('organisations', 2)
            ->where('organisations.1.name', 'Protea Heights Body Corporate')
            ->where('bills', fn ($bills) => count($bills) > 0),
        );

        $this->post(route('accounts.switch', $business))->assertRedirect(route('dashboard'));
        $this->assertSame($business->id, $user->refresh()->organisation_id);

        // The business sees none of the home's bills, and can't open them.
        $this->get(route('bills.index'))->assertInertia(fn (Assert $page) => $page
            ->where('organisation.id', $business->id)
            ->has('bills', 0),
        );
        $this->get(route('bills.show', $homeBill))->assertForbidden();

        // Still on both accounts, so both send it their emails.
        $this->assertTrue($home->users()->whereKey($user->id)->exists());
        $this->assertTrue($business->users()->whereKey($user->id)->exists());
    }

    public function test_nobody_can_switch_to_or_edit_someone_elses_account(): void
    {
        [$user] = $this->personWithHomeAndBusiness();
        $stranger = User::factory()->forKind(OrganisationKind::Agent)->create();
        $theirs = $stranger->organisation;

        $this->actingAs($user);
        $this->post(route('accounts.switch', $theirs))->assertNotFound();
        $this->patch(route('accounts.update', $theirs), ['kind' => 'household', 'name' => 'Mine now'])->assertNotFound();
        $this->delete(route('accounts.destroy', $theirs), ['confirm' => 'DELETE'])->assertNotFound();

        $this->assertNotSame($theirs->id, $user->refresh()->organisation_id);
        $this->assertNotSame('Mine now', $theirs->refresh()->name);
    }

    public function test_adding_an_account_starts_working_in_it(): void
    {
        [$user, $home] = $this->personWithHomeAndBusiness();

        $this->actingAs($user)->post(route('accounts.store'), ['kind' => 'agent', 'name' => '  Jacaranda Rentals '])
            ->assertRedirect(route('dashboard'));

        $added = $user->refresh()->organisation;
        $this->assertSame('Jacaranda Rentals', $added->name);
        $this->assertSame(OrganisationKind::Agent, $added->kind);
        $this->assertSame('0.00', app(Wallet::class)->balance($added));
        $this->assertCount(3, $user->organisations);
        $this->assertNotSame($home->forwarding_code, $added->forwarding_code);

        $this->post(route('accounts.store'), ['kind' => 'nonsense', 'name' => ''])->assertSessionHasErrors(['kind', 'name']);
    }

    public function test_an_account_can_be_renamed_and_its_type_changed(): void
    {
        [$user, , $business] = $this->personWithHomeAndBusiness();

        $this->actingAs($user)->patch(route('accounts.update', $business), ['kind' => 'agent', 'name' => 'Protea Rentals'])
            ->assertSessionHasNoErrors();

        $business->refresh();
        $this->assertSame('Protea Rentals', $business->name);
        $this->assertSame(OrganisationKind::Agent, $business->kind);

        $this->get(route('accounts.index'))->assertInertia(fn (Assert $page) => $page
            ->component('settings/accounts')
            ->has('accounts', 2)
            ->where('accounts.0.current', true)
            ->where('accounts.1.name', 'Protea Rentals')
            ->has('kinds', 3),
        );
    }

    public function test_removing_an_account_deletes_its_bills_and_moves_them_to_another(): void
    {
        [$user, $home, $business] = $this->personWithHomeAndBusiness();
        $user->switchTo($business);
        $bill = app(BillIntake::class)->createSample($business, $user);

        $this->actingAs($user)->delete(route('accounts.destroy', $business), ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->delete(route('accounts.destroy', $business), ['confirm' => 'DELETE'])->assertRedirect(route('accounts.index'));

        $this->assertNull(Organisation::find($business->id));
        $this->assertNull(Bill::find($bill->id));
        $this->assertSame($home->id, $user->refresh()->organisation_id);
    }

    public function test_the_last_account_and_one_holding_credit_are_kept(): void
    {
        [$user, $home, $business] = $this->personWithHomeAndBusiness();
        app(Wallet::class)->credit($business, CreditKind::TopUp, '50', 'test-top-up', 'Card top-up');

        $this->actingAs($user)->delete(route('accounts.destroy', $business), ['confirm' => 'DELETE'])
            ->assertSessionHasErrors(['confirm' => 'This account still has R50.00 of prepaid credit. Use it, or ask us to refund it, before removing the account.']);
        $this->assertNotNull(Organisation::find($business->id));

        $user->organisations()->detach($business);
        $this->delete(route('accounts.destroy', $home), ['confirm' => 'DELETE'])->assertSessionHasErrors('confirm');
        $this->assertNotNull(Organisation::find($home->id));
    }

    public function test_an_account_shared_with_someone_else_is_left_not_deleted(): void
    {
        [$user, , $business] = $this->personWithHomeAndBusiness();
        $colleague = User::factory()->create(['organisation_id' => $business->id]);

        $this->actingAs($user)->delete(route('accounts.destroy', $business), ['confirm' => 'DELETE'])->assertRedirect();

        $this->assertNotNull(Organisation::find($business->id));
        $this->assertFalse($user->belongsToOrganisation($business));
        $this->assertTrue($colleague->belongsToOrganisation($business));
    }

    public function test_someone_whose_account_went_moves_to_another_of_theirs(): void
    {
        [$user, $home, $business] = $this->personWithHomeAndBusiness();
        $user->switchTo($business);
        $business->purge();

        $this->actingAs($user->refresh())->get(route('bills.index'))->assertOk();

        $this->assertSame($home->id, $user->refresh()->organisation_id);
        $this->assertSame(1, Organisation::count());
    }

    public function test_deleting_a_profile_deletes_only_the_accounts_nobody_else_is_on(): void
    {
        [$user, $home, $business] = $this->personWithHomeAndBusiness();
        $shared = Organisation::factory()->agent()->create();
        $user->organisations()->attach($shared);
        $colleague = User::factory()->create(['organisation_id' => $shared->id]);

        $user->delete();

        $this->assertNull(Organisation::find($home->id));
        $this->assertNull(Organisation::find($business->id));
        $this->assertNotNull(Organisation::find($shared->id));
        $this->assertTrue($colleague->belongsToOrganisation($shared));
    }
}
