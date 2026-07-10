<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PincodeMasterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pincodes = [
            ['pincode' => '380001', 'city' => 'Ahmedabad', 'state' => 'Gujarat', 'country' => 'India', 'district' => 'Ahmedabad', 'region' => 'Central'],
            ['pincode' => '380005', 'city' => 'Ahmedabad', 'state' => 'Gujarat', 'country' => 'India', 'district' => 'Ahmedabad', 'region' => 'Central'],
            ['pincode' => '380009', 'city' => 'Ahmedabad', 'state' => 'Gujarat', 'country' => 'India', 'district' => 'Ahmedabad', 'region' => 'Central'],
            ['pincode' => '110001', 'city' => 'New Delhi', 'state' => 'Delhi', 'country' => 'India', 'district' => 'Delhi', 'region' => 'North'],
            ['pincode' => '110002', 'city' => 'New Delhi', 'state' => 'Delhi', 'country' => 'India', 'district' => 'Delhi', 'region' => 'North'],
            ['pincode' => '110011', 'city' => 'New Delhi', 'state' => 'Delhi', 'country' => 'India', 'district' => 'Delhi', 'region' => 'North'],
            ['pincode' => '400001', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India', 'district' => 'Mumbai', 'region' => 'West'],
            ['pincode' => '400002', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India', 'district' => 'Mumbai', 'region' => 'West'],
            ['pincode' => '560001', 'city' => 'Bangalore', 'state' => 'Karnataka', 'country' => 'India', 'district' => 'Bangalore', 'region' => 'South'],
            ['pincode' => '560002', 'city' => 'Bangalore', 'state' => 'Karnataka', 'country' => 'India', 'district' => 'Bangalore', 'region' => 'South'],
            ['pincode' => '700001', 'city' => 'Kolkata', 'state' => 'West Bengal', 'country' => 'India', 'district' => 'Kolkata', 'region' => 'East'],
            ['pincode' => '700009', 'city' => 'Kolkata', 'state' => 'West Bengal', 'country' => 'India', 'district' => 'Kolkata', 'region' => 'East'],
        ];

        foreach ($pincodes as $pincode) {
            DB::table('pincode_masters')->updateOrInsert(
                ['pincode' => $pincode['pincode']],
                $pincode
            );
        }
    }
}
