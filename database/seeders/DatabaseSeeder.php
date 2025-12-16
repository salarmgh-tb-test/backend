<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure a record exists in the `names` table with name = "Tradebytes"
        DB::table('names')->updateOrInsert(
            ['name' => 'Tradebytes'],
            ['name' => 'Tradebytes', 'created_at' => now(), 'updated_at' => now()]
        );
    }
}
