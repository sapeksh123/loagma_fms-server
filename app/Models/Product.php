<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'product'; // Fixed: actual table name is singular
    protected $primaryKey = 'product_id';
    public $incrementing = false;
    public $timestamps = false; // Set to true if you have created_at/updated_at columns

    protected $fillable = [
        'product_id',
        'cat_id',
        'parent_cat_id',
        'brand',
        'ctype_id',
        'seq_no',
        'start_date',
        'is_published',
        'is_used',
        'is_deleted',
        'in_stock',
        'inventory_type',
        'inventory_unit_type',
        'name',
        'description',
        'display_photo',
        'keywords',
        'spec_params',
        'packs',
        'default_pack_id',
        'hsn_code',
        'gst_percent',
        'offers',
        'cache_txt',
        'img_last_updated',
        'stock',
        'stock_ut_id',
        'order_limit',
        'buffer_limit',
    ];

    protected $casts = [
        'spec_params' => 'array',
        'packs' => 'array',
        'offers' => 'array',
        'start_date' => 'datetime',
        'img_last_updated' => 'datetime',
    ];

    // Custom accessors to handle corrupted JSON
    public function getPacksAttribute($value)
    {
        if (empty($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Exception $e) {
            \Log::warning("Corrupted packs JSON for product {$this->product_id}: {$e->getMessage()}");
            return [];
        }
    }

    public function getSpecParamsAttribute($value)
    {
        if (empty($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Exception $e) {
            \Log::warning("Corrupted spec_params JSON for product {$this->product_id}: {$e->getMessage()}");
            return [];
        }
    }

    public function getOffersAttribute($value)
    {
        if (empty($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Exception $e) {
            \Log::warning("Corrupted offers JSON for product {$this->product_id}: {$e->getMessage()}");
            return [];
        }
    }

    // Sanitize text fields
    public function getNameAttribute($value)
    {
        return $this->sanitizeText($value);
    }

    public function getDescriptionAttribute($value)
    {
        return $this->sanitizeText($value);
    }

    public function getKeywordsAttribute($value)
    {
        return $this->sanitizeText($value);
    }

    private function sanitizeText($text)
    {
        if (empty($text)) {
            return $text;
        }

        // Remove null bytes and control characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = str_replace("\0", '', $text);

        return $text;
    }

    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class, 'cat_id', 'cat_id');
    }

    public function parentCategory()
    {
        return $this->belongsTo(Category::class, 'parent_cat_id', 'cat_id');
    }

    public function hsnCode()
    {
        return $this->belongsTo(HsnCode::class, 'hsn_code', 'hsn_code');
    }

    public function photos()
    {
        return $this->hasMany(ProductPhoto::class, 'product_id', 'product_id');
    }

    public function productTaxes()
    {
        return $this->hasMany(ProductTax::class, 'product_id', 'product_id');
    }

    public function taxes()
    {
        return $this->belongsToMany(Tax::class, 'product_taxes', 'product_id', 'tax_id')
            ->withPivot('tax_percent');
    }
}
