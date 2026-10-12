<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use App\Services\Municipalities\MunicipalityDirectory;
use App\Services\Municipalities\MunicipalityImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loading every municipality, and finding the one a bill comes from.
 */
class MunicipalityDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_directory_loads_every_municipality_once(): void
    {
        $johannesburg = Municipality::firstWhere('slug', 'johannesburg');
        $johannesburg->update(['letter_paragraph' => 'Quote section 102.', 'dispute_window_days' => 21]);

        $this->artisan('municipalities:import')->expectsOutput('254 added, 3 updated.')->assertSuccessful();
        $this->artisan('municipalities:import')->expectsOutput('0 added, 257 updated.')->assertSuccessful();

        $this->assertSame(257, Municipality::count());
        $this->assertSame(['A' => 8, 'B' => 205, 'C' => 44], Municipality::query()->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category')->map(fn ($n) => (int) $n)->all());

        // The existing metro keeps its rules, name and letter, and gains its code.
        $johannesburg->refresh();
        $this->assertSame('JHB', $johannesburg->code);
        $this->assertSame('City of Johannesburg', $johannesburg->name);
        $this->assertSame('Quote section 102.', $johannesburg->letter_paragraph);
        $this->assertSame(21, $johannesburg->dispute_window_days);
        $this->assertContains('City of Johannesburg Metropolitan Municipality', $johannesburg->aliases);
        $this->assertNull($johannesburg->billing_email_verified_at);
        $this->assertNotNull($johannesburg->billing_email);
    }

    public function test_an_email_staff_verified_is_never_overwritten(): void
    {
        $tshwane = Municipality::firstWhere('slug', 'tshwane');
        $tshwane->update(['billing_email' => 'disputes@tshwane.example.org', 'billing_email_verified_at' => now()]);

        app(MunicipalityImporter::class)->import();

        $this->assertSame('disputes@tshwane.example.org', $tshwane->refresh()->billing_email);
    }

    public function test_staff_verify_an_address_from_the_reference_screen(): void
    {
        $staff = User::factory()->staff()->create();
        $ethekwini = Municipality::firstWhere('slug', 'ethekwini');

        $this->actingAs($staff)->put(route('staff.municipalities.update', $ethekwini->id), [
            'billing_addressee' => 'Revenue, eThekwini',
            'billing_email' => 'revenue@durban.example.org',
            'billing_email_verified' => '1',
            'escalation_email' => 'manager@durban.example.org',
            'escalation_email_verified' => '0',
            'dispute_window_days' => 30,
            'reply_window_days' => 21,
            'councillor_window_days' => 30,
        ])->assertSessionHasNoErrors();

        $ethekwini->refresh();
        $this->assertNotNull($ethekwini->billing_email_verified_at);
        $this->assertSame('Entered by staff', $ethekwini->billing_email_source);
        $this->assertSame('manager@durban.example.org', $ethekwini->escalation_email);
        $this->assertNull($ethekwini->escalation_email_verified_at);
    }

    public function test_names_on_bills_match_the_directory(): void
    {
        app(MunicipalityImporter::class)->import();
        $directory = new MunicipalityDirectory;

        $this->assertSame('tshwane', $directory->match('CITY OF TSHWANE')?->slug);
        $this->assertSame('tshwane', $directory->match('City of Tshwane Metropolitan Municipality')?->slug);
        $this->assertSame('johannesburg', $directory->match('City of Joburg')?->slug);
        $this->assertSame('ethekwini', $directory->match('eThekwini Municipality, Durban')?->slug);
        $this->assertSame('WC024', $directory->match('Stellenbosch Municipality')?->code);
        $this->assertNull($directory->match('Springfield Municipality'));
        $this->assertNull($directory->match(null));
    }

    public function test_a_shared_name_is_settled_by_the_address_or_left_alone(): void
    {
        app(MunicipalityImporter::class)->import();
        $directory = new MunicipalityDirectory;

        $this->assertCount(2, $directory->candidates('Emalahleni Local Municipality'));
        $this->assertSame('MP312', $directory->match('Emalahleni Local Municipality', '14 Main Road, eMalahleni, Mpumalanga, 1035')?->code);
        $this->assertSame('EC136', $directory->match('Emalahleni Municipality', 'PO Box 1, Lady Frere, 5410')?->code);
        $this->assertNull($directory->match('Emalahleni Local Municipality', '12 Fourth Avenue'));
    }
}
