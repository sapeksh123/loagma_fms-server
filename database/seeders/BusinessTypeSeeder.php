<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $businessTypes = [
            ['name' => 'Manufacturer', 'description' => 'Manufacturing business', 'is_active' => true],
            ['name' => 'Distributor', 'description' => 'Distribution business', 'is_active' => true],
            ['name' => 'Retailer', 'description' => 'Retail business', 'is_active' => true],
            ['name' => 'Wholesaler', 'description' => 'Wholesale business', 'is_active' => true],
            ['name' => 'Service Provider', 'description' => 'Service providing business', 'is_active' => true],
            ['name' => 'Supplier', 'description' => 'Supply business', 'is_active' => true],
        ];

        foreach ($businessTypes as $type) {
            DB::table('business_types')->updateOrInsert(
                ['name' => $type['name']],
                $type
            );
        }
    }
}
