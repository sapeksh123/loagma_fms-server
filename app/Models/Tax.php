<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    protected $table = 'taxes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'id',
        'tax_category',
        'tax_sub_category',
        'tax_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
