<?php

namespace App\Domain\Heritage\Policies;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;

class PanoramaNodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, PanoramaNode $panoramaNode): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, PanoramaNode $panoramaNode): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, PanoramaNode $panoramaNode): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
