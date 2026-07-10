<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voucher_pdc_details')) {
            return;
        }

        // One row per PDC/PDR voucher (post-dated cheque metadata). The cheque
        // amount is posted straight to the real cash/bank ledger like CP/BP/CR/BR
        // at entry time; this table only tracks the physical cheque's lifecycle
        // (pending -> cleared / bounced) for the PDC Outstanding Report.
        Schema::create('voucher_pdc_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('voucher_id');
            $table->string('cheque_no', 40);
            $table->date('cheque_date'); // due / maturity date printed on the cheque
            $table->string('bank_name', 100)->nullable();
            $table->string('status', 12)->default('PENDING'); // PENDING | CLEARED | BOUNCED
            $table->date('cleared_date')->nullable();
            $table->date('bounced_date')->nullable();
            $table->timestamps();

            $table->unique('voucher_id');
            $table->index(['status', 'cheque_date']);

            $table->foreign('voucher_id')
                ->references('id')->on('vouchers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_pdc_details');
    }
};
