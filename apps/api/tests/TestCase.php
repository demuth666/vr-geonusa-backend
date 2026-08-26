<?php

namespace Tests;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Closure;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Features\SupportTesting\Testable;

abstract class TestCase extends BaseTestCase
{
    /** @param array<string, mixed>|Closure(array<string, mixed>): array<string, mixed> $data */
    protected function callFilamentAction(
        Testable $component,
        string|TestAction $action,
        array|Closure $data,
    ): Testable {
        return $component->mountAction($action)->fillForm($data)->callMountedAction();
    }

    protected function createUser(UserRole $role): User
    {
        return User::create([
            'email' => "{$role->value}-".str()->random(8).'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
