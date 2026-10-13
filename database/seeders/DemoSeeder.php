<?php

namespace Database\Seeders;

use App\Audit\Enums\PropertyType;
use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use App\Audit\Money;
use App\Enums\BillSource;
use App\Enums\BillStatus;
use App\Enums\CreditKind;
use App\Enums\DisputeEventKind;
use App\Enums\DisputeMode;
use App\Enums\FindingState;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Finding;
use App\Models\Municipality;
use App\Models\Organisation;
use App\Models\Outage;
use App\Models\Property;
use App\Models\TariffRate;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Billing\Wallet;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Example accounts for trying the app: a household, a managing agent, a
 * body corporate and a staff member, each with bills, findings and disputes
 * at different stages. Every figure is illustrative.
 *
 * php artisan db:seed --class=DemoSeeder
 *
 * Sign in as household@example.com, agent@example.com,
 * business@example.com or staff@example.com, password "password".
 */
class DemoSeeder extends Seeder
{
    /** Illustrative prices per unit, by tariff. */
    private const RATES = [
        'residential' => ['water' => '42.00', 'electricity' => '3.00'],
        'bulk_residential' => ['water' => '40.00', 'electricity' => '3.10'],
        'business' => ['water' => '52.00', 'electricity' => '3.60'],
    ];

    /** @var array<string, float> Running meter positions, per property and service. */
    private array $meters = [];

    private CarbonImmutable $today;

    /** The clock before seeding, so a test that froze time keeps it. */
    private ?CarbonImmutable $clock = null;

    public function __construct(
        private readonly AuditService $audit,
        private readonly DisputeService $disputes,
    ) {}

    public function run(): void
    {
        $this->clock = CarbonImmutable::hasTestNow() ? CarbonImmutable::now() : null;
        $this->today = CarbonImmutable::today();
        $this->call(MunicipalitySeeder::class);
        $municipalities = Municipality::query()->get()->keyBy('slug');

        $this->referenceData($municipalities->all());
        $this->household($municipalities['johannesburg']);
        $this->agent($municipalities->all());
        $this->business($municipalities['tshwane']);
        $this->staff();

        CarbonImmutable::setTestNow($this->clock);
    }

    /**
     * @param  array<string, Municipality>  $municipalities
     */
    private function referenceData(array $municipalities): void
    {
        $lastMonth = $this->month(1);

        Outage::create([
            'municipality_id' => $municipalities['johannesburg']->id,
            'suburb' => 'Melville',
            'service' => Service::Water,
            'starts_on' => $lastMonth->addDays(11),
            'ends_on' => $lastMonth->addDays(14),
            'source' => 'Example outage notice (demo data)',
        ]);

        Outage::create([
            'municipality_id' => $municipalities['ethekwini']->id,
            'suburb' => 'Umhlanga',
            'service' => Service::Electricity,
            'starts_on' => $this->month(2)->addDays(5),
            'ends_on' => $this->month(2)->addDays(6),
            'source' => 'Example outage notice (demo data)',
        ]);

        foreach (['bulk_residential', 'business'] as $category) {
            foreach (['water', 'electricity'] as $service) {
                TariffRate::create([
                    'municipality_id' => $municipalities['tshwane']->id,
                    'category' => $category,
                    'service' => $service,
                    'rate' => self::RATES[$category][$service],
                    'unit' => Service::from($service)->defaultUnit(),
                    'effective_from' => $this->month(18),
                    'source' => 'Illustrative rate for the demo, not a real tariff',
                    'verified' => false,
                ]);
            }
        }
    }

    /**
     * The canvas example: an estimated water reading, and water billed while
     * an outage was reported in the suburb.
     */
    private function household(Municipality $municipality): void
    {
        $user = $this->user('household@example.com', 'Thandi Mokoena', OrganisationKind::Household, 'Thandi Mokoena');
        $this->credit($user, '100');
        $home = $this->property($user->organisation, $municipality, 'Home', null, 'Melville', PropertyType::Residential, '5021 4478 2210', '12 Fourth Avenue, Melville');
        $water = [16, 15, 17, 16, 16];
        $power = [480, 455, 470, 490, 462];

        foreach ($water as $i => $kl) {
            $this->bill($home, $user, $this->month(6 - $i), ['water' => [$kl], 'electricity' => [$power[$i]]]);
        }

        $this->bill($home, $user, $this->month(1), [
            'water' => [38, ReadingType::Estimated],
            'electricity' => [470],
        ]);
    }

