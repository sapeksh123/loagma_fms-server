<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Add indexes to taxes table for faster search
        if (\Schema::hasTable('taxes')) {
            $this->addIndexIfNotExists('taxes', 'tax_category', 'idx_tax_category');
            $this->addIndexIfNotExists('taxes', 'tax_sub_category', 'idx_tax_sub_category');
            $this->addIndexIfNotExists('taxes', 'tax_name', 'idx_tax_name');
            $this->addIndexIfNotExists('taxes', 'is_active', 'idx_tax_is_active');
            $this->addCompositeIndexIfNotExists('taxes', ['is_active', 'tax_category'], 'idx_tax_active_category');
        }

        // Add indexes to hsn_codes table
        if (\Schema::hasTable('hsn_codes')) {
            $this->addIndexIfNotExists('hsn_codes', 'hsn_code', 'idx_hsn_code');
            $this->addIndexIfNotExists('hsn_codes', 'is_active', 'idx_hsn_is_active');
        }

        // Add indexes to categories table
        if (\Schema::hasTable('categories')) {
            $this->addIndexIfNotExists('categories', 'name', 'idx_category_name');
            $this->addIndexIfNotExists('categories', 'parent_cat_id', 'idx_parent_cat_id');
            $this->addIndexIfNotExists('categories', 'is_active', 'idx_category_is_active');
            $this->addCompositeIndexIfNotExists('categories', ['parent_cat_id', 'is_active'], 'idx_parent_active');
        }
    }

    public function down(): void
    {
        if (\Schema::hasTable('taxes')) {
            $this->dropIndexIfExists('taxes', 'idx_tax_category');
            $this->dropIndexIfExists('taxes', 'idx_tax_sub_category');
            $this->dropIndexIfExists('taxes', 'idx_tax_name');
            $this->dropIndexIfExists('taxes', 'idx_tax_is_active');
            $this->dropIndexIfExists('taxes', 'idx_tax_active_category');
        }

        if (\Schema::hasTable('hsn_codes')) {
            $this->dropIndexIfExists('hsn_codes', 'idx_hsn_code');
            $this->dropIndexIfExists('hsn_codes', 'idx_hsn_is_active');
        }

        if (\Schema::hasTable('categories')) {
            $this->dropIndexIfExists('categories', 'idx_category_name');
            $this->dropIndexIfExists('categories', 'idx_parent_cat_id');
            $this->dropIndexIfExists('categories', 'idx_category_is_active');
            $this->dropIndexIfExists('categories', 'idx_parent_active');
        }
    }

    private function addIndexIfNotExists(string $table, string $column, string $indexName): void
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        if (empty($indexes)) {
            DB::statement("ALTER TABLE {$table} ADD INDEX {$indexName}({$column})");
        }
    }

    private function addCompositeIndexIfNotExists(string $table, array $columns, string $indexName): void
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        if (empty($indexes)) {
            $columnList = implode(',', $columns);
            DB::statement("ALTER TABLE {$table} ADD INDEX {$indexName}({$columnList})");
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        if (!empty($indexes)) {
            DB::statement("ALTER TABLE {$table} DROP INDEX {$indexName}");
        }
    }
};
