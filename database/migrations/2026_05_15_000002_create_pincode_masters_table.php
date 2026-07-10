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
        if (Schema::hasTable('pincode_masters')) {
            return;
        }

        Schema::create('pincode_masters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('pincode', 20)->unique();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('country', 100)->default('India');
            $table->string('district', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('pincode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pincode_masters');
    }
};