    /**
     * @param  array<string, Municipality>  $municipalities
     */
    private function agent(array $municipalities): void
    {
        $user = $this->user('agent@example.com', 'Pieter van Wyk', OrganisationKind::Agent, 'Jacaranda Property Management');
        $this->credit($user, '500');
        $org = $user->organisation;

        // Water estimated three months running, and an electricity spike.
        $unit12 = $this->property($org, $municipalities['johannesburg'], 'Jacaranda Court', '12', 'Bryanston', PropertyType::SectionalUnit, '4410 2287 0091');
        $this->history($unit12, $user, water: [12, 13, 12, 11, 12, 13, 12, 11, 12], power: [470, 480, 465, 490, 455, 470, 475, 460, 470], from: 4);
        $this->bill($unit12, $user, $this->month(3), ['water' => [31, ReadingType::Estimated], 'electricity' => [462]]);
        $this->bill($unit12, $user, $this->month(2), ['water' => [31, ReadingType::Estimated], 'electricity' => [478]]);
        $this->bill($unit12, $user, $this->month(1), ['water' => [31, ReadingType::Estimated], 'electricity' => [1140]]);

        // Disputed by the agent, waiting for the municipality's reply.
        $unit4 = $this->property($org, $municipalities['johannesburg'], 'Jacaranda Court', '4', 'Bryanston', PropertyType::SectionalUnit, '4410 2287 0034');
        $this->history($unit4, $user, water: [14, 15, 14, 13, 15], power: [380, 395, 372, 401, 388], from: 2);
        $bill = $this->bill($unit4, $user, $this->month(1), ['water' => [41, ReadingType::Estimated], 'electricity' => [390]]);
        $this->dispute($bill, $user, DisputeMode::Self, submitted: $this->after($bill, 2), reference: '8004 1123 77', acknowledged: $this->after($bill, 4));

        // Managed dispute, no reply in time: ready for the ward councillor.
        $unit21 = $this->property($org, $municipalities['ethekwini'], 'Harbour View', '21', 'Umhlanga', PropertyType::SectionalUnit, '512 448 902');
        $this->history($unit21, $user, water: [18, 17, 19, 18, 18], power: [520, 540, 515, 530, 525], from: 3);
        $bill = $this->bill($unit21, $user, $this->month(2), ['water' => [52, ReadingType::Estimated], 'electricity' => [530]]);
        $this->dispute($bill, $user, DisputeMode::Managed, submitted: $this->after($bill, 1), lodged: $this->after($bill, 2));
        $this->bill($unit21, $user, $this->month(1), ['water' => [18], 'electricity' => [528]]);

        // No bill for three months: the portfolio chases it.
        $unit3 = $this->property($org, $municipalities['ethekwini'], 'Harbour View', '3', 'Umhlanga', PropertyType::SectionalUnit, '512 448 871');
        $this->history($unit3, $user, water: [10, 11, 10, 12], power: [300, 310, 295, 305], from: 3);

        $unit7 = $this->property($org, $municipalities['tshwane'], 'Bluegum Mews', '7', 'Hatfield', PropertyType::SectionalUnit, '3009 1144 07');
        $this->history($unit7, $user, water: [9, 10, 9, 10, 9], power: [350, 342, 360, 355, 348], from: 2);
        $this->bill($unit7, $user, $this->month(1), ['water' => [22, ReadingType::Estimated], 'electricity' => [351]]);

        // Resolved earlier this year, with the credit on a later bill.
        $unit9 = $this->property($org, $municipalities['tshwane'], 'Bluegum Mews', '9', 'Hatfield', PropertyType::SectionalUnit, '3009 1144 09');
        $this->history($unit9, $user, water: [11, 12, 11, 12], power: [410, 400, 395, 405], from: 5);
        $old = $this->bill($unit9, $user, $this->month(4), ['water' => [29, ReadingType::Estimated], 'electricity' => [402]]);
        $this->dispute($old, $user, DisputeMode::Managed, submitted: $this->after($old, 1), lodged: $this->after($old, 2), reference: 'TSH-55120', acknowledged: $this->after($old, 5));
        $this->bill($unit9, $user, $this->month(3), ['water' => [11], 'electricity' => [398]]);
        $this->bill($unit9, $user, $this->month(2), ['water' => [12], 'electricity' => [404]], credit: '714.00');
        $this->bill($unit9, $user, $this->month(1), ['water' => [11], 'electricity' => [399]]);

        $unit1 = $this->property($org, $municipalities['johannesburg'], 'Protea Place', '1', 'Parkhurst', PropertyType::SectionalUnit, '4410 3390 0001');
        $this->history($unit1, $user, water: [8, 9, 8, 9, 8, 9], power: [300, 290, 310, 305, 295, 300], from: 1);

        // Handed to our team, not lodged yet: it shows in the staff queue.
        $unit6 = $this->property($org, $municipalities['johannesburg'], 'Protea Place', '6', 'Parkhurst', PropertyType::SectionalUnit, '4410 3390 0006');
        $this->history($unit6, $user, water: [13, 12, 14, 13, 13], power: [420, 410, 430, 415, 425], from: 2);
        $bill = $this->bill($unit6, $user, $this->month(1), ['water' => [36, ReadingType::Estimated], 'electricity' => [421]]);
        $this->dispute($bill, $user, DisputeMode::Managed, submitted: $this->after($bill, 3));
    }

