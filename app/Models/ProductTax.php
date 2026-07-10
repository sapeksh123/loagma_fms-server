<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTax extends Model
{
    protected $table = 'product_taxes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'product_id',
        'tax_id',
        'tax_percent',
    ];

    protected $casts = [
        'tax_percent' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class, 'tax_id', 'id');
    }
}
