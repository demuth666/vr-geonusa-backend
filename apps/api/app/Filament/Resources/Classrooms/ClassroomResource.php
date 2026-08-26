<?php

namespace App\Filament\Resources\Classrooms;

use App\Domain\School\Models\Classroom;
use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClassroomResource extends Resource
{
    protected static ?string $model = Classroom::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Manajemen Sekolah';

    protected static ?string $modelLabel = 'Kelas';

    protected static ?string $pluralModelLabel = 'Kelas';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('school_id')
                ->label('Sekolah')
                ->relationship('school', 'name')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255),
            Select::make('studentProfiles')
                ->label('Siswa')
                ->relationship(
                    name: 'studentProfiles',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                        ->where('school_id', $get('school_id')),
                )
                ->multiple()
                ->searchable()
                ->preload()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('school.name')
                    ->label('Sekolah')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student_profiles_count')
                    ->counts('studentProfiles')
                    ->label('Siswa'),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()->databaseTransaction(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClassrooms::route('/'),
        ];
    }
}
