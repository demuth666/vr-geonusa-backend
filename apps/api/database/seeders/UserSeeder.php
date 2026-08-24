<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $school = School::firstOrCreate(['name' => 'SMP GeoNusa']);
        $classroom = Classroom::firstOrCreate([
            'school_id' => $school->id,
            'name' => 'Kelas VII A',
        ]);
        $user = User::updateOrCreate(
            ['email' => 'student@example.test'],
            ['password' => 'student'],
        );
        $profile = StudentProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'school_id' => $school->id,
                'name' => 'Siti Rahma',
                'student_number' => 'VII-001',
            ],
        );

        $classroom->studentProfiles()->syncWithoutDetaching($profile);
    }
}
