<?php

namespace App\Domain\Heritage\Policies;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;

class HeritageObjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, HeritageObject $heritageObject): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, HeritageObject $heritageObject): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, HeritageObject $heritageObject): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
