<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metros', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name');
            $table->string('short_name');
            $table->string('area_name')->nullable();
            $table->string('billing_addressee');
            $table->string('billing_email')->nullable();
            $table->unsignedSmallInteger('dispute_window_days')->default(30);
            $table->unsignedSmallInteger('reply_window_days')->default(21);
            $table->unsignedSmallInteger('councillor_window_days')->default(30);
            $table->boolean('rules_verified')->default(false);
            $table->text('rules_notes')->nullable();
            $table->text('letter_paragraph')->nullable();
            $table->boolean('live')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // The three metros named in the founder's research. Their dispute
        // rules are placeholders until each metro's process is researched.
        $note = 'Placeholder rules. The 30-day dispute window comes from an eThekwini councillor\'s advice in the '
            .'founder\'s notes; the reply and councillor windows are guesses. Verify against the metro before launch.';
        $now = now();

        DB::table('metros')->insert([
            ['slug' => 'johannesburg', 'name' => 'City of Johannesburg', 'short_name' => 'Johannesburg', 'area_name' => null,
                'billing_addressee' => 'Billing queries, City of Johannesburg', 'rules_notes' => $note, 'sort' => 1,
                'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'tshwane', 'name' => 'City of Tshwane', 'short_name' => 'Tshwane', 'area_name' => 'Pretoria',
                'billing_addressee' => 'Billing queries, City of Tshwane', 'rules_notes' => $note, 'sort' => 2,
                'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'ethekwini', 'name' => 'eThekwini Municipality', 'short_name' => 'eThekwini', 'area_name' => 'Durban',
                'billing_addressee' => 'Billing queries, eThekwini Municipality', 'rules_notes' => $note, 'sort' => 3,
                'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('metros');
    }
};
