<?php

namespace App\Domain\Geometry\Policies;

use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;

class HeritageGeometryMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, HeritageGeometryMapping $heritageGeometryMapping): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Researcher], true);
    }

    public function update(User $user, HeritageGeometryMapping $heritageGeometryMapping): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Researcher], true);
    }
}
