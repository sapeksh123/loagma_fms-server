<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (\Schema::hasTable('orders_item')) {
            $this->addIndexIfNotExists('orders_item', ['product_id'], 'idx_orders_item_product_id');
            $this->addIndexIfNotExists('orders_item', ['order_id'], 'idx_orders_item_order_id');
            $this->addIndexIfNotExists('orders_item', ['product_id', 'order_id'], 'idx_orders_item_product_order');
        }

        if (\Schema::hasTable('orders')) {
            $this->addIndexIfNotExists('orders', ['master_order_id'], 'idx_orders_master_order_id');
            $this->addIndexIfNotExists('orders', ['bill_number'], 'idx_orders_bill_number');
            $this->addIndexIfNotExists('orders', ['start_time'], 'idx_orders_start_time');
            $this->addIndexIfNotExists('orders', ['order_state', 'start_time'], 'idx_orders_state_start_time');
        }

        if (\Schema::hasTable('product')) {
            $this->addIndexIfNotExists('product', ['is_deleted'], 'idx_product_is_deleted');
        }
    }

    public function down(): void
    {
        if (\Schema::hasTable('orders_item')) {
            $this->dropIndexIfExists('orders_item', 'idx_orders_item_product_id');
            $this->dropIndexIfExists('orders_item', 'idx_orders_item_order_id');
            $this->dropIndexIfExists('orders_item', 'idx_orders_item_product_order');
        }

        if (\Schema::hasTable('orders')) {
            $this->dropIndexIfExists('orders', 'idx_orders_master_order_id');
            $this->dropIndexIfExists('orders', 'idx_orders_bill_number');
            $this->dropIndexIfExists('orders', 'idx_orders_start_time');
            $this->dropIndexIfExists('orders', 'idx_orders_state_start_time');
        }

        if (\Schema::hasTable('product')) {
            $this->dropIndexIfExists('product', 'idx_product_is_deleted');
        }
    }

    private function addIndexIfNotExists(string $table, array $columns, string $indexName): void
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        if (!empty($indexes)) {
            return;
        }

        $columnList = implode(',', $columns);
        DB::statement("ALTER TABLE {$table} ADD INDEX {$indexName}({$columnList})");
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        if (!empty($indexes)) {
            DB::statement("ALTER TABLE {$table} DROP INDEX {$indexName}");
        }
    }
};
