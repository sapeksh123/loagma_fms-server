<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneralAccount extends Model
{
    use HasFactory;

    protected $table = 'general_account';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'account_no',
        'account_name',
        'account_type',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}