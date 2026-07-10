<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bill_adjustments')) {
            return;
        }

        Schema::table('bill_adjustments', function (Blueprint $table) {
            // Widen for SALES_RETURN | PURCHASE_RETURN (15 chars) on top of SALES | PURCHASE.
            $table->string('invoice_type', 20)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bill_adjustments')) {
            return;
        }

        Schema::table('bill_adjustments', function (Blueprint $table) {
            $table->string('invoice_type', 10)->change();
        });
    }
};
