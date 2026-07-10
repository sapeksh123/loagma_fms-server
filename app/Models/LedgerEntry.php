<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $table = 'ledger_entries';

    public $timestamps = false;

    protected $fillable = [
        'voucher_id',
        'ledger_source',
        'ledger_id',
        'dr_amount',
        'cr_amount',
        'entry_date',
    ];

    protected $casts = [
        'voucher_id' => 'integer',
        'ledger_id' => 'integer',
        'dr_amount' => 'decimal:2',
        'cr_amount' => 'decimal:2',
        'entry_date' => 'date:Y-m-d',
    ];
}
