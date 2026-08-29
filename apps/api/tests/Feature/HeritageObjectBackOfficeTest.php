<?php

namespace Tests\Feature;

use App\Domain\Heritage\Actions\DeleteHeritageObject;
use App\Domain\Heritage\Exceptions\HeritageObjectInUse;
use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\HeritageSite;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Filament\Resources\HeritageObjects\HeritageObjectResource;
use App\Filament\Resources\HeritageObjects\Pages\ManageHeritageObjects;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class HeritageObjectBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3', ['url' => 'http://storage.test/vr-geonusa-dev']);
        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_heritage_objects_but_students_cannot(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', HeritageObject::class));
        }

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(HeritageObjectResource::getUrl('index'))
            ->assertOk();

        $student = $this->createUser(UserRole::Student);

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', HeritageObject::class));
        $this->actingAs($student)
            ->get(HeritageObjectResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_only_super_admin_can_manage_heritage_objects(): void
    {
        $heritageObject = HeritageObject::query()->sole();
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', HeritageObject::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $heritageObject));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $heritageObject));

        foreach ([UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertFalse(Gate::forUser($user)->allows('create', HeritageObject::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $heritageObject));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $heritageObject));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));

        Livewire::test(ManageHeritageObjects::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($heritageObject))
            ->assertActionHidden(TestAction::make('delete')->table($heritageObject));
    }

    public function test_super_admin_crud_is_visible_through_the_learning_material_api(): void
    {
        $site = HeritageSite::query()->sole();
        $stupa = HeritageObject::query()->sole();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $component = Livewire::test(ManageHeritageObjects::class);

        $this->callFilamentAction($component, 'create', [
            'heritage_site_id' => $site->id,
            'name' => 'Stupa',
            'description' => 'Objek warisan tambahan.',
        ])->assertHasNoActionErrors();

        $created = HeritageObject::query()->where('slug', 'stupa-2')->sole();

        $this->callFilamentAction(
            Livewire::test(ManageHeritageObjects::class),
            TestAction::make('edit')->table($stupa),
            [
                'heritage_site_id' => $site->id,
                'name' => 'Stupa Utama',
                'description' => 'Deskripsi objek warisan dari Filament.',
            ],
        )->assertHasNoActionErrors();

        $this->getJson("/api/v1/heritage-objects/{$stupa->id}/learning-material")
            ->assertOk()
            ->assertJsonPath('data.heritage_object.name', 'Stupa Utama')
            ->assertJsonPath(
                'data.heritage_object.description',
                'Deskripsi objek warisan dari Filament.',
            );

        Livewire::test(ManageHeritageObjects::class)
            ->callAction(TestAction::make('delete')->table($created))
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('heritage_objects', ['id' => $created->id]);
    }

    public function test_super_admin_cannot_move_an_existing_heritage_object_to_another_site(): void
    {
        $originalSite = HeritageSite::query()->sole();
        $otherSite = HeritageSite::query()->create([
            'name' => 'Situs Lain',
            'slug' => 'situs-lain',
            'description' => null,
            'cover_image_url' => 'heritage/situs-lain-cover.jpg',
        ]);
        $heritageObject = HeritageObject::query()->sole();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageHeritageObjects::class),
            TestAction::make('edit')->table($heritageObject),
            [
                'heritage_site_id' => $otherSite->id,
                'name' => $heritageObject->name,
                'description' => $heritageObject->description,
            ],
        )->assertHasNoActionErrors();

        $this->assertDatabaseHas('heritage_objects', [
            'id' => $heritageObject->id,
            'heritage_site_id' => $originalSite->id,
        ]);
    }

    public function test_existing_heritage_object_rejects_site_reassignment(): void
    {
        $originalSite = HeritageSite::query()->sole();
        $otherSite = HeritageSite::query()->create([
            'name' => 'Situs Lain',
            'slug' => 'situs-lain',
            'description' => null,
            'cover_image_url' => 'heritage/situs-lain-cover.jpg',
        ]);
        $heritageObject = HeritageObject::query()->sole();

        try {
            $heritageObject->update(['heritage_site_id' => $otherSite->id]);
            $this->fail('Expected site reassignment to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Situs warisan pada objek tidak dapat diubah setelah dibuat.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($heritageObject->refresh()->heritageSite->is($originalSite));
    }

    public function test_existing_heritage_object_accepts_same_site_update(): void
    {
        $heritageObject = HeritageObject::query()->sole();

        $heritageObject->update([
            'heritage_site_id' => $heritageObject->heritage_site_id,
            'name' => 'Stupa Diperbarui',
        ]);

        $this->assertSame('Stupa Diperbarui', $heritageObject->refresh()->name);
    }

    public function test_referenced_heritage_object_cannot_be_deleted(): void
    {
        $heritageObject = HeritageObject::query()->sole();

        try {
            app(DeleteHeritageObject::class)->execute($heritageObject);
            $this->fail('Expected deletion of a referenced Heritage Object to be rejected.');
        } catch (HeritageObjectInUse $exception) {
            $this->assertSame(
                'Objek warisan masih digunakan dan tidak dapat dihapus.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('heritage_objects', ['id' => $heritageObject->id]);
        $this->assertDatabaseHas('panorama_object_annotations', [
            'heritage_object_id' => $heritageObject->id,
        ]);
        $this->assertDatabaseHas('heritage_geometry_mappings', [
            'heritage_object_id' => $heritageObject->id,
        ]);
    }

    public function test_unused_heritage_object_can_be_deleted(): void
    {
        $heritageObject = HeritageObject::query()->create([
            'heritage_site_id' => HeritageSite::query()->sole()->id,
            'name' => 'Objek Tanpa Referensi',
            'description' => null,
        ]);

        app(DeleteHeritageObject::class)->execute($heritageObject);

        $this->assertDatabaseMissing('heritage_objects', ['id' => $heritageObject->id]);
    }

    public function test_heritage_object_with_historical_activity_cannot_be_deleted(): void
    {
        $heritageObject = HeritageObject::query()->create([
            'heritage_site_id' => HeritageSite::query()->sole()->id,
            'name' => 'Objek Dengan Riwayat',
            'description' => null,
        ]);
        $study = ResearchStudy::query()->create(['name' => 'Studi']);
        $participant = ResearchParticipant::query()->create([
            'research_study_id' => $study->id,
        ]);
        $session = LearningSession::query()->create([
            'research_participant_id' => $participant->id,
            'write_token_hash' => hash('sha256', 'token'),
        ]);
        ActivityEvent::query()->create([
            'learning_session_id' => $session->id,
            'type' => ActivityEvent::MATERIAL_VIEWED,
            'heritage_object_id' => $heritageObject->id,
            'occurred_at' => now(),
        ]);

        $this->expectException(HeritageObjectInUse::class);

        app(DeleteHeritageObject::class)->execute($heritageObject);
    }

    public function test_database_rejects_direct_deletion_of_a_referenced_heritage_object(): void
    {
        $heritageObject = HeritageObject::query()->sole();

        try {
            DB::transaction(
                fn () => DB::table('heritage_objects')
                    ->where('id', $heritageObject->id)
                    ->delete(),
            );
            $this->fail('Expected the database to reject deletion of a referenced object.');
        } catch (QueryException) {
            $this->assertDatabaseHas('heritage_objects', ['id' => $heritageObject->id]);
        }
    }

    public function test_filament_explains_why_a_referenced_heritage_object_cannot_be_deleted(): void
    {
        $heritageObject = HeritageObject::query()->sole();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        Livewire::test(ManageHeritageObjects::class)
            ->callAction(TestAction::make('delete')->table($heritageObject))
            ->assertNotified('Objek warisan masih digunakan dan tidak dapat dihapus.');

        $this->assertDatabaseHas('heritage_objects', ['id' => $heritageObject->id]);
    }
}
