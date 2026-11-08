<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Course;
use App\Models\Opportunity;
use App\Models\Student;
use App\Models\University;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $student = $this->student($request);
        $applications = $student->applications();

        return response()->json([
            'data' => [
                'user' => $request->user()->only(['id', 'name', 'email']),
                'profile' => $this->profileData($student),
                'statistics' => [
                    'total_applications' => (clone $applications)->count(),
                    'pending_applications' => (clone $applications)->whereIn('status', [
                        ApplicationStatus::Pending->value,
                        ApplicationStatus::Submitted->value,
                    ])->count(),
                    'approved_applications' => (clone $applications)->whereIn('status', [
                        ApplicationStatus::Accepted->value,
                        ApplicationStatus::Onboarding->value,
                    ])->count(),
                    'rejected_applications' => (clone $applications)->where('status', ApplicationStatus::Rejected->value)->count(),
                    'available_opportunities' => Opportunity::query()->available()->count(),
                    'profile_completion' => $this->profileCompletion($request->user(), $student),
                ],
                'recent_applications' => ApplicationResource::collection(
                    $applications->with('opportunity.organization', 'events')
                        ->latest()
                        ->limit(5)
                        ->get()
                ),
            ],
        ]);
    }

    public function profile(Request $request)
    {
        $student = $this->student($request);

        return response()->json([
            'data' => $this->profileData($student),
            'universities' => University::query()->orderBy('name')->get(['id', 'name']),
            'courses' => Course::query()->orderBy('name')->get(['id', 'university_id', 'name']),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $student = $this->student($request);
        $user = $request->user();
        $currentYear = (int) now()->format('Y');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:17'],
            'bio' => ['nullable', 'string', 'max:3000'],
            'location' => ['nullable', 'string', 'max:255'],
            'university_id' => ['nullable', 'integer', 'exists:universities,id'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'registration_number' => ['nullable', 'string', 'max:64', Rule::unique('students', 'registration_number')->ignore($student->id)],
            'year_of_study' => ['nullable', 'integer', 'between:1,12'],
            'graduation_year' => ['nullable', 'integer', 'between:'.$currentYear.','.($currentYear + 10)],
        ]);

        if (! empty($data['course_id']) && ! empty($data['university_id'])) {
            $courseMatchesUniversity = Course::query()
                ->whereKey($data['course_id'])
                ->where('university_id', $data['university_id'])
                ->exists();
            if (! $courseMatchesUniversity) {
                return response()->json([
                    'message' => 'The selected course does not belong to the selected university.',
                    'errors' => ['course_id' => ['Choose a course offered by the selected university.']],
                ], 422);
            }
        } elseif (! empty($data['course_id'])) {
            $courseMatchesUniversity = Course::query()
                ->whereKey($data['course_id'])
                ->where('university_id', $student->university_id)
                ->exists();
            if (! $courseMatchesUniversity) {
                return response()->json([
                    'message' => 'Select the university that offers this course.',
                    'errors' => ['university_id' => ['This field is required for the selected course.']],
                ], 422);
            }
        }

        DB::transaction(function () use ($user, $student, $data): void {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            $student->update(collect($data)->except(['name', 'email'])->all());
        });

        return response()->json([
            'message' => 'Student profile updated.',
            'data' => $this->profileData($student->fresh(['university', 'course'])),
        ]);
    }

    private function student(Request $request): Student
    {
        abort_unless($request->user()->role === 'student', 403, 'A student account is required.');

        return $request->user()->student()
            ->with(['university:id,name', 'course:id,name,university_id'])
            ->firstOrFail();
    }

    private function profileData(Student $student): array
    {
        $student->loadMissing(['university:id,name', 'course:id,name,university_id', 'user:id,name,email']);

        return [
            'id' => $student->id,
            'name' => $student->user->name,
            'email' => $student->user->email,
            'phone' => $student->phone,
            'bio' => $student->bio,
            'location' => $student->location,
            'registration_number' => $student->registration_number,
            'year_of_study' => $student->year_of_study,
            'graduation_year' => $student->graduation_year,
            'university' => $student->university ? [
                'id' => $student->university->id,
                'name' => $student->university->name,
            ] : null,
            'course' => $student->course ? [
                'id' => $student->course->id,
                'name' => $student->course->name,
            ] : null,
        ];
    }

    private function profileCompletion(User $user, Student $student): int
    {
        $values = [
            $user->name,
            $user->email,
            $student->phone,
            $student->university_id,
            $student->course_id,
            $student->location,
            $student->bio,
        ];
        $completed = count(array_filter($values, fn ($value) => filled($value)));

        return (int) round($completed / count($values) * 100);
    }
}
