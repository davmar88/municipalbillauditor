<?php

namespace App\Services\Municipalities;

use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

/**
 * Loads the municipal directory from database/data/municipalities.csv.
 *
 * Rows are matched on their Demarcation Board code, so running it again
 * updates rather than duplicates. Staff work is never undone: a verified
 * email stays as it is, and so do each municipality's name, dispute rules,
 * letter paragraph and billing addressee once set. A different official
 * name in the file becomes an alias, so bills printing it still match.
 */
class MunicipalityImporter
{
    public const HEADER = [
        'code', 'slug', 'name', 'short_name', 'category', 'province', 'district_code', 'seat',
        'billing_email', 'billing_email_source', 'escalation_email', 'escalation_email_source', 'aliases',
    ];

    public static function defaultPath(): string
    {
        return database_path('data/municipalities.csv');
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function import(?string $path = null): array
    {
        $rows = $this->read($path ?? self::defaultPath());
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, &$created, &$updated) {
            foreach ($rows as $index => $row) {
                $municipality = Municipality::query()->where('code', $row['code'])->first()
                    // A row added before codes existed is found by its slug.
                    ?? Municipality::query()->whereNull('code')->where('slug', $row['slug'])->first();

                $municipality === null ? $created++ : $updated++;
                $municipality ??= new Municipality([
                    'billing_addressee' => 'Account queries, '.$row['name'],
                    'sort' => 100 + $index,
                ]);

                $aliases = $row['aliases'];

                if ($municipality->exists && $municipality->name !== $row['name']) {
                    $aliases[] = $row['name'];
                }

                $municipality->fill([
                    'code' => $row['code'],
                    'slug' => $row['slug'],
                    'name' => $municipality->exists ? $municipality->name : $row['name'],
                    'short_name' => $municipality->exists ? $municipality->short_name : $row['short_name'],
                    'category' => $row['category'],
                    'province' => $row['province'],
                    'district_code' => $row['district_code'],
                    'seat' => $row['seat'],
                    'aliases' => array_values(array_unique($aliases)),
                ]);

                if ($municipality->billing_email_verified_at === null) {
                    $municipality->fill([
                        'billing_email' => $row['billing_email'],
                        'billing_email_source' => $row['billing_email'] !== null ? $row['billing_email_source'] : null,
                    ]);
                }

                if ($municipality->escalation_email_verified_at === null) {
                    $municipality->fill([
                        'escalation_email' => $row['escalation_email'],
                        'escalation_email_source' => $row['escalation_email'] !== null ? $row['escalation_email_source'] : null,
                    ]);
                }

                $municipality->save();
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No municipality file at {$path}.");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $header = null;
        $rows = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($h) => trim((string) $h, " \xEF\xBB\xBF"), $line);
                $missing = array_diff(self::HEADER, $header);

                if ($missing !== []) {
                    throw new RuntimeException('The municipality file is missing: '.implode(', ', $missing).'.');
                }

                continue;
            }

            $values = array_combine($header, array_pad(array_map(fn ($v) => trim((string) $v), $line), count($header), ''));
            $blank = fn (string $key) => $values[$key] === '' ? null : $values[$key];

            if ($values['code'] === '' || ! in_array($values['category'], array_keys(Municipality::CATEGORIES), true)) {
                throw new RuntimeException('A row in the municipality file has no code or an unknown category: '.implode(',', $line));
            }

            $rows[] = [
                'code' => strtoupper($values['code']),
                'slug' => $values['slug'],
                'name' => $values['name'],
                'short_name' => $values['short_name'] !== '' ? $values['short_name'] : $values['name'],
                'category' => $values['category'],
                'province' => $blank('province'),
                'district_code' => $blank('district_code'),
                'seat' => $blank('seat'),
                'billing_email' => self::email($values['billing_email']),
                'billing_email_source' => $blank('billing_email_source'),
                'escalation_email' => self::email($values['escalation_email']),
                'escalation_email_source' => $blank('escalation_email_source'),
                'aliases' => array_values(array_filter(array_map('trim', explode('|', $values['aliases'])))),
            ];
        }

        return $rows;
    }

    private static function email(string $value): ?string
    {
        $value = strtolower(trim($value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }
}
