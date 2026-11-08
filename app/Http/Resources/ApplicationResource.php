<?php

namespace App\Http\Resources;

use App\Services\TimelineBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $opp = $this->opportunity;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'applied_at' => $this->created_at->toIso8601String(),
            'cover_letter' => $this->cover_letter,
            'opportunity' => [
                'id' => $opp->id,
                'title' => $opp->title,
                'role_type' => $opp->role_type,
                'location' => $opp->location,
                'organization' => [
                    'id' => $opp->organization->id,
                    'name' => $opp->organization->name,
                    'location' => $opp->organization->location,
                    'logo_path' => $opp->organization->logo_path,
                ],
                'stipend' => $opp->stipend_amount ? [
                    'amount' => (float) $opp->stipend_amount,
                    'currency' => $opp->stipend_currency,
                    'period' => $opp->stipend_period,
                    'formatted' => sprintf('%s%s / %s',
                        $opp->stipend_currency === 'USD' ? '$' : $opp->stipend_currency.' ',
                        number_format($opp->stipend_amount),
                        $opp->stipend_period),
                ] : null,
            ],
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->user->name,
                'email' => $this->student->user->email,
                'phone' => $this->student->phone,
                'location' => $this->student->location,
                'registration_number' => $this->student->registration_number,
                'year_of_study' => $this->student->year_of_study,
                'graduation_year' => $this->student->graduation_year,
                'university' => $this->student->university?->name,
                'course' => $this->student->course?->name,
            ]),
            'review_feedback' => $this->whenLoaded('events', fn () => $this->events->last()?->note),
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
                'note' => $event->note,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()),
            'timeline' => app(TimelineBuilder::class)->build($this->resource),
            'offer_letter_available' => (bool) $this->offer_letter_path,
            'advisor_tip' => $this->status->advisorTip(),
        ];
    }
}
