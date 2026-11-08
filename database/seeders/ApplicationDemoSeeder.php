<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ApplicationDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Application demo accounts must not be seeded in production.');
        }

        $employer = User::factory()->create(['name' => 'Safaricom HR', 'email' => 'hr@safaricom.test', 'role' => 'employer']);
        $studentUser = User::factory()->create(['name' => 'Proses', 'email' => 'student@test.com', 'role' => 'student']);

        $studentProfile = Student::create([
            'user_id' => $studentUser->id,
        ]);

        $organization = Organization::create(['user_id' => $employer->id, 'name' => 'Safaricom', 'location' => 'Dar es Salaam, Tanzania']);

        $opp = $organization->opportunities()->create([
            'title' => 'Software Engineering Field/Intern', 'role_type' => 'Full-Time Field Training',
            'location' => 'Dar es Salaam', 'stipend_amount' => 1000, 'stipend_currency' => 'USD',
            'status' => 'published', 'is_active' => true,
        ]);

        $app = Application::create(['student_id' => $studentProfile->id, 'opportunity_id' => $opp->id, 'status' => 'accepted']);

        foreach ([
            ['submitted', '2026-09-12 10:30'],
            ['screening', '2026-09-15 14:00'],
            ['accepted',  '2026-09-18 09:15'],
        ] as [$status, $at]) {
            $app->events()->create(['status' => $status, 'created_by' => $employer->id, 'created_at' => $at]);
        }
    }
}
