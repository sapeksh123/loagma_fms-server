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
        if (!Schema::hasTable('user')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            if (!Schema::hasColumn('user', 'is_email_verified')) {
                $table->tinyInteger('is_email_verified')->default(0)->after('email');
            }
            if (!Schema::hasColumn('user', 'contactno')) {
                $table->string('contactno', 250)->unique()->nullable()->after('is_email_verified');
            }
            if (!Schema::hasColumn('user', 'is_contact_verified')) {
                $table->tinyInteger('is_contact_verified')->default(0)->after('contactno');
            }
            if (!Schema::hasColumn('user', 'account_state')) {
                $table->string('account_state', 250)->default('ACTIVE')->after('is_contact_verified');
            }
            if (!Schema::hasColumn('user', 'address')) {
                $table->text('address')->nullable()->after('account_state');
            }
            if (!Schema::hasColumn('user', 'latitude')) {
                $table->decimal('latitude', 10, 6)->nullable()->after('address');
            }
            if (!Schema::hasColumn('user', 'longitude')) {
                $table->decimal('longitude', 10, 6)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('user', 'dob')) {
                $table->text('dob')->nullable()->after('longitude');
            }
            if (!Schema::hasColumn('user', 'register_date')) {
                $table->unsignedInteger('register_date')->nullable()->after('dob');
            }
            if (!Schema::hasColumn('user', 'shop_name')) {
                $table->string('shop_name', 255)->nullable()->after('register_date');
            }
            if (!Schema::hasColumn('user', 'shop_address')) {
                $table->string('shop_address', 255)->nullable()->after('shop_name');
            }
            if (!Schema::hasColumn('user', 'shop_plot_no')) {
                $table->string('shop_plot_no', 255)->nullable()->after('shop_address');
            }
            if (!Schema::hasColumn('user', 'user_type')) {
                $table->enum('user_type', ['B2C', 'B2B'])->default('B2C')->after('shop_plot_no');
            }
            if (!Schema::hasColumn('user', 'adhar_card')) {
                $table->string('adhar_card', 255)->nullable()->after('user_type');
            }
            if (!Schema::hasColumn('user', 'shop_photo')) {
                $table->string('shop_photo', 255)->nullable()->after('adhar_card');
            }
            if (!Schema::hasColumn('user', 'shop_licence')) {
                $table->string('shop_licence', 255)->nullable()->after('shop_photo');
            }
            if (!Schema::hasColumn('user', 'bussiness_pan_card')) {
                $table->string('bussiness_pan_card', 255)->nullable()->after('shop_licence');
            }
            if (!Schema::hasColumn('user', 'is_approved')) {
                $table->enum('is_approved', ['YES', 'NO', 'REQUESTED'])->default('REQUESTED')->after('bussiness_pan_card');
            }
            if (!Schema::hasColumn('user', 'session_id')) {
                $table->text('session_id')->nullable()->after('is_approved');
            }
            if (!Schema::hasColumn('user', 'last_activity')) {
                $table->unsignedInteger('last_activity')->nullable()->after('session_id');
            }
            if (!Schema::hasColumn('user', 'push_notif_id')) {
                $table->text('push_notif_id')->nullable()->after('last_activity');
            }
            if (!Schema::hasColumn('user', 'is_first_login')) {
                $table->unsignedTinyInteger('is_first_login')->default(1)->after('push_notif_id');
            }
            if (!Schema::hasColumn('user', 'has_unread_comments')) {
                $table->unsignedTinyInteger('has_unread_comments')->default(0)->after('is_first_login');
            }
            if (!Schema::hasColumn('user', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('has_unread_comments');
            }
            if (!Schema::hasColumn('user', 'city')) {
                $table->string('city', 100)->nullable()->after('pincode');
            }
            if (!Schema::hasColumn('user', 'state')) {
                $table->string('state', 100)->nullable()->after('city');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            // Drop all added columns if rolling back
            $columns = [
                'is_email_verified', 'contactno', 'is_contact_verified',
                'account_state', 'address', 'latitude', 'longitude', 'dob',
                'register_date', 'shop_name', 'shop_address', 'shop_plot_no',
                'user_type', 'adhar_card', 'shop_photo', 'shop_licence',
                'bussiness_pan_card', 'is_approved', 'session_id', 'last_activity',
                'push_notif_id', 'is_first_login', 'has_unread_comments',
                'pincode', 'city', 'state'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('user', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
