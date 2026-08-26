<?php

namespace Tests;

use Closure;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Livewire\Features\SupportTesting\Testable;

abstract class TestCase extends BaseTestCase
{
    /** @param array<string, mixed>|Closure(array<string, mixed>): array<string, mixed> $data */
    protected function callFilamentAction(
        Testable $component,
        string|TestAction $action,
        array|Closure $data,
    ): Testable {
        $component->mountAction($action);
        $state = $component->get('mountedActions.0.data');
        $data = $data instanceof Closure ? $data($state) : $data;

        foreach (Arr::dot($data) as $key => $value) {
            if (! $value instanceof UploadedFile && ! (is_array($value) && ($value[0] ?? null) instanceof UploadedFile)) {
                continue;
            }

            $path = "mountedActions.0.data.{$key}";
            $component->set($path, $value);
            Arr::set($data, $key, $component->get($path));
        }

        $component->set('mountedActions.0.data', $data);

        return $component->callMountedAction();
    }
}
