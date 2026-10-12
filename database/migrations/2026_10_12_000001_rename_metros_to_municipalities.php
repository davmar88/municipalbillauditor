<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables that point at a metro, and whether the link may be empty. */
    private const CHILDREN = ['properties' => true, 'outages' => false, 'tariff_rates' => false];

    public function up(): void
    {
        foreach (array_keys(self::CHILDREN) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign(['metro_id']));
        }

        Schema::rename('metros', 'municipalities');

        Schema::table('municipalities', function (Blueprint $table) {
            // The Municipal Demarcation Board code, such as JHB or WC024.
            $table->string('code', 10)->nullable()->unique()->after('id');
            // A metro, B local, C district.
            $table->char('category', 1)->default('B')->after('short_name');
            $table->string('province', 40)->nullable()->after('category');
            $table->string('district_code', 10)->nullable()->after('province');
            $table->string('seat', 60)->nullable()->after('district_code');
            $table->string('billing_email_source', 500)->nullable()->after('billing_email');
            // Set when a staff member has confirmed the address. The importer
            // never overwrites a verified address.
            $table->timestamp('billing_email_verified_at')->nullable()->after('billing_email_source');
            // Usually the municipal manager: the last step before the Public Protector.
            $table->string('escalation_email')->nullable()->after('billing_email_verified_at');
            $table->string('escalation_email_source', 500)->nullable()->after('escalation_email');
            $table->timestamp('escalation_email_verified_at')->nullable()->after('escalation_email_source');
            // Other names a bill may use, such as "City of Joburg".
            $table->json('aliases')->nullable()->after('escalation_email_verified_at');
            // Only the metros we wrote landing pages for get one.
            $table->boolean('has_landing_page')->default(false)->after('live');
        });

        foreach (['johannesburg' => ['JHB', 'Gauteng'], 'tshwane' => ['TSH', 'Gauteng'], 'ethekwini' => ['ETH', 'KwaZulu-Natal']] as $slug => [$code, $province]) {
            DB::table('municipalities')->where('slug', $slug)->update([
                'code' => $code,
                'category' => 'A',
                'province' => $province,
                'has_landing_page' => true,
            ]);
        }

        foreach (self::CHILDREN as $table => $nullable) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('metro_id', 'municipality_id'));

            Schema::table($table, function (Blueprint $t) use ($table, $nullable) {
                if ($nullable) {
                    // A household's first bill sets its municipality, so a
                    // property can exist before we know it.
                    $t->unsignedBigInteger('municipality_id')->nullable()->change();
                    $t->foreign('municipality_id')->references('id')->on('municipalities')->nullOnDelete();
                } else {
                    $t->foreign('municipality_id')->references('id')->on('municipalities')->cascadeOnDelete();
                }

                if ($table === 'tariff_rates') {
                    $t->renameIndex('tariff_rates_metro_id_category_service_index', 'tariff_rates_municipality_id_category_service_index');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::CHILDREN as $table => $nullable) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign(['municipality_id']);

                if ($table === 'tariff_rates') {
                    $t->renameIndex('tariff_rates_municipality_id_category_service_index', 'tariff_rates_metro_id_category_service_index');
                }
            });
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('municipality_id', 'metro_id'));
        }

        // Municipalities that weren't metros, and properties pointing at
        // them, can't go back into the old table.
        $extra = DB::table('municipalities')->whereNotIn('slug', ['johannesburg', 'tshwane', 'ethekwini'])->pluck('id');
        DB::table('properties')->whereIn('metro_id', $extra)->orWhereNull('metro_id')->delete();
        DB::table('outages')->whereIn('metro_id', $extra)->delete();
        DB::table('tariff_rates')->whereIn('metro_id', $extra)->delete();
        DB::table('municipalities')->whereIn('id', $extra)->delete();

        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code', 'category', 'province', 'district_code', 'seat', 'billing_email_source',
                'billing_email_verified_at', 'escalation_email', 'escalation_email_source',
                'escalation_email_verified_at', 'aliases', 'has_landing_page',
            ]);
        });

        Schema::rename('municipalities', 'metros');

        foreach (self::CHILDREN as $table => $nullable) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if ($table === 'properties') {
                    $t->unsignedBigInteger('metro_id')->nullable(false)->change();
                    $t->foreign('metro_id')->references('id')->on('metros')->restrictOnDelete();
                } else {
                    $t->foreign('metro_id')->references('id')->on('metros')->cascadeOnDelete();
                }
            });
        }
    }
};
