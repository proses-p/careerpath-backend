<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;

// use Override;

class Application extends Model
{
    protected $fillable = [
        'student_id',
        'opportunity_id',
        'status',
        'cover_letter',
        'offer_letter_path',
    ];

    protected $casts = [
        'status' => ApplicationStatus::class,
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function events()
    {
        return $this->hasMany(ApplicationEvent::class)->oldest('created_at');
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->oldest();
    }
}
