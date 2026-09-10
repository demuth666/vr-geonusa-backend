<?php

namespace App\Domain\MachineLearning\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\MachineLearning\Models\MlModel;

class MlModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, MlModel $mlModel): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, MlModel $mlModel): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, MlModel $mlModel): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
