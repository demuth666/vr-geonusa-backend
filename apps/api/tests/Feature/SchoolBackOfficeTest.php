<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use App\Filament\Resources\Schools\Pages\ManageSchools;
use App\Filament\Resources\Schools\SchoolResource;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Students\StudentResource;
use Database\Seeders\UserSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_school_structure_but_student_identity_is_super_admin_only(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', School::class));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', Classroom::class));
            $this->assertSame(
                $role === UserRole::SuperAdmin,
                Gate::forUser($user)->allows('viewAny', User::class),
            );
        }

        $student = StudentProfile::query()->firstOrFail()->user;
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', School::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', Classroom::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', User::class));

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(SchoolResource::getUrl('index'))
            ->assertOk();
        $this->get(ClassroomResource::getUrl('index'))->assertOk();
        $this->get(StudentResource::getUrl('index'))->assertForbidden();
    }

    public function test_only_super_admin_can_manage_school_records_and_students(): void
    {
        $school = School::query()->firstOrFail();
        $classroom = Classroom::query()->firstOrFail();
        $student = StudentProfile::query()->firstOrFail()->user;
        $internalUser = $this->createUser(UserRole::Researcher);
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        foreach ([School::class, Classroom::class, User::class] as $model) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows('create', $model));
        }
        foreach ([$school, $classroom, $student] as $record) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $record));
            $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $record));
        }
        $this->assertFalse(Gate::forUser($superAdmin)->allows('update', $internalUser));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $internalUser));

        $teacher = $this->createUser(UserRole::Teacher);
        foreach ([School::class, Classroom::class, User::class] as $model) {
            $this->assertFalse(Gate::forUser($teacher)->allows('create', $model));
        }
        foreach ([$school, $classroom, $student] as $record) {
            $this->assertFalse(Gate::forUser($teacher)->allows('update', $record));
            $this->assertFalse(Gate::forUser($teacher)->allows('delete', $record));
        }

        $this->actingAs($teacher);
        Livewire::test(ManageSchools::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($school));
        Livewire::test(ManageClassrooms::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($classroom));
    }

    public function test_filament_created_student_can_login_with_assigned_school_and_classroom(): void
    {
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageSchools::class),
            'create',
            ['name' => 'SMP Filament'],
        )->assertHasNoActionErrors();
        $school = School::query()->where('name', 'SMP Filament')->sole();

        $this->callFilamentAction(
            Livewire::test(ManageClassrooms::class),
            'create',
            [
                'school_id' => $school->id,
                'name' => 'Kelas VIII B',
                'studentProfiles' => [],
            ],
        )->assertHasNoActionErrors();
        $classroom = Classroom::query()->where('school_id', $school->id)->sole();

        $this->callFilamentAction(
            Livewire::test(ManageStudents::class),
            'create',
            fn (array $state): array => [
                ...$state,
                'email' => 'filament-student@example.test',
                'password' => 'student-secret',
                'studentProfile' => [
                    ...$state['studentProfile'],
                    'school_id' => $school->id,
                    'name' => 'Siswa Filament',
                    'student_number' => 'VIII-002',
                ],
            ],
        )->assertHasNoActionErrors();

        $student = User::query()->where('email', 'filament-student@example.test')->sole();
        $profile = $student->studentProfile()->sole();
        $this->assertSame(UserRole::Student, $student->role);

        $this->callFilamentAction(
            Livewire::test(ManageClassrooms::class),
            TestAction::make('edit')->table($classroom),
            [
                'school_id' => $school->id,
                'name' => $classroom->name,
                'studentProfiles' => [$profile->id],
            ],
        )->assertHasNoActionErrors();

        $this->callFilamentAction(
            Livewire::test(ManageStudents::class),
            TestAction::make('edit')->table($student),
            fn (array $state): array => [
                ...$state,
                'studentProfile' => [
                    ...$state['studentProfile'],
                    'name' => 'Siswa Filament Diperbarui',
                ],
            ],
        )->assertHasNoActionErrors();

        $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'student-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.student_profile.name', 'Siswa Filament Diperbarui')
            ->assertJsonPath('data.user.student_profile.student_number', 'VIII-002')
            ->assertJsonPath('data.user.student_profile.school.name', 'SMP Filament')
            ->assertJsonPath('data.user.student_profile.classrooms.0.name', 'Kelas VIII B')
            ->assertJsonMissingPath('data.user.password');

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('delete')->table($student));

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('student_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('classroom_members', ['student_profile_id' => $profile->id]);
    }

    public function test_classroom_rejects_students_from_another_school(): void
    {
        $classroom = Classroom::query()->firstOrFail();
        $otherSchool = School::create(['name' => 'SMP Lain']);
        $otherUser = User::create([
            'email' => 'other-school@example.test',
            'password' => 'password',
        ]);
        $otherProfile = StudentProfile::create([
            'user_id' => $otherUser->id,
            'school_id' => $otherSchool->id,
            'name' => 'Siswa Sekolah Lain',
            'student_number' => 'OTHER-001',
        ]);
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageClassrooms::class),
            TestAction::make('edit')->table($classroom),
            [
                'school_id' => $classroom->school_id,
                'name' => $classroom->name,
                'studentProfiles' => [$otherProfile->id],
            ],
        )->assertHasActionErrors(['studentProfiles.0']);

        $this->assertFalse($classroom->studentProfiles()->whereKey($otherProfile->id)->exists());
    }

    private function createUser(UserRole $role): User
    {
        return User::create([
            'email' => "{$role->value}-".str()->random(8).'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
