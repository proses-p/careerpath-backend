<?php

namespace App\Http\Controllers\Employer;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $filters = $request->validate([
            'opportunity_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        if (isset($filters['opportunity_id']) && ! $organization->opportunities()->whereKey($filters['opportunity_id'])->exists()) {
            abort(404);
        }

        $applications = Application::query()
            ->whereHas('opportunity', fn (Builder $query) => $query->where('organization_id', $organization->id))
            ->with([
                'opportunity.organization',
                'student.user',
                'student.university',
                'student.course',
                'events',
            ])
            ->when($filters['opportunity_id'] ?? null, fn (Builder $query, int $id) => $query->where('opportunity_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $like = "%{$search}%";
                $query->where(function (Builder $query) use ($like): void {
                    $query->whereHas('student.user', function (Builder $studentQuery) use ($like): void {
                        $studentQuery->where('name', 'like', $like)->orWhere('email', 'like', $like);
                    })->orWhereHas('opportunity', function (Builder $opportunityQuery) use ($like): void {
                        $opportunityQuery->where('title', 'like', $like);
                    });
                });
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return ApplicationResource::collection($applications);
    }

    public function show(Request $request, Application $application)
    {
        $this->authorize('view', $application);
        abort_unless($this->organization($request)->opportunities()->whereKey($application->opportunity_id)->exists(), 404);

        return new ApplicationResource($application->load([
            'opportunity.organization',
            'student.user',
            'student.university',
            'student.course',
            'events',
        ]));
    }

    public function updateStatus(Request $request, Application $application)
    {
        $this->authorize('manage', $application);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                ApplicationStatus::Screening->value,
                ApplicationStatus::Interview->value,
                ApplicationStatus::Accepted->value,
                ApplicationStatus::Rejected->value,
                ApplicationStatus::Onboarding->value,
            ])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $nextStatus = ApplicationStatus::from($data['status']);
        $allowedTransitions = match ($application->status) {
            ApplicationStatus::Pending, ApplicationStatus::Submitted => [
                ApplicationStatus::Screening,
                ApplicationStatus::Interview,
                ApplicationStatus::Accepted,
                ApplicationStatus::Rejected,
            ],
            ApplicationStatus::Screening => [
                ApplicationStatus::Interview,
                ApplicationStatus::Accepted,
                ApplicationStatus::Rejected,
            ],
            ApplicationStatus::Interview => [
                ApplicationStatus::Accepted,
                ApplicationStatus::Rejected,
            ],
            ApplicationStatus::Accepted => [ApplicationStatus::Onboarding],
            default => [],
        };

        if (! in_array($nextStatus, $allowedTransitions, true)) {
            throw ValidationException::withMessages([
                'status' => ['This application cannot transition from '.$application->status->label().' to '.$nextStatus->label().'.'],
            ]);
        }

        $application->update(['status' => $nextStatus]);
        $application->events()->create([
            'status' => $nextStatus,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return new ApplicationResource($application->load([
            'opportunity.organization',
            'student.user',
            'student.university',
            'student.course',
            'events',
        ]));
    }

    public function uploadOfferLetter(Request $request, Application $application)
    {
        $this->authorize('manage', $application);

        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:5120']]);

        $path = $request->file('file')->store("offer-letters/{$application->id}", 'private');
        $application->update(['offer_letter_path' => $path]);

        return response()->json(['message' => 'Offer letter uploaded.']);
    }

    private function organization(Request $request): Organization
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($user->role, ['employer', 'organization'], true), 403, 'An organization account is required.');

        return $user->organization()->firstOrFail();
    }
}
