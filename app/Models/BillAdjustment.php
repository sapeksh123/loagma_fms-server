<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillAdjustment extends Model
{
    protected $table = 'bill_adjustments';

    public $timestamps = false;

    protected $fillable = [
        'voucher_detail_id',
        'invoice_type',
        'invoice_id',
        'adjustment_type',
        'adjusted_amount',
        'discount_amount',
    ];

    protected $casts = [
        'voucher_detail_id' => 'integer',
        'invoice_id' => 'integer',
        'adjusted_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(VoucherDetail::class, 'voucher_detail_id');
    }
}
