<?php

namespace App\Domain\Research\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Research\Models\ResearchStudy;

class ResearchStudyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, ResearchStudy $researchStudy): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, ResearchStudy $researchStudy): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, ResearchStudy $researchStudy): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
