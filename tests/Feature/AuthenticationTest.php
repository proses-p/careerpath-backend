<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\University;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\ApplicationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_registration_creates_a_student_and_ignores_submitted_roles(): void
    {
        [$university, $course] = $this->createAcademicCatalog();

        $response = $this->postJson('/api/v1/register/student', [
            'name' => 'Asha Student',
            'email' => 'ASHA@example.test',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
            'university_id' => $university->id,
            'course_id' => $course->id,
            'registration_number' => 'REG-001',
            'year_of_study' => 3,
            'graduation_year' => now()->year + 1,
            'phone' => '+255700000001',
            'role' => 'admin',
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.email', 'asha@example.test')
            ->assertJsonPath('data.user.profile.registration_number', 'REG-001')
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);

        $user = User::query()->where('email', 'asha@example.test')->firstOrFail();
        $this->assertSame('student', $user->role);
        $this->assertTrue(Hash::check('StrongPass9!', $user->password));
        $this->assertDatabaseHas('students', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'registration_number' => 'REG-001',
        ]);
        $this->assertSame(1, $user->tokens()->count());

        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/student/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/v1/employer/dashboard')->assertForbidden();
    }

    public function test_student_registration_validates_course_ownership_and_duplicate_account_fields(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$university, $course] = $this->createAcademicCatalog();
        $otherUniversity = University::query()->create(['name' => 'Other University']);
        $otherCourse = Course::query()->create(['university_id' => $otherUniversity->id, 'name' => 'Other Course']);

        $payload = [
            'name' => 'Asha Student',
            'email' => 'asha@example.test',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
            'university_id' => $university->id,
            'course_id' => $course->id,
            'registration_number' => 'REG-001',
            'year_of_study' => 3,
            'phone' => '+255700000001',
        ];

        $this->postJson('/api/v1/register/student', [...$payload, 'course_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course_id');
        $this->postJson('/api/v1/register/student', [...$payload, 'university_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['university_id', 'course_id']);
        $this->postJson('/api/v1/register/student', [...$payload, 'course_id' => $otherCourse->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course_id');
        $this->postJson('/api/v1/register/student', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'university_id',
                'course_id',
                'registration_number',
                'year_of_study',
                'phone',
            ]);

        $user = User::factory()->create(['email' => 'asha@example.test']);
        Student::query()->create([
            'user_id' => $user->id,
            'registration_number' => 'REG-001',
        ]);

        $this->postJson('/api/v1/register/student', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'registration_number']);

        $this->postJson('/api/v1/register/student', [...$payload, 'role' => 'admin', 'password' => 'weak'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_student_profile_creation_failure_rolls_back_user_and_token_creation(): void
    {
        [$university, $course] = $this->createAcademicCatalog();

        Student::creating(static function (): void {
            throw new RuntimeException('Unable to create student profile.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/register/student', [
                'name' => 'Asha Student',
                'email' => 'rollback@example.test',
                'password' => 'StrongPass9!',
                'password_confirmation' => 'StrongPass9!',
                'university_id' => $university->id,
                'course_id' => $course->id,
                'registration_number' => 'REG-ROLLBACK',
                'year_of_study' => 2,
                'phone' => '+255700000009',
            ]);
            $this->fail('Student profile creation should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to create student profile.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseMissing('students', ['registration_number' => 'REG-ROLLBACK']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_organization_registration_assigns_only_the_organization_role_and_profile(): void
    {
        $response = $this->postJson('/api/v1/register/organization', [
            'organization_name' => 'Example Organization',
            'email' => 'INFO@example.test',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
            'phone' => '+255700000002',
            'website' => 'https://example.test',
            'description' => 'A registered organization.',
            'address' => '1 Example Road',
            'city' => 'Arusha',
            'region' => 'Arusha',
            'role' => 'admin',
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'employer')
            ->assertJsonPath('data.user.profile.name', 'Example Organization');

        $user = User::query()->where('email', 'info@example.test')->firstOrFail();
        $this->assertSame('employer', $user->role);
        $this->assertDatabaseHas('organizations', [
            'user_id' => $user->id,
            'name' => 'Example Organization',
            'location' => 'Arusha, Arusha',
        ]);

        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/employer/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/v1/student/dashboard')->assertForbidden();
    }

    public function test_login_restores_user_role_and_rejects_bad_credentials_and_inactive_accounts(): void
    {
        $student = User::factory()->create([
            'name' => 'Asha Student',
            'email' => 'asha@example.test',
            'password' => Hash::make('StrongPass9!'),
            'role' => 'student',
        ]);
        Student::query()->create(['user_id' => $student->id]);

        $login = $this->postJson('/api/v1/login', [
            'email' => 'ASHA@example.test',
            'password' => 'StrongPass9!',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.profile.id', $student->student->id);

        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'asha@example.test');

        $this->postJson('/api/v1/login', [
            'email' => 'asha@example.test',
            'password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $student->update(['is_active' => false]);
        $this->postJson('/api/v1/login', [
            'email' => 'asha@example.test',
            'password' => 'StrongPass9!',
        ])->assertForbidden();
    }

    public function test_logout_revokes_the_current_token_and_invalid_tokens_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Student::query()->create(['user_id' => $user->id]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken('invalid-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deactivated_accounts_cannot_use_existing_tokens(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Student::query()->create(['user_id' => $user->id]);
        $token = $user->createToken('test-token')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_universities_and_courses_are_loaded_from_the_database(): void
    {
        [$university, $course] = $this->createAcademicCatalog();

        $this->getJson('/api/v1/universities')
            ->assertOk()
            ->assertJsonPath('data.0.id', $university->id);
        $this->getJson("/api/v1/universities/{$university->id}/courses")
            ->assertOk()
            ->assertJsonPath('data.0.id', $course->id)
            ->assertJsonPath('data.0.university_id', $university->id);
    }

    public function test_default_database_seeding_does_not_create_demo_accounts(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_seeder_is_blocked_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->assertTrue(app()->isProduction());
        $this->expectException(RuntimeException::class);

        app(ApplicationDemoSeeder::class)->run();
    }

    public function test_password_recovery_sends_a_generic_reset_response_and_revokes_old_tokens_on_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'asha@example.test',
            'password' => Hash::make('OldPass9!word'),
        ]);

        $this->postJson('/api/v1/forgot-password', ['email' => 'asha@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link will be sent.');
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $token = Password::createToken($user);
        $user->createToken('old-session');
        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewStrong9!Password',
            'password_confirmation' => 'NewStrong9!Password',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewStrong9!Password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    private function createAcademicCatalog(): array
    {
        $university = University::query()->create(['name' => 'Test University']);
        $course = Course::query()->create([
            'university_id' => $university->id,
            'name' => 'Computer Science',
        ]);

        return [$university, $course];
    }
}
