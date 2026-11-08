<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Course;
use App\Models\Organization;
use App\Models\Student;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_dashboard_and_profile_are_authenticated_and_data_driven(): void
    {
        $this->createUser('employer', 'without-student');
        [$studentUser, $student] = $this->createStudent();
        $university = University::query()->create(['name' => 'Test University']);
        $course = Course::query()->create([
            'university_id' => $university->id,
            'name' => 'Computer Science',
        ]);
        $student->update([
            'university_id' => $university->id,
            'course_id' => $course->id,
        ]);
        $organization = $this->createOrganization();
        $opportunity = $organization->opportunities()->create($this->opportunityAttributes([
            'status' => 'published',
            'is_active' => true,
        ]));
        Application::query()->create([
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
            'status' => 'pending',
        ]);

        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_applications', 1)
            ->assertJsonPath('data.statistics.pending_applications', 1)
            ->assertJsonPath('data.statistics.available_opportunities', 1)
            ->assertJsonPath('data.recent_applications.0.opportunity.title', 'Test Opportunity');

        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/v1/student/profile')
            ->assertOk()
            ->assertJsonPath('data.university.name', 'Test University')
            ->assertJsonPath('courses.0.name', 'Computer Science');

        $this->actingAs($studentUser, 'sanctum')
            ->putJson('/api/v1/student/profile', [
                'name' => $studentUser->name,
                'email' => $studentUser->email,
                'phone' => '+255700000000',
                'location' => 'Arusha',
                'university_id' => $university->id,
                'course_id' => $course->id,
                'registration_number' => 'REG-1001',
                'year_of_study' => 3,
                'graduation_year' => (int) now()->format('Y') + 1,
                'bio' => 'Computer science student.',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+255700000000')
            ->assertJsonPath('data.registration_number', 'REG-1001');

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'course_id' => $course->id,
            'registration_number' => 'REG-1001',
        ]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/student/dashboard')->assertUnauthorized();
    }

    public function test_organization_can_manage_only_its_opportunities_and_review_its_applications(): void
    {
        [$owner, $organization] = $this->createOrganizationOwner('owner');
        [, $otherOrganization] = $this->createOrganizationOwner('other');
        [$studentUser, $student] = $this->createStudent();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/employer/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_opportunities', 0);

        $creation = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/employer/opportunities', $this->opportunityAttributes())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $opportunityId = $creation->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/employer/opportunities/{$opportunityId}/status", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.is_accepting_applications', true);

        $this->getJson('/api/v1/opportunities')
            ->assertOk()
            ->assertJsonPath('data.0.id', $opportunityId);

        $otherOpportunity = $otherOrganization->opportunities()->create($this->opportunityAttributes([
            'title' => 'Other Organization Opportunity',
        ]));
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/employer/opportunities/{$otherOpportunity->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/employer/opportunities/{$otherOpportunity->id}", $this->opportunityAttributes([
                'title' => 'Unauthorized edit',
            ]))
            ->assertNotFound();

        $application = Application::query()->create([
            'student_id' => $student->id,
            'opportunity_id' => $opportunityId,
            'status' => 'pending',
            'cover_letter' => 'I am applying for this role.',
        ]);
        $application->events()->create([
            'status' => 'pending',
            'created_by' => $studentUser->id,
        ]);
        $otherApplication = Application::query()->create([
            'student_id' => $student->id,
            'opportunity_id' => $otherOpportunity->id,
            'status' => 'pending',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/employer/applications?q=Student')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $application->id)
            ->assertJsonPath('data.0.student.name', $studentUser->name)
            ->assertJsonPath('data.0.student.course', null)
            ->assertJsonPath('data.0.cover_letter', 'I am applying for this role.');

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/employer/applications/{$otherApplication->id}")
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/employer/opportunities/{$opportunityId}")
            ->assertStatus(409);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/employer/applications/{$application->id}/status", [
                'status' => 'screening',
                'note' => 'Profile is being reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'screening')
            ->assertJsonPath('data.review_feedback', 'Profile is being reviewed.');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/employer/applications/{$application->id}/status", [
                'status' => 'pending',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('application_events', [
            'application_id' => $application->id,
            'status' => 'screening',
            'note' => 'Profile is being reviewed.',
        ]);

        $this->actingAs($studentUser, 'sanctum')
            ->getJson("/api/v1/student/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.review_feedback', 'Profile is being reviewed.')
            ->assertJsonPath('data.history.1.status', 'screening');

        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/v1/student/applications?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $otherApplication->id);
    }

    public function test_organization_profile_updates_are_owner_scoped(): void
    {
        [$owner, $organization] = $this->createOrganizationOwner('profile');
        [$otherOwner, $otherOrganization] = $this->createOrganizationOwner('other-profile');

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/employer/profile', [
                'name' => 'Updated Organization',
                'email' => 'contact@example.test',
                'phone' => '+255700000001',
                'website' => 'https://example.test',
                'description' => 'An organization description.',
                'location' => 'Dar es Salaam',
                'address' => '1 Example Road',
                'city' => 'Dar es Salaam',
                'region' => 'Dar es Salaam',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Organization');

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Updated Organization',
            'email' => 'contact@example.test',
        ]);

        $this->actingAs($otherOwner, 'sanctum')
            ->getJson('/api/v1/employer/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $otherOrganization->id);
    }

    private function createStudent(): array
    {
        $user = $this->createUser('student');
        $student = Student::query()->create(['user_id' => $user->id]);

        return [$user, $student];
    }

    private function createOrganizationOwner(string $suffix = ''): array
    {
        $user = $this->createUser('employer', $suffix);
        $organization = $this->createOrganization($user);

        return [$user, $organization];
    }

    private function createOrganization(?User $user = null): Organization
    {
        $user ??= $this->createUser('employer');

        return Organization::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Organization',
            'location' => 'Dar es Salaam',
        ]);
    }

    private function createUser(string $role, string $suffix = ''): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.$suffix.'-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function opportunityAttributes(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Test Opportunity',
            'role_type' => 'Full-Time',
            'location' => 'Dar es Salaam',
            'description' => 'A practical field attachment opportunity.',
            'requirements' => 'Currently enrolled student.',
            'responsibilities' => 'Support the team.',
            'industry' => 'Technology & ICT',
            'duration_months' => 6,
            'available_slots' => 3,
            'deadline' => now()->addMonth()->toIso8601String(),
            'start_date' => now()->addMonths(2)->toDateString(),
            'end_date' => now()->addMonths(8)->toDateString(),
            'stipend_amount' => 500000,
            'stipend_currency' => 'TZS',
            'stipend_period' => 'month',
        ], $overrides);
    }
}
