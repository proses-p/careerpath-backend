<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrganizationDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $organization = $this->organization($request);
        $opportunities = $organization->opportunities();
        $applications = Application::query()->whereHas('opportunity', fn ($query) => $query->where('organization_id', $organization->id));

        return response()->json([
            'data' => [
                'organization' => $this->profileData($organization),
                'statistics' => [
                    'total_opportunities' => (clone $opportunities)->count(),
                    'published_opportunities' => (clone $opportunities)->where('status', 'published')->count(),
                    'draft_opportunities' => (clone $opportunities)->where('status', 'draft')->count(),
                    'closed_opportunities' => (clone $opportunities)->where('status', 'closed')->count(),
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
                ],
                'opportunities' => (clone $opportunities)
                    ->withCount('applications')
                    ->latest()
                    ->limit(5)
                    ->get(),
                'recent_applications' => ApplicationResource::collection(
                    (clone $applications)->with([
                        'opportunity.organization',
                        'student.user',
                        'student.university',
                        'student.course',
                        'events',
                    ])->latest()->limit(8)->get()
                ),
            ],
        ]);
    }

    public function profile(Request $request)
    {
        return response()->json(['data' => $this->profileData($this->organization($request))]);
    }

    public function updateProfile(Request $request)
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        DB::transaction(function () use ($request, $organization, $data): void {
            $organization->fill(collect($data)->except('logo')->all());

            if ($request->hasFile('logo')) {
                $newPath = $request->file('logo')->store('organization-logos', 'public');
                $oldPath = $organization->logo_path;
                $organization->logo_path = $newPath;
                $organization->save();
                if ($oldPath) {
                    Storage::disk('public')->delete($oldPath);
                }

                return;
            }

            $organization->save();
        });

        return response()->json([
            'message' => 'Organization profile updated.',
            'data' => $this->profileData($organization->fresh()),
        ]);
    }

    private function organization(Request $request): Organization
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($user->role, ['employer', 'organization'], true), 403, 'An organization account is required.');

        return $user->organization()->firstOrFail();
    }

    private function profileData(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'location' => $organization->location,
            'logo_path' => $organization->logo_path,
            'logo_url' => $organization->logo_path ? Storage::disk('public')->url($organization->logo_path) : null,
            'email' => $organization->email,
            'phone' => $organization->phone,
            'website' => $organization->website,
            'description' => $organization->description,
            'address' => $organization->address,
            'city' => $organization->city,
            'region' => $organization->region,
        ];
    }
}
