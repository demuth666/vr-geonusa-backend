<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $credentials = validator(config('admin'), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:72'],
        ])->validate();

        if (User::query()
            ->where('email', $credentials['email'])
            ->where('role', '!=', UserRole::SuperAdmin->value)
            ->exists()) {
            throw ValidationException::withMessages([
                'email' => ['The email already belongs to a non-admin account.'],
            ]);
        }

        User::updateOrCreate(
            ['email' => $credentials['email']],
            [
                'password' => $credentials['password'],
                'role' => UserRole::SuperAdmin,
            ],
        );
    }
}
