<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitMaster extends Model
{
    protected $table = 'units_master';
    protected $primaryKey = 'unit_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'unit_id',
        'unit_name',
        'serial_no',
        'conversion_rate',
        'created_at',
    ];

    protected $casts = [
        'serial_no' => 'integer',
        'conversion_rate' => 'decimal:4',
        'created_at' => 'datetime',
    ];
}
