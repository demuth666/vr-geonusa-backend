<?php

namespace App\Domain\Heritage\Policies;

use App\Domain\Heritage\Models\HeritageSite;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;

class HeritageSitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, HeritageSite $heritageSite): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, HeritageSite $heritageSite): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, HeritageSite $heritageSite): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
