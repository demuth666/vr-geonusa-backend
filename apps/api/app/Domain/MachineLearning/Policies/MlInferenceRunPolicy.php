<?php

namespace App\Domain\MachineLearning\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\MachineLearning\Models\MlInferenceRun;

class MlInferenceRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isInternal();
    }

    public function view(User $user, MlInferenceRun $mlInferenceRun): bool
    {
        return $this->viewAny($user);
    }
}
