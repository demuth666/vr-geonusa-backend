<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\LoginStudent;
use App\Domain\Identity\Models\User;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\AuthenticatedStudentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginStudent $loginStudent): JsonResponse
    {
        $login = $loginStudent->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json([
            'data' => [
                'token' => $login['token'],
                'user' => AuthenticatedStudentResource::make($this->loadStudent($login['user']))->resolve(),
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request): AuthenticatedStudentResource
    {
        /** @var User $user */
        $user = $request->user();

        return AuthenticatedStudentResource::make($this->loadStudent($user));
    }

    private function loadStudent(User $user): User
    {
        return $user->loadMissing([
            'studentProfile.school',
            'studentProfile.classrooms',
        ]);
    }
}
