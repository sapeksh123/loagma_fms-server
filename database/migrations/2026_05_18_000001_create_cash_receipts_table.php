<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_cash_main');
    }
};
