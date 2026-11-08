<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Screening = 'screening';
    case Interview = 'interview';
    case Accepted = 'accepted';
    case Onboarding = 'onboarding';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function stageIndex(): int
    {
        return match ($this) {
            self::Pending,
            self::Submitted => 0,
            self::Screening => 1,
            self::Interview,
            self::Accepted => 2,
            self::Onboarding => 3,
            default => 1, // rejected or withdrwan freezes the timeline.
        };
    }

    public function advisorTip(): string
    {
        return match ($this) {
            self::Pending,
            self::Submitted => 'Make sure your profile and portifolio are up to date while the employer reviews your application.',
            self::Screening => 'Review the job desciption again and prepare to talk about your relevant projects.',
            self::Interview => 'Practice common technical and behavioural questions, and research the company before your interview.',
            self::Accepted => 'Since your application hahs been accepted, prepare your university introduction letter and logbook guidlines ahead of day one.',
            self::Onboarding => 'Complete your agreements and clearance letters early so you can start on time.',
            self::Rejected => 'Don\'t be discouraged. Ask for feedbackand keep applying to similar opportunities.',
            self::Withdrawn => 'You withdrew this application. Browse other opportunities that match your skills.',
        };
    }
}
