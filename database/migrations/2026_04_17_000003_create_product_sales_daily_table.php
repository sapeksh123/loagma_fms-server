<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_sales_daily', function (Blueprint $table) {
            $table->date('sale_date');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('total_orders')->default(0);
            $table->decimal('total_quantity', 14, 2)->default(0);
            $table->timestamps();

            $table->primary(['sale_date', 'product_id']);
            $table->index('product_id', 'idx_product_sales_daily_product_id');
            $table->index('sale_date', 'idx_product_sales_daily_sale_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_daily');
    }
};