    /**
     * A residential scheme billed on a business tariff for a year. The claim
     * was lodged, the tariff has been corrected, and the back-refund is
     * still to come.
     */
    private function business(Municipality $municipality): void
    {
        $user = $this->user('business@example.com', 'Naledi Dlamini', OrganisationKind::Business, 'Protea Heights Body Corporate');
        $this->credit($user, '500');
        $scheme = $this->property($user->organisation, $municipality, 'Protea Heights', null, 'Lynnwood', PropertyType::BulkResidential, '3008 7712 4410', '64 units, 12 Protea Road');
        $last = null;

        for ($ago = 15; $ago >= 4; $ago--) {
            $last = $this->bill($scheme, $user, $this->month($ago), ['water' => [400], 'electricity' => [5400]], tariff: TariffCategory::Business);
        }

        $this->dispute($last, $user, DisputeMode::Managed, submitted: $this->after($last, 3), lodged: $this->after($last, 5), reference: 'TSH-BC-20931', acknowledged: $this->after($last, 13));

        for ($ago = 3; $ago >= 1; $ago--) {
            $this->bill($scheme, $user, $this->month($ago), ['water' => [400], 'electricity' => [5400]], tariff: TariffCategory::BulkResidential);
        }
    }

    /**
     * Demo accounts pay as they go too, so each has some credit to try a
     * check and a dispute with.
     */
    private function credit(User $user, string $amount): void
    {
        app(Wallet::class)->credit($user->organisation, CreditKind::TopUp, $amount, "demo:{$user->organisation_id}", 'Demo credit');
    }

    private function staff(): void
    {
        $user = $this->user('staff@example.com', 'Sipho Ndlovu', OrganisationKind::Household, 'Bill Auditor team');
        $user->forceFill(['is_staff' => true])->save();
    }

    private function user(string $email, string $name, OrganisationKind $kind, string $organisation): User
    {
        $org = Organisation::create(['kind' => $kind, 'name' => $organisation]);

        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->forceFill(['email_verified_at' => $this->today]);
        $user->organisation()->associate($org);
        $user->save();

        return $user;
    }

    private function property(Organisation $org, Municipality $municipality, string $name, ?string $unit, string $suburb, PropertyType $type, string $account, ?string $address = null): Property
    {
        return $org->properties()->create([
            'municipality_id' => $municipality->id,
            'name' => $name,
            'unit' => $unit,
            'address' => $address ?? ($unit ? "Unit {$unit}, {$name}" : $name),
            'suburb' => $suburb,
            'property_type' => $type,
            'account_number' => $account,
        ]);
    }

    /**
     * Actual readings, oldest first, ending $from months ago.
     *
     * @param  list<int>  $water
     * @param  list<int>  $power
     */
    private function history(Property $property, User $user, array $water, array $power, int $from): void
    {
        $count = count($water);

        foreach ($water as $i => $kl) {
            $this->bill($property, $user, $this->month($from + $count - 1 - $i), ['water' => [$kl], 'electricity' => [$power[$i]]]);
        }
    }

