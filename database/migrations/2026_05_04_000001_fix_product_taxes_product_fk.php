<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixProductTaxesProductFk extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('product_taxes') || !Schema::hasTable('product')) {
            return;
        }

        $this->ensureProductIdIsIndexed();

        // Remove orphaned rows before adding the foreign key constraint.
        DB::table('product_taxes')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product')
                    ->whereColumn('product.product_id', 'product_taxes.product_id');
            })
            ->delete();

        // The production database still has an old FK pointing to product_old.
        // Drop it first, then re-create the correct relation to product.product_id.
        try {
            Schema::table('product_taxes', function (Blueprint $table) {
                $table->dropForeign('product_taxes_product_id_foreign');
            });
        } catch (\Throwable $e) {
            // Ignore if the foreign key was already removed or has a different name.
        }

        Schema::table('product_taxes', function (Blueprint $table) {
            $table->foreign('product_id')
                ->references('product_id')
                ->on('product')
                ->cascadeOnDelete();
        });
    }

    /**
     * Ensure the referenced product_id column is indexed so MySQL/TiDB can
     * create the foreign key constraint.
     */
    private function ensureProductIdIsIndexed(): void
    {
        $database = DB::getDatabaseName();

        $hasIndex = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'product')
            ->where('COLUMN_NAME', 'product_id')
            ->exists();

        if ($hasIndex) {
            return;
        }

        Schema::table('product', function (Blueprint $table) {
            $table->index('product_id', 'idx_product_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('product_taxes') || !Schema::hasTable('product')) {
            return;
        }

        try {
            Schema::table('product_taxes', function (Blueprint $table) {
                $table->dropForeign('product_taxes_product_id_foreign');
            });
        } catch (\Throwable $e) {
            // Ignore if the foreign key is already missing.
        }

        // Restore the previous relation only if the legacy table exists.
        if (Schema::hasTable('product_old')) {
            Schema::table('product_taxes', function (Blueprint $table) {
                $table->foreign('product_id')
                    ->references('product_id')
                    ->on('product_old')
                    ->cascadeOnDelete();
            });
        }
    }
}
