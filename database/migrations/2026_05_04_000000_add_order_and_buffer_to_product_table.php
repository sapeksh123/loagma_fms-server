<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOrderAndBufferToProductTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('product')) {
            return;
        }

        Schema::table('product', function (Blueprint $table) {
            if (!Schema::hasColumn('product', 'order_limit')) {
                $table->integer('order_limit')->nullable()->after('stock_ut_id');
            }
            if (!Schema::hasColumn('product', 'buffer_limit')) {
                $table->integer('buffer_limit')->nullable()->after('order_limit');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('product')) {
            return;
        }

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
