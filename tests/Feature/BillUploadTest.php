<?php

namespace Tests\Feature;

use App\Enums\BillSource;
use App\Enums\BillStatus;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Metro;
use App\Models\User;
use App\Services\Reading\BillReader;
use App\Services\Reading\BillReadingFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
    }

    private function household(): User
    {
        return User::factory()->forKind(OrganisationKind::Household)->create();
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
        $johannesburg = Metro::firstWhere('slug', 'johannesburg');

        $response = $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->create('my bill.pdf', 120, 'application/pdf'),
            'metro_id' => $johannesburg->id,
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
        $this->assertSame($johannesburg->id, $home->metro_id);
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

    public function test_the_first_household_bill_needs_its_metro(): void
    {
        $this->reader(new FakeBillReader(FakeBillReader::output()));

        $this->actingAs($this->household())
            ->post(route('bills.store'), ['bill' => UploadedFile::fake()->image('bill.jpg')])
            ->assertSessionHasErrors('metro_id');

        $this->assertSame(0, Bill::count());
    }

    public function test_only_photos_and_pdfs_are_accepted(): void
    {
        $this->actingAs($this->household())
            ->post(route('bills.store'), [
                'bill' => UploadedFile::fake()->create('bill.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                'metro_id' => Metro::first()->id,
            ])
            ->assertSessionHasErrors('bill');
    }

    public function test_a_bill_that_cannot_be_read_waits_for_its_details(): void
    {
        $this->reader(new FakeBillReader(new BillReadingFailed("This doesn't look like a municipal bill.")));
        $user = $this->household();

        $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->image('bill.jpg'),
            'metro_id' => Metro::first()->id,
        ]);

        $bill = Bill::sole();
        $this->assertSame(BillStatus::NeedsEntry, $bill->status);
        $this->assertSame("This doesn't look like a municipal bill.", $bill->review_note);
    }

    public function test_without_an_api_key_every_bill_is_typed_in(): void
    {
        $this->actingAs($this->household())->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->image('bill.jpg'),
            'metro_id' => Metro::first()->id,
        ]);

        $this->assertSame(BillStatus::NeedsEntry, Bill::sole()->status);
    }

    public function test_typing_in_a_bill_checks_it(): void
    {
        $user = $this->household();
        $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->image('bill.jpg'),
            'metro_id' => Metro::first()->id,
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
            'metro_id' => Metro::first()->id,
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
                ->where('needsMetro', true)
                ->has('metros', 3)
                ->has('checks', 4),
            );
    }
}
