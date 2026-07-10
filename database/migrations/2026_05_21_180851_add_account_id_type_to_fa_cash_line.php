<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fa_cash_line', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->after('cash_receipt_id');
            $table->string('account_type', 50)->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        Schema::table('fa_cash_line', function (Blueprint $table) {
            $table->dropColumn(['account_id', 'account_type']);
        });
    }
};
