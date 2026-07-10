<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('party_advances')) {
            return;
        }

        Schema::create('party_advances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('party_type', 10);           // CUSTOMER | SUPPLIER
            $table->unsignedBigInteger('party_id');
            $table->unsignedBigInteger('voucher_detail_id');
            $table->decimal('amount', 14, 2);
            $table->decimal('remaining_amount', 14, 2);

            $table->index(['party_type', 'party_id']);
            $table->index('voucher_detail_id');

            $table->foreign('voucher_detail_id')
                ->references('id')->on('voucher_details')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_advances');
    }
};
