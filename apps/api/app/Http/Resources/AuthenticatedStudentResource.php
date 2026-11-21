<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticatedStudentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->studentProfile;

        return [
            'id' => $this->id,
            'email' => $this->email,
            // The respondent code is the student's research-safe identifier.
            // It selects the participant when the student starts a learning
            // session, so the student never has to type or know it.
            'respondent_code' => $profile?->researchParticipants->first()?->respondent_code,
            'student_profile' => $profile ? [
                'id' => $profile->id,
                'name' => $profile->name,
                'student_number' => $profile->student_number,
                'school' => [
                    'id' => $profile->school->id,
                    'name' => $profile->school->name,
                ],
                'classrooms' => $profile->classrooms->map(fn ($classroom) => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                ])->values()->all(),
            ] : null,
        ];
    }
}
