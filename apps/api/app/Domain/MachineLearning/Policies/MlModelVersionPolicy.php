<?php

namespace App\Domain\MachineLearning\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\MachineLearning\Models\MlModelVersion;

class MlModelVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, MlModelVersion $mlModelVersion): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, MlModelVersion $mlModelVersion): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, MlModelVersion $mlModelVersion): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
