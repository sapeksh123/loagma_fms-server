<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voucher_details')) {
            return;
        }

        Schema::create('voucher_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('voucher_id');
            $table->string('account_category', 10);     // CUSTOMER | SUPPLIER | GENERAL
            $table->unsignedBigInteger('account_id');    // user.userid | suppliers.id | general_account.id
            $table->decimal('amount', 14, 2);
            $table->text('narration')->nullable();

            $table->index('voucher_id');
            $table->index(['account_category', 'account_id']);

            $table->foreign('voucher_id')
                ->references('id')->on('vouchers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_details');
    }
};
