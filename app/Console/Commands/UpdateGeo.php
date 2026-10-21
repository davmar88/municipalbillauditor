<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fetch DB-IP's free "IP to Country Lite" list (CC BY 4.0) for this month,
 * or last month's while this month's isn't out yet, and load it into the
 * ip_countries table that the Visitors page reads countries from.
 */
#[Signature('geo:update')]
#[Description('Load the free IP-to-country list the Visitors page uses')]
class UpdateGeo extends Command
{
    public function handle(): int
    {
        $file = tempnam(sys_get_temp_dir(), 'dbip');
        $fetched = false;

        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $url = 'https://download.db-ip.com/free/dbip-country-lite-'.$month->format('Y-m').'.csv.gz';

            if (Http::timeout(300)->sink($file)->get($url)->successful()) {
                $fetched = true;
                break;
            }
        }

        $gz = $fetched ? @gzopen($file, 'rb') : false;

        if ($gz === false) {
            @unlink($file);
            $this->error("DB-IP's country list could not be fetched.");

            return self::FAILURE;
        }

        $count = 0;

        // Read it a line at a time: the list is large.
        DB::transaction(function () use ($gz, &$count) {
            DB::table('ip_countries')->delete();
            $rows = [];

            while (($line = gzgets($gz)) !== false) {
                $parts = str_getcsv(trim($line), escape: '');

                if (count($parts) < 3) {
                    continue;
                }

                $from = @inet_pton($parts[0]);
                $to = @inet_pton($parts[1]);

                if ($from === false || $to === false) {
                    continue;
                }

                $rows[] = ['ip_from' => $from, 'ip_to' => $to, 'country' => strtoupper(substr($parts[2], 0, 2))];

                if (count($rows) === 1000) {
                    DB::table('ip_countries')->insert($rows);
                    $count += count($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table('ip_countries')->insert($rows);
                $count += count($rows);
            }
        });

        gzclose($gz);
        @unlink($file);

        $this->info("Loaded {$count} country ranges.");

        return self::SUCCESS;
    }
}
