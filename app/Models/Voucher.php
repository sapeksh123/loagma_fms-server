<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Voucher extends Model
{
    protected $table = 'vouchers';

    protected $fillable = [
        'voucher_type',
        'voucher_no',
        'fy',
        'seq',
        'voucher_date',
        'cash_bank_account_id',
        'total_amount',
        'narration',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'voucher_date' => 'date:Y-m-d',
        'seq' => 'integer',
        'cash_bank_account_id' => 'integer',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(VoucherDetail::class, 'voucher_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'voucher_id');
    }

    public function cashBankAccount()
    {
        return $this->belongsTo(GeneralAccount::class, 'cash_bank_account_id');
    }

    /** Cheque metadata, only present for PDC/PDR vouchers. */
    public function pdcDetail(): HasOne
    {
        return $this->hasOne(VoucherPdcDetail::class, 'voucher_id');
    }
}
