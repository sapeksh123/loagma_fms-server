<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'user';
    protected $primaryKey = 'userid';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'userid',
        'name',
        'email',
        'password',
        'is_email_verified',
        'contactno',
        'is_contact_verified',
        'account_state',
        'address',
        'latitude',
        'longitude',
        'dob',
        'register_date',
        'shop_name',
        'shop_address',
        'shop_plot_no',
        'user_type',
        'adhar_card',
        'shop_photo',
        'shop_licence',
        'bussiness_pan_card',
        'is_approved',
        'session_id',
        'last_activity',
        'push_notif_id',
        'is_first_login',
        'has_unread_comments',
        'pincode',
        'city',
        'state',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_email_verified' => 'boolean',
            'is_contact_verified' => 'boolean',
            'is_first_login' => 'boolean',
            'has_unread_comments' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}
