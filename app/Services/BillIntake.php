<?php

namespace App\Services;

use App\Audit\Enums\PropertyType;
use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use App\Audit\Money;
use App\Enums\BillSource;
use App\Enums\BillStatus;
use App\Jobs\ReadBill;
use App\Models\Bill;
use App\Models\Metro;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Takes bills in: uploads, forwarded e-bills and the demo sample.
 */
class BillIntake
{
    public const ACCEPTED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private readonly AuditService $audit) {}

    public function storeUpload(Organisation $organisation, UploadedFile $file, ?Property $property, ?User $user): Bill
    {
        return $this->store(
            $organisation,
            (string) file_get_contents($file->getRealPath()),
            $file->getClientOriginalName(),
            (string) $file->getMimeType(),
            $property,
            $user,
            BillSource::Upload,
        );
    }

    public function store(
        Organisation $organisation,
        string $contents,
        string $fileName,
        string $mimeType,
        ?Property $property,
        ?User $user,
        BillSource $source,
    ): Bill {
        $extension = match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        // A random name: the stored path never carries the customer's file name.
        $path = "bills/{$organisation->id}/".Str::uuid().".{$extension}";
        Storage::disk(config('auditor.bill_disk'))->put($path, $contents);

        $bill = $organisation->bills()->create([
            'property_id' => $property?->id,
            'user_id' => $user?->id,
            'source' => $source,
            'status' => BillStatus::Received,
            'file_path' => $path,
            'file_name' => Str::limit(basename($fileName), 200, ''),
            'file_mime' => $mimeType,
            'file_size' => strlen($contents),
        ]);

        ReadBill::dispatch($bill)->afterCommit();

        return $bill;
    }

    /**
     * The first bill from a household creates its home: households have one
     * property, so there is nothing to ask.
     */
    public function homeFor(Organisation $organisation, Metro $metro): Property
    {
        return $organisation->properties()->first() ?? $organisation->properties()->create([
            'metro_id' => $metro->id,
            'name' => 'Home',
            'property_type' => PropertyType::Residential,
        ]);
    }

    /**
     * A ready-made bill with five months of history, for trying the service
     * without a bill to hand. Its estimated water reading is a likely error.
     */
    public function createSample(Organisation $organisation, User $user): Bill
    {
        $metro = Metro::query()->where('live', true)->orderBy('sort')->firstOrFail();
        $property = $organisation->properties()->create([
            'metro_id' => $metro->id,
            'name' => 'Sample home',
            'address' => '38 Sample Street',
            'property_type' => PropertyType::Residential,
            'account_number' => 'SAMPLE-0001',
        ]);

        $thisMonth = CarbonImmutable::today()->startOfMonth();
        $usage = [16, 15, 17, 16, 16];
        $months = [];

        foreach ($usage as $index => $kilolitres) {
            $months[] = [$thisMonth->subMonthsNoOverflow(6 - $index), $kilolitres, ReadingType::Actual];
        }

        $months[] = [$thisMonth->subMonthNoOverflow(), 38, ReadingType::Estimated];
        $meter = 1100;
        $latest = null;

        foreach ($months as [$month, $kilolitres, $type]) {
            $water = (string) ($kilolitres * 42);
            // Bills arrive a few days into the next month, never in the future.
            $billDate = $month->addMonthNoOverflow()->addDays(2)->min(CarbonImmutable::today());
            $total = (string) Money::sum(array_map(fn ($amount) => Money::of($amount), [$water, '562.05', '318.40', '520.00']));

            $latest = $organisation->bills()->create([
                'property_id' => $property->id,
                'user_id' => $user->id,
                'source' => BillSource::Sample,
                'status' => BillStatus::Received,
                'bill_date' => $billDate,
                'due_date' => $billDate->addDays(23),
                'period_start' => $month,
                'period_end' => $month->endOfMonth()->startOfDay(),
                'account_number' => 'SAMPLE-0001',
                'tariff_label' => 'Residential',
                'tariff_category' => TariffCategory::Residential,
                'total_due' => $total,
                'read_method' => 'sample',
            ]);

            $latest->readings()->create([
                'service' => Service::Water,
                'meter_number' => '07-118843',
                'previous' => $meter,
                'current' => $meter + $kilolitres,
                'consumption' => $kilolitres,
                'reading_type' => $type,
                'unit' => 'kL',
            ]);
            $meter += $kilolitres;

            $latest->charges()->createMany([
                ['service' => Service::Water, 'description' => "Water {$kilolitres} kL", 'amount' => $water, 'quantity' => $kilolitres, 'unit' => 'kL'],
                ['service' => Service::Sanitation, 'description' => 'Sanitation', 'amount' => '562.05'],
                ['service' => Service::Refuse, 'description' => 'Refuse removal', 'amount' => '318.40'],
                ['service' => Service::Rates, 'description' => 'Property rates', 'amount' => '520.00'],
            ]);

            $this->audit->run($latest);
        }

        return $latest;
    }
}
