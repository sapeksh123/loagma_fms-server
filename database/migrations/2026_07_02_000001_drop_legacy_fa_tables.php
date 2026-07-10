<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the legacy fa_* cash-voucher tables (fa_cash_main/fa_cash_line,
 * fa_cash_payment_main/fa_cash_payment_line, fa_main_line). Superseded by the
 * vouchers/voucher_details/ledger_entries model (2026_06_13_*); no current
 * model, controller, service, or route reads/writes these tables. They held
 * historical rows from the earlier cash-voucher system, which are not
 * preserved by this migration (dropped, not archived) per explicit decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fa_cash_line');
        Schema::dropIfExists('fa_cash_payment_line');
        Schema::dropIfExists('fa_main_line');
        Schema::dropIfExists('fa_cash_main');
        Schema::dropIfExists('fa_cash_payment_main');
    }

    public function down(): void
    {
        Schema::create('fa_cash_main', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('doc_no')->unique();
            $table->date('doc_date');
            $table->string('receipt_mode', 100)->nullable();
            $table->unsignedBigInteger('book_account_id')->nullable();
            $table->string('book_account_name', 255)->nullable();
            $table->string('status', 50)->default('draft');
            $table->timestamps();

            $table->foreign('book_account_id')
                ->references('id')
                ->on('general_account')
                ->onDelete('set null');
        });

        Schema::create('fa_cash_payment_main', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('doc_no')->unique();
            $table->date('doc_date');
            $table->unsignedBigInteger('book_account_id')->nullable();
            $table->string('book_account_name', 255)->nullable();
            $table->string('status', 50)->default('draft');
            $table->timestamps();

            $table->foreign('book_account_id')
                ->references('id')
                ->on('general_account')
                ->onDelete('set null');
        });

        Schema::create('fa_cash_line', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cash_receipt_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('account_code', 100)->nullable();
            $table->string('account_name', 255)->nullable();
            $table->string('account_type', 50)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('bill_no', 100)->nullable();
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->foreign('cash_receipt_id')
                ->references('id')
                ->on('fa_cash_main')
                ->onDelete('cascade');
        });

        Schema::create('fa_cash_payment_line', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cash_payment_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('account_code', 100)->nullable();
            $table->string('account_name', 255)->nullable();
            $table->string('account_type', 50)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('bill_no', 100)->nullable();
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->foreign('cash_payment_id')
                ->references('id')
                ->on('fa_cash_payment_main')
                ->onDelete('cascade');
        });

        Schema::create('fa_main_line', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('voucher_type', 50);
            $table->unsignedBigInteger('voucher_id');
            $table->unsignedInteger('voucher_no');
            $table->date('voucher_date');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('account_code', 100)->nullable();
            $table->string('account_name', 255)->nullable();
            $table->string('account_type', 50)->nullable();
            $table->char('dr_cr', 1)->default('D');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('bill_no', 100)->nullable();
            $table->text('narration')->nullable();
            $table->string('status', 50)->default('draft');
            $table->timestamps();

            $table->index(['voucher_type', 'voucher_id'], 'idx_fml_voucher');
            $table->index(['account_id', 'voucher_date'], 'idx_fml_account_date');
            $table->index('voucher_date', 'idx_fml_date');
            $table->index('status', 'idx_fml_status');
        });
    }
};
