<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherPdcDetail extends Model
{
    protected $table = 'voucher_pdc_details';

    protected $fillable = [
        'voucher_id',
        'cheque_no',
        'cheque_date',
        'bank_name',
        'status',
        'cleared_date',
        'bounced_date',
    ];

    protected $casts = [
        'voucher_id' => 'integer',
        'cheque_date' => 'date:Y-m-d',
        'cleared_date' => 'date:Y-m-d',
        'bounced_date' => 'date:Y-m-d',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
