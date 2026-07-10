<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fa_main_line', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Voucher source reference
            $table->string('voucher_type', 50);        // cash_receipt | cash_payment | journal | …
            $table->unsignedBigInteger('voucher_id');   // PK of the source voucher header
            $table->unsignedInteger('voucher_no');      // human-readable doc_no
            $table->date('voucher_date');

            // Account (nullable: User/Supplier have no general_account row)
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('account_code', 100)->nullable();
            $table->string('account_name', 255)->nullable();
            $table->string('account_type', 50)->nullable(); // User | Supplier | General Account

            // Double-entry
            $table->char('dr_cr', 1)->default('D');         // D = Debit, C = Credit
            $table->decimal('amount', 15, 2)->default(0);

            $table->string('bill_no', 100)->nullable();
            $table->text('narration')->nullable();
            $table->string('status', 50)->default('draft'); // draft | posted

            $table->timestamps();

            $table->index(['voucher_type', 'voucher_id'], 'idx_fml_voucher');
            $table->index(['account_id', 'voucher_date'],  'idx_fml_account_date');
            $table->index('voucher_date',                  'idx_fml_date');
            $table->index('status',                        'idx_fml_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_main_line');
    }
};