    /**
     * One bill, read and checked like an uploaded one.
     *
     * @param  array<string, array{0: int, 1?: ReadingType}>  $use
     */
    private function bill(Property $property, User $user, CarbonImmutable $month, array $use, TariffCategory $tariff = TariffCategory::Residential, ?string $credit = null): Bill
    {
        $rates = self::RATES[$tariff === TariffCategory::Business ? 'business' : ($tariff === TariffCategory::BulkResidential ? 'bulk_residential' : 'residential')];
        $billDate = $this->clamp($month->addMonthNoOverflow()->addDays(2));
        $charges = [];
        $readings = [];

        foreach ($use as $service => [$used]) {
            $type = $use[$service][1] ?? ReadingType::Actual;
            $key = "{$property->id}:{$service}";
            $this->meters[$key] ??= $service === 'water' ? 1200.0 : 48000.0;
            $previous = $this->meters[$key];
            $this->meters[$key] += $used;
            $unit = Service::from($service)->defaultUnit();
            $amount = (string) Money::money(Money::of($used)->multipliedBy($rates[$service]));

            $readings[] = [
                'service' => $service,
                'meter_number' => $service === 'water' ? '07-'.(118800 + $property->id) : '55-'.(9100 + $property->id),
                'previous' => $previous,
                'current' => $previous + $used,
                'consumption' => $used,
                'reading_type' => $type,
                'unit' => $unit,
            ];
            $charges[] = ['service' => $service, 'description' => Service::from($service)->label()." consumption {$used} {$unit}", 'amount' => $amount, 'quantity' => $used, 'unit' => $unit];
        }

        $large = $property->property_type === PropertyType::BulkResidential;
        $charges[] = ['service' => Service::Sanitation, 'description' => 'Sanitation', 'amount' => $large ? '18240.00' : '488.60'];
        $charges[] = ['service' => Service::Refuse, 'description' => 'Refuse removal', 'amount' => $large ? '9620.00' : '318.40'];
        $charges[] = ['service' => Service::Rates, 'description' => 'Property rates', 'amount' => $large ? '41200.00' : '744.00'];

        if ($credit !== null) {
            $charges[] = ['service' => Service::Other, 'description' => 'Credit: billing adjustment', 'amount' => '-'.$credit];
        }

        $bill = $property->organisation->bills()->create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'source' => BillSource::Upload,
            'status' => BillStatus::Received,
            'bill_date' => $billDate,
            'due_date' => $billDate->addDays(23),
            'period_start' => $month,
            'period_end' => $month->endOfMonth()->startOfDay(),
            'account_number' => $property->account_number,
            'tariff_label' => $tariff->label(),
            'tariff_category' => $tariff,
            'total_due' => (string) Money::sum(array_map(fn (array $c) => Money::of($c['amount']), $charges)),
            'read_method' => 'demo',
            'created_at' => $billDate,
        ]);

        $bill->readings()->createMany($readings);
        $bill->charges()->createMany($charges);

        CarbonImmutable::setTestNow($billDate);
        $this->audit->run($bill);
        CarbonImmutable::setTestNow($this->clock);

        return $bill->refresh();
    }

    private function dispute(Bill $bill, User $user, DisputeMode $mode, CarbonImmutable $submitted, ?CarbonImmutable $lodged = null, ?string $reference = null, ?CarbonImmutable $acknowledged = null): void
    {
        // Answer any open questions the way a customer keeping the flag would.
        foreach ($bill->findings->filter(fn (Finding $f) => $f->awaitingAnswer()) as $finding) {
            $answer = collect($finding->answer_options)->pluck(0)->first(fn (string $v) => in_array($v, ['yes', 'keep'], true));

            if ($answer !== null) {
                $finding->update(['answer' => $answer, 'answered_at' => $submitted, 'state' => FindingState::Confirmed]);
            }
        }

        // Built directly rather than sent: the demo emails nobody.
        CarbonImmutable::setTestNow($submitted);
        $dispute = $this->disputes->draft($bill->refresh(), $user);
        $dispute->update(['mode' => $mode, 'submitted_on' => $submitted]);

        if ($mode === DisputeMode::Self) {
            $this->disputes->lodge($dispute->refresh(), $submitted, null, $user, 'Sent by you');
        } elseif ($lodged !== null) {
            CarbonImmutable::setTestNow($lodged);
            $this->disputes->lodge($dispute->refresh(), $lodged, null, null, 'Sent to the municipality');
        }

        if ($reference !== null && $acknowledged !== null) {
            CarbonImmutable::setTestNow($acknowledged);
            $this->disputes->record($dispute->refresh(), DisputeEventKind::Acknowledged, $acknowledged, ['reference' => $reference]);
        }

        CarbonImmutable::setTestNow($this->clock);
    }

    /** The first day of the month, $ago months back. */
    private function month(int $ago): CarbonImmutable
    {
        return $this->today->startOfMonth()->subMonthsNoOverflow($ago);
    }

    private function after(Bill $bill, int $days): CarbonImmutable
    {
        return $this->clamp($bill->bill_date->addDays($days));
    }

    private function clamp(CarbonImmutable $day): CarbonImmutable
    {
        return $day->greaterThan($this->today) ? $this->today : $day;
    }
}
