<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The Duck Pond gives water now, not clay (her call, 2026-10-20). Old Well still gives clay. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('regions')->where('key', 'duck_pond')->update(['produces' => 'water']);
    }

    public function down(): void
    {
        DB::table('regions')->where('key', 'duck_pond')->update(['produces' => 'clay']);
    }
};
