<?php

namespace App\Domain\School\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;

class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, School $school): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, School $school): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, School $school): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

}
