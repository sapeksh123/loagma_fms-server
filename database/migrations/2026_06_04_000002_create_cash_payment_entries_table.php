<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_cash_payment_line');
    }
};
