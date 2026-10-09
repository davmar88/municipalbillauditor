<?php

namespace Database\Seeders;

use App\Audit\AuditService;
use App\Models\Outage;
use App\Models\Property;
use App\Models\User;
use App\Services\BillService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo data: demo@example.com / password.
 *
 * "Sunset Court" (Johannesburg, sectional title) has six monthly bills. The
 * newest one is set up to show every common finding: an estimated water
 * reading, a business water tariff, water charged during a recorded outage
 * and an electricity spike. "Kloof Street" (Cape Town) has clean bills.
 */
class DatabaseSeeder extends Seeder
{
    public function run(BillService $bills, AuditService $audit): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'demo@example.com'],
            [
                'name' => 'Demo User',
                'password' => 'password',
                'popia_consent_version' => User::POPIA_CONSENT_VERSION,
                'popia_consented_at' => now(),
                'ai_extraction_consent' => true,
            ],
        );

        // Bill dates relative to today so the deadlines on the dashboard
        // are always upcoming.
        $newestBillDate = CarbonImmutable::today()->subDays(10);

        $this->seedSunsetCourt($user, $bills, $audit, $newestBillDate);
        $this->seedKloofStreet($user, $bills, $newestBillDate);
    }

    private function seedSunsetCourt(User $user, BillService $bills, AuditService $audit, CarbonImmutable $newest): void
    {
        $property = new Property([
            'nickname' => 'Sunset Court',
            'metro' => 'johannesburg',
            'account_number' => '5501234567',
            'address' => '12 Example Rd, Melville',
            'property_type' => 'sectional_title',
        ]);
        $property->user()->associate($user);
        $property->save();

        // Oldest first: water (kl, actual) and electricity (kWh, actual).
        $history = [
            ['water' => 11.0, 'electricity' => 410],
            ['water' => 12.5, 'electricity' => 395],
            ['water' => 12.0, 'electricity' => 430],
            ['water' => 13.0, 'electricity' => 405],
            ['water' => 11.5, 'electricity' => 420],
        ];

        $waterReading = 1150.0;
        $electricityReading = 30210.0;

        foreach ($history as $index => $usage) {
            $monthsAgo = count($history) - $index;
            $dates = $this->dates($newest->subMonthsNoOverflow($monthsAgo));

            $water = $this->meteredLine('water', 'Water consumption', 'Sectional title residential', 'actual', $waterReading, $usage['water'], 'kl', 3200);
            $waterReading += $usage['water'];
            $electricity = $this->meteredLine('electricity', 'Electricity consumption', null, 'actual', $electricityReading, $usage['electricity'], 'kwh', 340);
            $electricityReading += $usage['electricity'];

            $this->createBill($bills, $property, $user, $dates, [$water, $electricity, ...$this->fixedLines()]);
        }

        // The newest bill's period, with a 9.5-day water outage in it.
        $dates = $this->dates($newest);
        $periodStart = CarbonImmutable::parse($dates['period_start'], 'Africa/Johannesburg');

        $outage = new Outage([
            'service' => 'water',
            'starts_at' => $periodStart->addDays(5)->setTime(6, 0)->utc(),
            'ends_at' => $periodStart->addDays(14)->setTime(18, 0)->utc(),
            'notes' => 'Rand Water maintenance',
        ]);
        $outage->property()->associate($property);
        $outage->save();

        // Estimated water reading of 38 kl on a "Business" tariff, and an
        // actual electricity reading far above the usual ~410 kWh.
        $water = $this->meteredLine('water', 'Water consumption', 'Business', 'estimated', $waterReading, 38.0, 'kl', 3500);
        $electricity = $this->meteredLine('electricity', 'Electricity consumption', null, 'actual', $electricityReading, 1350, 'kwh', 340);

        $this->createBill($bills, $property, $user, $dates, [$water, $electricity, ...$this->fixedLines()]);

        $audit->auditProperty($property);
    }

    private function seedKloofStreet(User $user, BillService $bills, CarbonImmutable $newest): void
    {
        $property = new Property([
            'nickname' => 'Kloof Street',
            'metro' => 'cape_town',
            'account_number' => '3001234567',
            'address' => '8 Kloof St, Gardens, Cape Town',
            'property_type' => 'residential',
        ]);
        $property->user()->associate($user);
        $property->save();

        $usages = [
            ['water' => 9.0, 'electricity' => 310],
            ['water' => 10.0, 'electricity' => 300],
            ['water' => 9.5, 'electricity' => 320],
            ['water' => 10.0, 'electricity' => 305],
        ];

        $waterReading = 820.0;
        $electricityReading = 18400.0;

        foreach ($usages as $index => $usage) {
            $monthsAgo = count($usages) - 1 - $index;
            $dates = $this->dates($newest->subMonthsNoOverflow($monthsAgo)->subDays(3));

            $water = $this->meteredLine('water', 'Water', 'Domestic', 'actual', $waterReading, $usage['water'], 'kl', 3000);
            $waterReading += $usage['water'];
            $electricity = $this->meteredLine('electricity', 'Electricity', 'Domestic', 'actual', $electricityReading, $usage['electricity'], 'kwh', 320);
            $electricityReading += $usage['electricity'];

            $this->createBill($bills, $property, $user, $dates, [
                $water,
                $electricity,
                ['service' => 'sewerage', 'description' => 'Sewerage', 'amount_cents' => 38000],
                ['service' => 'refuse', 'description' => 'Refuse removal', 'amount_cents' => 26500],
                ['service' => 'rates', 'description' => 'Property rates', 'amount_cents' => 54000],
            ]);
        }
    }

    /**
     * @return array{bill_date: string, period_start: string, period_end: string, due_date: string}
     */
    private function dates(CarbonImmutable $billDate): array
    {
        $periodEnd = $billDate->subDays(6);

        return [
            'bill_date' => $billDate->toDateString(),
            'period_start' => $periodEnd->subMonthNoOverflow()->addDay()->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'due_date' => $billDate->addDays(20)->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meteredLine(string $service, string $description, ?string $tariff, string $readingType, float $previous, float $consumption, string $unit, int $centsPerUnit): array
    {
        return [
            'service' => $service,
            'description' => $description,
            'tariff_category' => $tariff,
            'reading_type' => $readingType,
            'previous_reading' => $previous,
            'current_reading' => $previous + $consumption,
            'consumption' => $consumption,
            'unit' => $unit,
            'amount_cents' => (int) round($consumption * $centsPerUnit),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixedLines(): array
    {
        return [
            ['service' => 'sewerage', 'description' => 'Sewerage', 'amount_cents' => 45000],
            ['service' => 'refuse', 'description' => 'Refuse removal', 'amount_cents' => 30000],
            ['service' => 'rates', 'description' => 'Property rates', 'amount_cents' => 62000],
        ];
    }

    /**
     * @param  array<string, string>  $dates
     * @param  list<array<string, mixed>>  $lines
     */
    private function createBill(BillService $bills, Property $property, User $user, array $dates, array $lines): void
    {
        $total = array_sum(array_map(fn (array $l) => $l['amount_cents'], $lines));

        $bills->create($property, $user, [...$dates, 'total_cents' => $total, 'line_items' => $lines], null);
    }
}
