<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpportunityController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'locations' => ['sometimes', 'array', 'max:10'],
            'locations.*' => ['string', 'max:120'],
            'industries' => ['sometimes', 'array', 'max:10'],
            'industries.*' => ['string', 'max:120'],
            'role_types' => ['sometimes', 'array', 'max:10'],
            'role_types.*' => ['string', 'max:120'],
            'duration_months' => ['nullable', 'integer', 'between:1,12'],
            'sort' => ['nullable', Rule::in(['newest', 'deadline', 'stipend'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $opportunities = Opportunity::query()
            ->available()
            ->with('organization')
            ->withCount('applications')
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $like = "%{$search}%";
                    $query->where('title', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('requirements', 'like', $like)
                        ->orWhere('industry', 'like', $like)
                        ->orWhereHas('organization', function (Builder $organizationQuery) use ($like): void {
                            $organizationQuery->where('name', 'like', $like);
                        });
                });
            })
            ->when($filters['locations'] ?? [], function (Builder $query, array $locations): void {
                $query->where(function (Builder $query) use ($locations): void {
                    $query->whereIn('location', $locations)
                        ->orWhereIn('city', $locations);
                });
            })
            ->when($filters['industries'] ?? [], fn (Builder $query, array $industries) => $query->whereIn('industry', $industries))
            ->when($filters['role_types'] ?? [], fn (Builder $query, array $roleTypes) => $query->whereIn('role_type', $roleTypes))
            ->when($filters['duration_months'] ?? null, fn (Builder $query, int $duration) => $query->where('duration_months', $duration));

        match ($filters['sort'] ?? 'newest') {
            'deadline' => $opportunities->orderByRaw('deadline IS NULL')->orderBy('deadline'),
            'stipend' => $opportunities->orderByDesc('stipend_amount')->orderByDesc('created_at'),
            default => $opportunities->latest(),
        };

        return OpportunityResource::collection(
            $opportunities->paginate($filters['per_page'] ?? 12)->withQueryString()
        );
    }

    public function show(Request $request, int $opportunity)
    {
        $availableOpportunity = Opportunity::query()
            ->available()
            ->with('organization')
            ->withCount('applications')
            ->findOrFail($opportunity);

        $studentId = $request->user()?->student?->id;
        if ($studentId !== null) {
            $availableOpportunity->setAttribute(
                'already_applied',
                $availableOpportunity->applications()->where('student_id', $studentId)->exists()
            );
        }

        return new OpportunityResource($availableOpportunity);
    }
}
