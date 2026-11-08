<?php

namespace App\Services;

use App\Enums\ApplicationStatus as S;
use App\Models\Application;

class TimelineBuilder
{
    public function build(Application $app): array
    {
        $company = $app->opportunity->organization->name;
        $current = $app->status->stageIndex();

        // Latest event date per stage index
        $dates = [];
        foreach ($app->events as $event) {
            $dates[$event->status->stageIndex()] = $event->created_at;
        }

        $stages = [
            ['key' => 'submitted', 'title' => 'Application Submitted',
                'description' => "Your application was successfully received by the {$company} recruitment team."],
            ['key' => 'screening', 'title' => 'Screening & Review',
                'description' => 'Your academic background, portfolio, and technical skills are being reviewed.'],
            ['key' => 'offer', 'title' => 'Interview / Offer Stage',
                'description' => match ($app->status) {
                    S::Accepted, S::Onboarding => 'Congratulations! You have been accepted for the internship position.',
                    S::Interview => 'You have been shortlisted for an interview. Check your messages for details.',
                    default => 'Waiting for the employer to schedule an interview or send an offer.',
                }],
            ['key' => 'onboarding', 'title' => 'Onboarding & Final Documentation',
                'description' => 'Sign institutional agreements and submit field attachment clearance letters.'],
        ];

        return collect($stages)->map(function ($stage, $i) use ($current, $dates, $app) {
            $closed = in_array($app->status, [S::Rejected, S::Withdrawn]);

            $state = match (true) {
                $i < $current => 'completed',
                $i === $current && ! $closed => 'active',
                $i === $current && $closed => 'completed',
                default => 'pending',
            };

            return $stage + [
                'state' => $state,
                'date' => isset($dates[$i]) ? $dates[$i]->toIso8601String() : null,
            ];
        })->all();
    }
}
