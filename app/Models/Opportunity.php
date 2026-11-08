<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

class Opportunity extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'role_type',
        'location',
        'stipend_amount',
        'stipend_currency',
        'stipend_period',
        'description',
        'requirements',
        'responsibilities',
        'industry',
        'duration_months',
        'available_slots',
        'city',
        'region',
        'start_date',
        'end_date',
        'status',
        'deadline',
        'is_active',

    ];

    #[Override]
    public function casts()
    {
        return [
            'deadline' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'stipend_amount' => 'decimal:2',
            'duration_months' => 'integer',
            'available_slots' => 'integer',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', 'published')
            ->where(function (Builder $query): void {
                $query->whereNull('deadline')
                    ->orWhere('deadline', '>=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('available_slots')
                    ->orWhereRaw(
                        '(SELECT COUNT(*) FROM applications WHERE applications.opportunity_id = opportunities.id) < opportunities.available_slots'
                    );
            });
    }

    public function isAcceptingApplications(): bool
    {
        $applicationCount = $this->getAttribute('applications_count')
            ?? $this->applications()->count();

        return $this->is_active
        && $this->status === 'published'
        && ($this->deadline === null || $this->deadline->isFuture())
            && ($this->available_slots === null || $applicationCount < $this->available_slots);
    }
}
