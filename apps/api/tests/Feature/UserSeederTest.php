<?php

namespace Tests\Feature;

use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_seed_demo_credentials_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        app(UserSeeder::class)->run();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_seeds_a_login_ready_student_idempotently(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student@example.test',
            'password' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.student_profile.school.name', 'SMP GeoNusa')
            ->assertJsonPath('data.user.student_profile.classrooms.0.name', 'Kelas VII A');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('student_profiles', 1);
        $this->assertDatabaseCount('classroom_members', 1);
    }
}
