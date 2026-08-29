<?php

namespace App\Domain\Geometry\Policies;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;

class GeometryShapePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, GeometryShape $geometryShape): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, GeometryShape $geometryShape): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
