<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Quiz;

class QuizPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, Quiz $quiz): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, Quiz $quiz): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function delete(User $user, Quiz $quiz): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
