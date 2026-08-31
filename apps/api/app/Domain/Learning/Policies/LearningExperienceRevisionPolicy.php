<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningExperienceRevision;

class LearningExperienceRevisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, LearningExperienceRevision $revision): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, LearningExperienceRevision $revision): bool
    {
        return $user->role === UserRole::SuperAdmin && $revision->status === 'draft';
    }

    public function delete(User $user, LearningExperienceRevision $revision): bool
    {
        return $this->update($user, $revision);
    }
}
