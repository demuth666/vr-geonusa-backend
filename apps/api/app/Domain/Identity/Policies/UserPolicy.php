<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function view(User $user, User $student): bool
    {
        return $this->canManageStudent($user, $student);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, User $student): bool
    {
        return $this->canManageStudent($user, $student);
    }

    public function delete(User $user, User $student): bool
    {
        return $this->canManageStudent($user, $student);
    }

    private function canManageStudent(User $user, User $student): bool
    {
        return $user->role === UserRole::SuperAdmin
            && $student->role === UserRole::Student;
    }
}
