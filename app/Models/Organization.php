<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'location',
        'logo_path',
        'email',
        'phone',
        'website',
        'description',
        'address',
        'city',
        'region',
    ];

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
