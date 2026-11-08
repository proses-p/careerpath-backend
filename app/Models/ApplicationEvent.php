<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;

class ApplicationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'status',
        'note',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'status' => ApplicationStatus::class, 'created_at' => 'datetime',
    ];
}
