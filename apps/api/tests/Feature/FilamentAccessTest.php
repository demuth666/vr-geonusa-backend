<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_filament_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_students_cannot_access_filament(): void
    {
        $student = User::create([
            'email' => 'student-panel@example.test',
            'password' => 'password',
        ]);

        $this->actingAs($student)
            ->get('/admin')
            ->assertForbidden();
    }

    #[DataProvider('internalRoles')]
    public function test_internal_roles_can_access_filament(UserRole $role): void
    {
        $user = User::create([
            'email' => "{$role->value}@example.test",
            'password' => 'password',
            'role' => $role,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();
    }

    /** @return array<string, array{UserRole}> */
    public static function internalRoles(): array
    {
        return [
            'super admin' => [UserRole::SuperAdmin],
            'researcher' => [UserRole::Researcher],
            'teacher' => [UserRole::Teacher],
        ];
    }
}
