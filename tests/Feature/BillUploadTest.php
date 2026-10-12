<?php

namespace Tests\Feature;

use App\Enums\BillSource;
use App\Enums\BillStatus;
use App\Enums\CreditKind;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Municipality;
use App\Models\User;
use App\Notifications\CustomerNotice;
use App\Services\Billing\Wallet;
use App\Services\Reading\BillReader;
use Carbon\CarbonImmutable;
use App\Services\Reading\BillReadingFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeBillReader;
use Tests\TestCase;

class BillUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    private function household(): User
    {
        return User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
    }

    private function reader(FakeBillReader $reader): FakeBillReader
    {
        $this->app->instance(BillReader::class, $reader);

        return $reader;
    }

    public function test_a_household_sends_its_first_bill_and_it_is_read_and_checked(): void
    {
        $reader = $this->reader(new FakeBillReader(FakeBillReader::output()));
        $user = $this->household();
        $johannesburg = Municipality::firstWhere('slug', 'johannesburg');

        $response = $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->create('my bill.pdf', 120, 'application/pdf'),
            'municipality_id' => $johannesburg->id,
        ]);

        $bill = Bill::sole();
        $response->assertRedirect(route('bills.show', $bill));

        $this->assertSame(['application/pdf'], $reader->mimeTypes);
        $this->assertSame(BillStatus::Checked, $bill->status);
        $this->assertSame('2026-10-03', $bill->bill_date->toDateString());
        $this->assertSame('2672.45', $bill->total_due);
        $this->assertSame('my bill.pdf', $bill->file_name);
        $this->assertStringNotContainsString('my bill', $bill->file_path);
        Storage::disk('local')->assertExists($bill->file_path);

        // The household's home is set up from the bill.
        $home = $bill->property;
        $this->assertSame('Home', $home->name);
        $this->assertSame($johannesburg->id, $home->municipality_id);
        $this->assertSame('5021 4478 2210', $home->account_number);
        $this->assertSame('Melville', $home->suburb);

        $this->get(route('bills.show', $bill))->assertInertia(fn (Assert $page) => $page
            ->component('bills/show')
            ->where('bill.status', 'checked')
            ->where('bill.totalDue', 'R2,672.45')
            ->where('bill.readings.0.consumption', '16')
            ->has('bill.charges', 4),
        );
    }

    public function test_the_first_household_bill_tells_us_the_municipality(): void
    {
        $this->reader(new FakeBillReader(FakeBillReader::output()));

        $this->actingAs($this->household())
            ->post(route('bills.store'), ['bill' => UploadedFile::fake()->image('bill.jpg')])
            ->assertSessionHasNoErrors();

        $bill = Bill::sole();
        $this->assertSame('johannesburg', $bill->property->municipality->slug);
        $this->assertSame('accounts@joburg.example.org', $bill->municipality_email);
        $this->assertSame(BillStatus::Checked, $bill->status);
    }

    public function test_an_unknown_municipality_is_left_for_the_customer_to_choose(): void
    {
        $this->reader(new FakeBillReader(FakeBillReader::output(['municipality' => 'Nowhere Local Municipality'])));
        $user = $this->household();

        $this->actingAs($user)->post(route('bills.store'), ['bill' => UploadedFile::fake()->image('bill.jpg')]);

        $bill = Bill::sole();
        $this->assertNull($bill->property->municipality_id);
        $this->get(route('bills.show', $bill))->assertInertia(fn (Assert $page) => $page
            ->where('bill.municipality', null)
            ->has('municipalities', 3),
        );

        $tshwane = Municipality::firstWhere('slug', 'tshwane');
        $this->patch(route('properties.municipality', $bill->property), ['municipality_id' => $tshwane->id])->assertRedirect();
        $this->assertSame($tshwane->id, $bill->property->refresh()->municipality_id);
    }

    public function test_only_photos_and_pdfs_are_accepted(): void
    {
        $this->actingAs($this->household())
            ->post(route('bills.store'), [
                'bill' => UploadedFile::fake()->create('bill.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                'municipality_id' => Municipality::first()->id,
            ])
            ->assertSessionHasErrors('bill');
    }

    public function test_a_bill_that_cannot_be_read_waits_for_its_details(): void
    {
        $this->reader(new FakeBillReader(new BillReadingFailed("This doesn't look like a municipal bill.")));
        $user = $this->household();

        $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->image('bill.jpg'),
            'municipality_id' => Municipality::first()->id,
        ]);

        $bill = Bill::sole();
        $this->assertSame(BillStatus::NeedsEntry, $bill->status);
        $this->assertSame("This doesn't look like a municipal bill.", $bill->review_note);

        // A failed read is refunded.
        $this->assertSame('100.00', app(Wallet::class)->balance($user->organisation));
        $this->assertSame(['job', 'refund'], $user->organisation->creditTransactions()->where('job', 'bill_check')->orderBy('id')->pluck('kind')->map->value->all());
    }

    public function test_a_bill_check_is_paid_once_from_credit(): void
    {
        $this->reader(new FakeBillReader(FakeBillReader::output()));
        $user = $this->household();

        $this->actingAs($user)->get(route('bills.index'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.price', 'R15.00')
            ->where('quote.affordable', true),
        );

        $this->post(route('bills.store'), ['bill' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')]);
        $bill = Bill::sole();
        $this->assertSame('85.00', app(Wallet::class)->balance($user->organisation));

        // Re-checking, answering or correcting costs nothing more.
        $this->post(route('bills.recheck', $bill))->assertRedirect();
        $this->assertSame('85.00', app(Wallet::class)->balance($user->organisation));
        Notification::assertSentTo($user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'bill_checked');
    }

    public function test_a_bill_without_credit_waits_and_is_checked_after_a_top_up(): void
    {
        $reader = $this->reader(new FakeBillReader(FakeBillReader::output()));
        $user = User::factory()->forKind(OrganisationKind::Household)->create();

        $this->actingAs($user)->post(route('bills.store'), ['bill' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')]);

        $bill = Bill::sole();
        $this->assertSame(BillStatus::WaitingForCredit, $bill->status);
        $this->assertSame([], $reader->mimeTypes, 'Nothing is read before it is paid for.');
        Notification::assertSentTo($user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'waiting_for_credit');

        $this->get(route('bills.show', $bill))->assertInertia(fn (Assert $page) => $page
            ->where('bill.status', 'waiting_for_credit')
            ->where('quote.shortfall', 'R15.00'),
        );

        app(Wallet::class)->credit($user->organisation, CreditKind::TopUp, '50', 'payment:test', 'Card top-up');

        $this->assertSame(BillStatus::Checked, $bill->refresh()->status);
        $this->assertSame('35.00', app(Wallet::class)->balance($user->organisation));
    }

    public function test_without_an_api_key_every_bill_is_typed_in(): void
    {
        $this->actingAs($this->household())->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->image('bill.jpg'),
            'municipality_id' => Municipality::first()->id,
        ]);

        $this->assertSame(BillStatus::NeedsEntry, Bill::sole()->status);
    }

    public function test_a_top_up_checks_only_the_waiting_bills_it_covers_in_order(): void
    {
        $reader = $this->reader(new FakeBillReader(FakeBillReader::output()));
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $this->actingAs($user);

        foreach (range(1, 3) as $n) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 09:00')->addMinutes($n));
            $this->post(route('bills.store'), ['bill' => UploadedFile::fake()->create("bill-{$n}.pdf", 50, 'application/pdf')]);
        }

        CarbonImmutable::setTestNow('2026-10-11 09:00');
        $waitingSince = Bill::orderBy('id')->pluck('waiting_since', 'id')->map(fn ($d) => (string) $d)->all();
        Notification::assertSentToTimes($user, CustomerNotice::class, 3);

        app(Wallet::class)->credit($user->organisation, CreditKind::TopUp, '20', 'payment:one', 'Card top-up');

        $bills = Bill::orderBy('id')->get();
        $this->assertSame(BillStatus::Checked, $bills[0]->status);
        $this->assertSame([BillStatus::WaitingForCredit, BillStatus::WaitingForCredit], [$bills[1]->status, $bills[2]->status]);
        $this->assertSame([$waitingSince[$bills[1]->id], $waitingSince[$bills[2]->id]], [(string) $bills[1]->waiting_since, (string) $bills[2]->waiting_since]);
        $this->assertCount(1, $reader->mimeTypes, 'Only the bill the credit covers is read.');
        $this->assertSame('5.00', app(Wallet::class)->balance($user->organisation));

        // One "bill checked" email; no new "waiting for credit" ones.
        Notification::assertSentToTimes($user, CustomerNotice::class, 4);
        Notification::assertSentTo($user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'bill_checked');

        CarbonImmutable::setTestNow();
    }

    public function test_typing_in_a_bill_checks_it(): void
    {
        $user = $this->household();
        $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->image('bill.jpg'),
            'municipality_id' => Municipality::first()->id,
        ]);
        $bill = Bill::sole();

        $this->get(route('bills.details.edit', $bill))->assertInertia(fn (Assert $page) => $page->component('bills/details'));

        $response = $this->put(route('bills.details.update', $bill), [
            'bill_date' => '2026-10-03',
            'account_number' => '5021 4478 2210',
            'tariff_label' => 'Residential',
            'total_due' => '2996.45',
            'readings' => [
                ['service' => 'water', 'previous' => '1204', 'current' => '1242', 'consumption' => '38', 'unit' => 'kL', 'reading_type' => 'estimated'],
            ],
            'charges' => [
                ['service' => 'water', 'description' => 'Water 38 kL', 'amount' => '1596.00'],
            ],
        ]);

        $response->assertRedirect(route('bills.show', $bill));
        $bill->refresh();
        $this->assertSame(BillStatus::Checked, $bill->status);
        $this->assertSame(BillSource::Upload, $bill->source);
        $this->assertSame('38.000', $bill->readings->first()->consumption);
    }

    public function test_a_sample_bill_shows_how_a_check_works(): void
    {
        $user = $this->household();

        $this->actingAs($user)->post(route('bills.sample'))->assertRedirect();

        $bill = $user->organisation->bills()->latest('bill_date')->first();
        $this->get(route('bills.show', $bill))->assertInertia(fn (Assert $page) => $page
            ->component('bills/show')
            ->where('bill.findings.0.amount', 'R924.00')
            ->where('bill.findings.0.calculation', '(38 − 16) kL × R42.00 = R924.00')
            ->has('bill.history.0.points', 6),
        );
    }

    public function test_deleting_a_bill_deletes_its_file(): void
    {
        $this->reader(new FakeBillReader(FakeBillReader::output()));
        $user = $this->household();
        $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->image('bill.jpg'),
            'municipality_id' => Municipality::first()->id,
        ]);
        $bill = Bill::sole();

        $this->delete(route('bills.destroy', $bill))->assertRedirect(route('bills.index'));

        $this->assertModelMissing($bill);
        Storage::disk('local')->assertMissing($bill->file_path);
    }

    public function test_the_bill_list_renders(): void
    {
        $this->actingAs($this->household())
            ->get(route('bills.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('bills/index')
                ->where('askMunicipality', true)
                ->has('municipalities', 3)
                ->has('checks', 4),
            );
    }
}
