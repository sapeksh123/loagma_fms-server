<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vouchers')) {
            return;
        }

        Schema::create('vouchers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('voucher_type', 2);          // CP | BP | CR | BR
            $table->string('voucher_no', 40);           // CP/25-26/0001
            $table->string('fy', 7);                    // 25-26
            $table->unsignedInteger('seq');             // per (type, fy) counter
            $table->date('voucher_date');
            $table->unsignedBigInteger('cash_bank_account_id'); // general_account.id (Cash/Bank)
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('narration')->nullable();
            $table->string('status', 12)->default('POSTED'); // POSTED | CANCELLED
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('voucher_no');
            $table->unique(['voucher_type', 'fy', 'seq']);
            $table->index(['voucher_type', 'voucher_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
