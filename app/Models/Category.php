<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'categories';
    protected $primaryKey = 'cat_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'cat_id',
        'name',
        'parent_cat_id',
        'is_active',
        'type',
        'image_slug',
        'image_name',
        'img_last_updated',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_cat_id', 'cat_id');
    }

    public function subcategories()
    {
        return $this->hasMany(Category::class, 'parent_cat_id', 'cat_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'cat_id', 'cat_id');
    }
}
