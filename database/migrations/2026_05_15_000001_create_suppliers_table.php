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
        if (Schema::hasTable('suppliers')) {
            return;
        }

        Schema::create('suppliers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('supplier_code', 50)->unique();
            $table->string('supplier_name', 255);
            $table->string('short_name', 255)->nullable();
            $table->string('business_type', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('gst_no', 20)->nullable()->unique();
            $table->string('pan_no', 20)->nullable()->unique();
            $table->string('tan_no', 20)->nullable()->unique();
            $table->string('cin_no', 30)->nullable();
            $table->string('vat_no', 30)->nullable();
            $table->string('registration_no', 50)->nullable();
            $table->string('fssai_no', 50)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 30);
            $table->string('alternate_phone', 30)->nullable();
            $table->string('contact_person', 255)->nullable();
            $table->string('contact_person_email', 255)->nullable();
            $table->string('contact_person_phone', 30)->nullable();
            $table->string('contact_person_designation', 100)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('pincode', 20)->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('bank_branch', 150)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes(); // deleted_at for soft deletes (though you mentioned no delete)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
