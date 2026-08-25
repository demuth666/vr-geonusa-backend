<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\ClassroomMember;
use App\Domain\School\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_login_and_access_their_profile(): void
    {
        $user = $this->createStudent();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.student_profile.name', 'Siti Rahma')
            ->assertJsonPath('data.user.student_profile.student_number', 'VII-001')
            ->assertJsonPath('data.user.student_profile.school.name', 'SMP GeoNusa')
            ->assertJsonPath('data.user.student_profile.classrooms.0.name', 'Kelas VII A')
            ->assertJsonStructure(['data' => ['token']])
            ->assertJsonMissingPath('data.user.password');

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.fields.email.0', 'The provided credentials are incorrect.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_student_api_rejects_internal_roles_and_students_without_profiles(): void
    {
        $researcher = User::create([
            'email' => 'researcher@example.test',
            'password' => 'correct-password',
            'role' => UserRole::Researcher,
        ]);
        $studentWithoutProfile = User::create([
            'email' => 'unlinked-student@example.test',
            'password' => 'correct-password',
        ]);

        foreach ([$researcher, $studentWithoutProfile] as $user) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
                ->assertUnprocessable()
                ->assertJsonPath('error.details.fields.email.0', 'The provided credentials are incorrect.');
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_profile_and_logout_require_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->createStudent();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        Auth::forgetGuards();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function createStudent(): User
    {
        $school = School::create(['name' => 'SMP GeoNusa']);
        $user = User::create([
            'email' => 'siti@example.test',
            'password' => 'correct-password',
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'name' => 'Siti Rahma',
            'student_number' => 'VII-001',
        ]);
        $classroom = Classroom::create([
            'school_id' => $school->id,
            'name' => 'Kelas VII A',
        ]);

        ClassroomMember::create([
            'classroom_id' => $classroom->id,
            'student_profile_id' => $profile->id,
        ]);

        return $user;
    }
}
