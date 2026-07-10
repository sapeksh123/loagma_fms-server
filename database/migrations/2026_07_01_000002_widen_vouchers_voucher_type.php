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
            // Widen for PDC | PDR (3 chars) on top of CP | BP | CR | BR | CN | DN | JV.
            $table->string('voucher_type', 10)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vouchers')) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('voucher_type', 2)->change();
        });
    }
};
