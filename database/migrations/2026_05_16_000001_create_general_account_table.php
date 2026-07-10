<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('general_account')) {
            return;
        }

        Schema::create('general_account', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('account_no', 100);
            $table->string('account_name', 255);
            $table->string('account_type', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('account_no');
            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_account');
    }
};