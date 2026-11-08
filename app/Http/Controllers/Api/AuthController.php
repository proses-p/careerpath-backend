<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function registerStudent(Request $request)
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()->symbols()],
            'university_id' => ['required', 'integer', 'exists:universities,id'],
            'course_id' => [
                'required',
                'integer',
                Rule::exists('courses', 'id')->where(fn ($query) => $query->where('university_id', $request->input('university_id'))),
            ],
            'registration_number' => ['required', 'string', 'max:64', 'unique:students,registration_number'],
            'year_of_study' => ['required', 'integer', 'between:1,12'],
            'graduation_year' => ['nullable', 'integer', 'between:'.now()->year.','.(now()->year + 10)],
            'phone' => ['required', 'string', 'max:17'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => Hash::make($validated['password']),
                'role' => 'student',
                'is_active' => true,
            ]);

            $student = Student::query()->create([
                'user_id' => $user->id,
                'university_id' => $validated['university_id'],
                'course_id' => $validated['course_id'],
                'registration_number' => $validated['registration_number'],
                'year_of_study' => $validated['year_of_study'],
                'graduation_year' => $validated['graduation_year'] ?? null,
                'phone' => $validated['phone'],
            ]);

            return [
                'user' => $user->load(['student.university', 'student.course']),
                'token' => $user->createToken('careerbridge-auth')->plainTextToken,
            ];
        });

        return response()->json([
            'message' => 'Student account created.',
            'data' => [
                'user' => $this->userData($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function registerOrganization(Request $request)
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()->symbols()],
            'phone' => ['required', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['required_without:address', 'nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => $validated['organization_name'],
                'email' => strtolower($validated['email']),
                'password' => Hash::make($validated['password']),
                'role' => 'employer',
                'is_active' => true,
            ]);

            $locationParts = array_filter([
                $validated['city'] ?? null,
                $validated['region'] ?? null,
            ]);
            $organization = Organization::query()->create([
                'user_id' => $user->id,
                'name' => $validated['organization_name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'],
                'website' => $validated['website'] ?? null,
                'description' => $validated['description'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'region' => $validated['region'] ?? null,
                'location' => implode(', ', $locationParts) ?: $validated['address'],
            ]);

            return [
                'user' => $user->setRelation('organization', $organization),
                'token' => $user->createToken('careerbridge-auth')->plainTextToken,
            ];
        });

        return response()->json([
            'message' => 'Organization account created. Verification may be required before publishing opportunities.',
            'data' => [
                'user' => $this->userData($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $this->normalizeEmail($request);
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with(['student.university', 'student.course', 'organization'])
            ->where('email', strtolower($credentials['email']))
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is inactive. Contact support for assistance.'], 403);
        }

        if (
            ($user->role === 'student' && ! $user->student)
            || (in_array($user->role, ['employer', 'organization'], true) && ! $user->organization)
            || ! in_array($user->role, ['student', 'employer', 'organization', 'admin'], true)
        ) {
            return response()->json(['message' => 'This account is not configured for a CareerBridge portal.'], 403);
        }

        $token = $user->createToken('careerbridge-auth')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => $this->userData($user),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['student.university', 'student.course', 'organization']);

        return response()->json(['data' => ['user' => $this->userData($user)]]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logout successful.']);
    }

    private function userData(User $user): array
    {
        $profile = match ($user->role) {
            'student' => $user->student ? [
                'id' => $user->student->id,
                'university' => $user->student->university?->only(['id', 'name']),
                'course' => $user->student->course?->only(['id', 'name']),
                'registration_number' => $user->student->registration_number,
            ] : null,
            'employer', 'organization' => $user->organization?->only([
                'id', 'name', 'email', 'phone', 'website', 'address', 'city', 'region',
            ]),
            default => null,
        };

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'profile' => $profile,
        ];
    }

    private function normalizeEmail(Request $request): void
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => strtolower(trim($email))]);
        }
    }
}
