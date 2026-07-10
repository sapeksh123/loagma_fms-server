<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasFactory;

    protected $table = 'notes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'id',
        'user_id',
        'folder_name',
        'title',
        'content',
    ];

    protected $casts = [
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
