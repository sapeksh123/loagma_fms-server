<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsnCode extends Model
{
    protected $table = 'hsn_codes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = true; // Table has created_at/updated_at columns

    protected $fillable = [
        'id',
        'hsn_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships
    public function products()
    {
        return $this->hasMany(Product::class, 'hsn_code', 'hsn_code');
    }
}
