<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vouchers')) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) {
            // JV (Journal Voucher) has no single cash/bank header — allow NULL.
            $table->unsignedBigInteger('cash_bank_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vouchers')) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_bank_account_id')->nullable(false)->change();
        });
    }
};
