<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OpportunityController extends Controller
{
    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'closed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $opportunities = $organization->opportunities()
            ->withCount('applications')
            ->when($filters['q'] ?? null, fn (Builder $query, string $search) => $query->where('title', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return OpportunityResource::collection($opportunities);
    }

    public function store(Request $request)
    {
        $organization = $this->organization($request);
        $data = $this->validatedOpportunity($request);
        $opportunity = $organization->opportunities()->create($this->attributes($data));

        return (new OpportunityResource($opportunity->load('organization')->loadCount('applications')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $opportunity)
    {
        $record = $this->organization($request)->opportunities()
            ->with('organization')
            ->withCount('applications')
            ->findOrFail($opportunity);

        return new OpportunityResource($record);
    }

    public function update(Request $request, int $opportunity)
    {
        $organization = $this->organization($request);
        $record = $organization->opportunities()->findOrFail($opportunity);
        $data = $this->validatedOpportunity($request, $record);
        $record->update($this->attributes($data, $record));

        return new OpportunityResource($record->fresh()->load('organization')->loadCount('applications'));
    }

    public function updateStatus(Request $request, int $opportunity)
    {
        $record = $this->organization($request)->opportunities()->findOrFail($opportunity);
        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'published', 'closed'])],
        ]);

        if ($data['status'] === 'published' && $record->deadline !== null && $record->deadline->isPast()) {
            return response()->json([
                'message' => 'An expired opportunity cannot be published.',
                'errors' => ['status' => ['Set a future application deadline before publishing.']],
            ], 422);
        }

        $record->update([
            'status' => $data['status'],
            'is_active' => $data['status'] === 'published',
        ]);

        return new OpportunityResource($record->fresh()->load('organization')->loadCount('applications'));
    }

    public function destroy(Request $request, int $opportunity)
    {
        $record = $this->organization($request)->opportunities()->findOrFail($opportunity);

        if ($record->applications()->exists()) {
            return response()->json([
                'message' => 'This opportunity has applications and cannot be deleted. Close it instead.',
            ], 409);
        }

        $record->delete();

        return response()->json(['message' => 'Opportunity deleted.']);
    }

    private function validatedOpportunity(Request $request, ?Opportunity $opportunity = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'role_type' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'responsibilities' => ['nullable', 'string', 'max:5000'],
            'industry' => ['nullable', 'string', 'max:120'],
            'duration_months' => ['nullable', 'integer', 'between:1,12'],
            'available_slots' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'stipend_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'stipend_currency' => ['required', 'string', 'size:3'],
            'stipend_period' => ['required', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'closed'])],
        ]);

        $status = $data['status'] ?? $opportunity?->status ?? 'draft';
        if ($status === 'published' && isset($data['deadline']) && now()->parse($data['deadline'])->isPast()) {
            throw ValidationException::withMessages([
                'deadline' => ['Choose a future application deadline before publishing.'],
            ]);
        }

        return $data;
    }

    private function attributes(array $data, ?Opportunity $opportunity = null): array
    {
        $status = $data['status'] ?? $opportunity?->status ?? 'draft';

        return array_merge($data, [
            'status' => $status,
            'is_active' => $status === 'published',
        ]);
    }

    private function organization(Request $request): Organization
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($user->role, ['employer', 'organization'], true), 403, 'An organization account is required.');

        return $user->organization()->firstOrFail();
    }
}
