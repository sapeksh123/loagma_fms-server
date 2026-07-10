<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VoucherDetail extends Model
{
    protected $table = 'voucher_details';

    public $timestamps = false;

    protected $fillable = [
        'voucher_id',
        'account_category',
        'account_id',
        'amount',
        'narration',
    ];

    protected $casts = [
        'voucher_id' => 'integer',
        'account_id' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function billAdjustments(): HasMany
    {
        return $this->hasMany(BillAdjustment::class, 'voucher_detail_id');
    }

    public function advance(): HasOne
    {
        return $this->hasOne(PartyAdvance::class, 'voucher_detail_id');
    }
}
