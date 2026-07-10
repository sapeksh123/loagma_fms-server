<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bill_adjustments')) {
            return;
        }

        Schema::create('bill_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('voucher_detail_id');
            $table->string('invoice_type', 10);         // SALES | PURCHASE
            $table->unsignedBigInteger('invoice_id');   // orders.order_id | purchase_vouchers.id
            $table->string('adjustment_type', 15);      // AGAINST_REF | ON_ACCOUNT (DISCOUNT/AUTO_ALLOCATE reserved)
            $table->decimal('adjusted_amount', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);

            $table->index('voucher_detail_id');
            $table->index(['invoice_type', 'invoice_id']);

            $table->foreign('voucher_detail_id')
                ->references('id')->on('voucher_details')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_adjustments');
    }
};
