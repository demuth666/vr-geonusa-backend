<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_super_admin_idempotently(): void
    {
        config([
            'admin.email' => 'admin@example.test',
            'admin.password' => 'secure-admin-password',
        ]);

        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::sole();

        $this->assertSame(UserRole::SuperAdmin, $admin->role);
        $this->assertTrue(Hash::check('secure-admin-password', $admin->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_rejects_missing_or_insecure_credentials(): void
    {
        config([
            'admin.email' => null,
            'admin.password' => 'short',
        ]);

        $this->expectException(ValidationException::class);

        $this->seed(AdminUserSeeder::class);
    }

    public function test_it_does_not_promote_an_existing_student(): void
    {
        User::create([
            'email' => 'student@example.test',
            'password' => 'student-password',
        ]);
        config([
            'admin.email' => 'student@example.test',
            'admin.password' => 'secure-admin-password',
        ]);

        $this->expectException(ValidationException::class);

        $this->seed(AdminUserSeeder::class);
    }
}
