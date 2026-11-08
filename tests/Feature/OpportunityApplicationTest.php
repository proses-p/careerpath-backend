<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OpportunityApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_can_browse_and_filter_available_opportunities(): void
    {
        $availableOpportunity = $this->createOpportunity([
            'title' => 'Data Analyst Attachment',
            'location' => 'Arusha',
            'industry' => 'Finance & Banking',
            'role_type' => 'Full-Time',
            'duration_months' => 6,
        ]);
        $this->createOpportunity([
            'title' => 'Expired Attachment',
            'deadline' => now()->subDay(),
        ]);
        $this->createOpportunity([
            'title' => 'Draft Attachment',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/opportunities?q=Data&locations%5B0%5D=Arusha&industries%5B0%5D=Finance%20%26%20Banking&duration_months=6')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Data Analyst Attachment')
            ->assertJsonPath('data.0.organization.name', 'Test Organization')
            ->assertJsonPath('data.0.is_accepting_applications', true)
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/opportunities/{$availableOpportunity->id}")
            ->assertOk()
            ->assertJsonPath('data.requirements', 'Enrolled in a computing course.')
            ->assertJsonPath('data.remaining_slots', null);
    }

    public function test_student_can_submit_once_and_only_view_their_own_applications(): void
    {
        $this->createUser('employer');
        $student = $this->createStudent();
        $otherStudent = $this->createStudent();
        $opportunity = $this->createOpportunity();

        $studentToken = $this->postJson('/api/v1/login', [
            'email' => $student->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $response = $this->withToken($studentToken)
            ->postJson('/api/v1/student/applications', [
                'student_id' => $otherStudent->student->id,
                'opportunity_id' => $opportunity->id,
                'cover_letter' => 'I am interested in this attachment.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.opportunity.title', 'Software Attachment');

        $applicationId = $response->json('data.id');
        $otherApplication = Application::query()->create([
            'student_id' => $otherStudent->student->id,
            'opportunity_id' => $opportunity->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'student_id' => $student->student->id,
            'opportunity_id' => $opportunity->id,
            'status' => 'pending',
            'cover_letter' => 'I am interested in this attachment.',
        ]);
        $this->assertDatabaseHas('application_events', [
            'application_id' => $applicationId,
            'created_by' => $student->id,
            'status' => 'pending',
        ]);

        $this->withToken($studentToken)
            ->getJson('/api/v1/student/applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $applicationId);

        $this->withToken($studentToken)
            ->getJson("/api/v1/student/applications/{$otherApplication->id}")
            ->assertForbidden();

        $this->withToken($studentToken)
            ->postJson('/api/v1/student/applications', [
                'opportunity_id' => $opportunity->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opportunity_id');

        $this->assertDatabaseCount('applications', 2);
    }

    public function test_closed_or_full_opportunities_cannot_be_applied_to(): void
    {
        $student = $this->createStudent();
        $opportunity = $this->createOpportunity([
            'available_slots' => 1,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/student/applications', [
                'opportunity_id' => $opportunity->id,
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('opportunities', [
            'id' => $opportunity->id,
            'is_active' => false,
        ]);
        $this->getJson('/api/v1/opportunities')
            ->assertOk()
            ->assertJsonMissing(['id' => $opportunity->id]);

        $this->actingAs($this->createStudent(), 'sanctum')
            ->postJson('/api/v1/student/applications', [
                'opportunity_id' => $opportunity->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opportunity_id');
    }

    public function test_application_routes_require_a_student_profile_and_authentication(): void
    {
        $this->getJson('/api/v1/student/applications')->assertUnauthorized();

        $employer = $this->createUser('employer');
        $this->actingAs($employer, 'sanctum')
            ->getJson('/api/v1/student/applications')
            ->assertForbidden();
    }

    private function createStudent(): User
    {
        $user = $this->createUser('student');
        Student::query()->create(['user_id' => $user->id]);

        return $user->load('student');
    }

    private function createUser(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createOpportunity(array $attributes = []): Opportunity
    {
        $organizationUser = $this->createUser('employer');
        $organization = Organization::query()->create([
            'user_id' => $organizationUser->id,
            'name' => 'Test Organization',
            'location' => 'Dar es Salaam',
        ]);

        return Opportunity::query()->create(array_merge([
            'organization_id' => $organization->id,
            'title' => 'Software Attachment',
            'role_type' => 'Full-Time',
            'location' => 'Dar es Salaam',
            'description' => 'Practical software development experience.',
            'requirements' => 'Enrolled in a computing course.',
            'industry' => 'Technology & ICT',
            'duration_months' => 6,
            'available_slots' => null,
            'deadline' => now()->addMonth(),
            'status' => 'published',
            'is_active' => true,
        ], $attributes));
    }
}
