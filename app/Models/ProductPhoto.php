<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPhoto extends Model
{
    protected $table = 'product_photos';
    protected $primaryKey = 'photo_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'photo_id',
        'file_location',
        'photo_slug',
    ];

    // Relationship back to product
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}