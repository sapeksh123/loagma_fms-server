<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PincodeMaster extends Model
{
    use HasFactory;

    protected $table = 'pincode_masters';

    protected $fillable = [
        'pincode',
        'city',
        'state',
        'country',
        'district',
        'region',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}