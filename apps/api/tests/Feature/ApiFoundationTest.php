<?php

namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->get('/api/v1/test/protected', fn () => [
            'data' => ['authenticated' => true],
        ]);

        Route::post('/api/v1/test/validation', function () {
            request()->validate(['name' => ['required', 'string']]);

            return response()->noContent();
        });

        Route::get('/api/v1/test/error', fn () => throw new \RuntimeException('Sensitive internal detail.'));
    }

    public function test_protected_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/test/protected')
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ]);
    }

    public function test_protected_api_accepts_a_sanctum_user(): void
    {
        $user = (new TestApiUser)->forceFill(['id' => 1]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/test/protected')
            ->assertOk()
            ->assertExactJson(['data' => ['authenticated' => true]]);
    }

    public function test_missing_api_route_uses_standard_error(): void
    {
        $this->getJson('/api/v1/missing')
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Resource not found.',
                ],
            ]);
    }

    public function test_validation_errors_include_field_details(): void
    {
        $this->postJson('/api/v1/test/validation')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'The given data was invalid.')
            ->assertJsonPath('error.details.fields.name.0', 'The name field is required.');
    }

    public function test_server_errors_do_not_expose_internal_details(): void
    {
        $this->getJson('/api/v1/test/error')
            ->assertInternalServerError()
            ->assertExactJson([
                'error' => [
                    'code' => 'SERVER_ERROR',
                    'message' => 'Server error.',
                ],
            ]);
    }

    public function test_tests_use_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame(1, DB::selectOne('select 1 as connected')->connected);
    }
}

class TestApiUser extends Authenticatable
{
    use HasApiTokens;
}
