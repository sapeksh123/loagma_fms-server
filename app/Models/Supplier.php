<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'supplier_code',
        'supplier_name',
        'short_name',
        'business_type',
        'department',
        'gst_no',
        'pan_no',
        'tan_no',
        'cin_no',
        'vat_no',
        'registration_no',
        'fssai_no',
        'website',
        'email',
        'phone',
        'alternate_phone',
        'contact_person',
        'contact_person_email',
        'contact_person_phone',
        'contact_person_designation',
        'address_line1',
        'city',
        'state',
        'country',
        'pincode',
        'bank_name',
        'bank_branch',
        'bank_account_name',
        'bank_account_number',
        'ifsc_code',
        'swift_code',
        'payment_terms_days',
        'credit_limit',
        'rating',
        'is_preferred',
        'status',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_preferred' => 'boolean',
        'payment_terms_days' => 'integer',
        'credit_limit' => 'decimal:2',
        'rating' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}