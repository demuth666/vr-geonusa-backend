<?php

namespace App\Domain\Heritage\Policies;

use App\Domain\Heritage\Models\PanoramaLink;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;

class PanoramaLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, PanoramaLink $panoramaLink): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, PanoramaLink $panoramaLink): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, PanoramaLink $panoramaLink): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
