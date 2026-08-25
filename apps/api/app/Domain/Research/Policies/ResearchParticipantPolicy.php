<?php

namespace App\Domain\Research\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Research\Models\ResearchParticipant;

class ResearchParticipantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, ResearchParticipant $researchParticipant): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, ResearchParticipant $researchParticipant): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, ResearchParticipant $researchParticipant): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
