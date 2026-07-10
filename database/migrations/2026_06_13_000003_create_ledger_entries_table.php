<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ledger_entries')) {
            return;
        }

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('voucher_id');
            $table->string('ledger_source', 10);        // CUSTOMER | SUPPLIER | GENERAL
            $table->unsignedBigInteger('ledger_id');
            $table->decimal('dr_amount', 14, 2)->default(0);
            $table->decimal('cr_amount', 14, 2)->default(0);
            $table->date('entry_date');

            $table->index('voucher_id');
            $table->index(['ledger_source', 'ledger_id', 'entry_date']);

            $table->foreign('voucher_id')
                ->references('id')->on('vouchers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
