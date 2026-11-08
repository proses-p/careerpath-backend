<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OpportunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $applicationCount = (int) ($this->applications_count ?? 0);
        $availableSlots = $this->available_slots;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'role_type' => $this->role_type,
            'industry' => $this->industry,
            'status' => $this->status,
            'location' => $this->location,
            'city' => $this->city,
            'region' => $this->region,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'responsibilities' => $this->responsibilities,
            'duration_months' => $this->duration_months,
            'deadline' => $this->deadline?->toIso8601String(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'available_slots' => $availableSlots,
            'remaining_slots' => $availableSlots === null
                ? null
                : max(0, $availableSlots - $applicationCount),
            'is_accepting_applications' => $this->isAcceptingApplications(),
            'already_applied' => (bool) ($this->already_applied ?? false),
            'stipend' => $this->stipend_amount === null ? null : [
                'amount' => (float) $this->stipend_amount,
                'currency' => $this->stipend_currency,
                'period' => $this->stipend_period,
                'formatted' => sprintf(
                    '%s%s / %s',
                    $this->stipend_currency === 'USD' ? '$' : $this->stipend_currency.' ',
                    number_format((float) $this->stipend_amount),
                    $this->stipend_period
                ),
            ],
            'organization' => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'location' => $this->organization->location,
                'city' => $this->organization->city,
                'region' => $this->organization->region,
                'address' => $this->organization->address,
                'logo_path' => $this->organization->logo_path,
                'logo_url' => $this->organization->logo_path
                    ? Storage::disk('public')->url($this->organization->logo_path)
                    : null,
                'website' => $this->organization->website,
                'description' => $this->organization->description,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
