<?php

namespace App\Http\Controllers\Student;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Opportunity;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    private function with(): array
    {
        return [
            'opportunity.organization',
            'events',
        ];
    }

    public function index(Request $request)
    {
        $studentId = $this->studentId($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $apps = Application::query()
            ->where('student_id', $studentId)
            ->with($this->with())
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function ($query, string $search): void {
                $like = "%{$search}%";
                $query->whereHas('opportunity', function ($opportunityQuery) use ($like): void {
                    $opportunityQuery->where('title', 'like', $like)
                        ->orWhereHas('organization', fn ($organizationQuery) => $organizationQuery->where('name', 'like', $like));
                });
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return ApplicationResource::collection($apps);
    }

    public function show(Request $request, Application $application)
    {
        $this->assertApplicationOwner($request, $application);

        return new ApplicationResource($application->load($this->with()));
    }

    public function store(Request $request)
    {
        $studentId = $this->studentId($request);
        $userId = $request->user()->id;

        $data = $request->validate([
            'opportunity_id' => ['required', 'integer', 'exists:opportunities,id'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $application = DB::transaction(function () use ($studentId, $userId, $data): Application {
                $opportunity = Opportunity::query()
                    ->withCount('applications')
                    ->lockForUpdate()
                    ->findOrFail($data['opportunity_id']);

                if (! $opportunity->isAcceptingApplications()) {
                    throw ValidationException::withMessages([
                        'opportunity_id' => ['This opportunity is no longer accepting applications.'],
                    ]);
                }

                if (Application::query()
                    ->where('student_id', $studentId)
                    ->where('opportunity_id', $opportunity->id)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'opportunity_id' => ['You have already applied for this opportunity.'],
                    ]);
                }

                $application = Application::create([
                    'student_id' => $studentId,
                    'opportunity_id' => $opportunity->id,
                    'cover_letter' => $data['cover_letter'] ?? null,
                    'status' => ApplicationStatus::Pending,
                ]);

                $application->events()->create([
                    'status' => ApplicationStatus::Pending,
                    'created_by' => $userId,
                ]);

                return $application;
            });
        } catch (QueryException $exception) {
            $duplicateExists = Application::query()
                ->where('student_id', $studentId)
                ->where('opportunity_id', $data['opportunity_id'])
                ->exists();

            if ($exception->getCode() !== '23000' || ! $duplicateExists) {
                throw $exception;
            }

            return response()->json([
                'message' => 'You have already applied for this opportunity.',
            ], 409);
        }

        return (new ApplicationResource($application->load($this->with())))
            ->response()
            ->setStatusCode(201);
    }

    public function downloadOfferLetter(Request $request, Application $application)
    {
        $this->assertApplicationOwner($request, $application);
        abort_unless($application->offer_letter_path, 404, 'Offer letter not available yet.');

        return Storage::disk('private')->download(
            $application->offer_letter_path,
            "Offer-letter-{$application->id}.pdf"
        );
    }

    private function assertApplicationOwner(Request $request, Application $application): void
    {
        abort_unless(
            $application->student_id === $this->studentId($request),
            403
        );
    }

    private function studentId(Request $request): int
    {
        abort_unless($request->user()->role === 'student', 403);

        $studentId = $request->user()->student?->id;
        abort_unless($studentId !== null, 403, 'A student profile is required to apply.');

        return $studentId;
    }
}
