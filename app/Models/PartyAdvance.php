<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyAdvance extends Model
{
    protected $table = 'party_advances';

    public $timestamps = false;

    protected $fillable = [
        'party_type',
        'party_id',
        'voucher_detail_id',
        'amount',
        'remaining_amount',
    ];

    protected $casts = [
        'party_id' => 'integer',
        'voucher_detail_id' => 'integer',
        'amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(VoucherDetail::class, 'voucher_detail_id');
    }
}
