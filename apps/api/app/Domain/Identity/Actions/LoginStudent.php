<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginStudent
{
    /** @return array{user: User, token: string} */
    public function handle(string $email, string $password): array
    {
        $user = User::query()
            ->where('email', $email)
            ->where('role', UserRole::Student->value)
            ->whereHas('studentProfile')
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $user->createToken('student-api')->plainTextToken,
        ];
    }
}
