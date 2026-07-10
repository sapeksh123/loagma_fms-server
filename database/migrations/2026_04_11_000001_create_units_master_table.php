<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('units_master')) {
            Schema::create('units_master', function (Blueprint $table) {
                $table->increments('unit_id');
                $table->string('unit_name', 100)->unique();
                $table->integer('serial_no')->nullable()->index();
                $table->decimal('conversion_rate', 10, 4);
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('units_master');
    }
};
