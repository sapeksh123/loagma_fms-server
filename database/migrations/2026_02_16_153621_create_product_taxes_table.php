<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('product_taxes')) {
            Schema::create('product_taxes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedInteger('tax_id');
                $table->decimal('tax_percent', 5, 2);
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('product_id')
                    ->references('product_id')
                    ->on('product')
                    ->onDelete('cascade');

                $table->foreign('tax_id')
                    ->references('id')
                    ->on('taxes')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_taxes');
    }
};
