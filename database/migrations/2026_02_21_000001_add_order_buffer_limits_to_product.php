<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('product', 'order_limit')) {
            Schema::table('product', function (Blueprint $table) {
                $table->unsignedInteger('order_limit')->default(0)->after('seq_no');
            });
        }

        if (!Schema::hasColumn('product', 'buffer_limit')) {
            Schema::table('product', function (Blueprint $table) {
                $table->unsignedInteger('buffer_limit')->default(0)->after('order_limit');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('product', 'order_limit') || Schema::hasColumn('product', 'buffer_limit')) {
            Schema::table('product', function (Blueprint $table) {
                if (Schema::hasColumn('product', 'buffer_limit')) {
                    $table->dropColumn('buffer_limit');
                }

                if (Schema::hasColumn('product', 'order_limit')) {
                    $table->dropColumn('order_limit');
                }
            });
        }
    }
};
