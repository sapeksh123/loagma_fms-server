<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'Electronics', 'description' => 'Electronics department', 'is_active' => true],
            ['name' => 'Sales', 'description' => 'Sales department', 'is_active' => true],
            ['name' => 'Finance', 'description' => 'Finance department', 'is_active' => true],
            ['name' => 'Purchase', 'description' => 'Purchase department', 'is_active' => true],
            ['name' => 'HR', 'description' => 'Human Resources department', 'is_active' => true],
            ['name' => 'Operations', 'description' => 'Operations department', 'is_active' => true],
            ['name' => 'IT', 'description' => 'Information Technology department', 'is_active' => true],
            ['name' => 'Marketing', 'description' => 'Marketing department', 'is_active' => true],
        ];

        foreach ($departments as $dept) {
            DB::table('departments')->updateOrInsert(
                ['name' => $dept['name']],
                $dept
            );
        }
    }
}
