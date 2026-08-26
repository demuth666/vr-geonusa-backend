<?php

namespace App\Filament\Resources\Students;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Students\Pages\ManageStudents;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class StudentResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Manajemen Sekolah';

    protected static ?string $modelLabel = 'Siswa';

    protected static ?string $pluralModelLabel = 'Siswa';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(table: User::class, column: 'email', ignoreRecord: true),
            TextInput::make('password')
                ->label('Kata Sandi')
                ->password()
                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->minLength(8)
                ->dehydrated(fn (?string $state): bool => filled($state)),
            Section::make('Profil Siswa')
                ->relationship('studentProfile')
                ->schema([
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
                    TextInput::make('student_number')
                        ->label('Nomor Siswa')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: 'student_profiles',
                            column: 'student_number',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->where('school_id', $get('school_id')),
                        ),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('studentProfile.name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.student_number')
                    ->label('Nomor Siswa')
                    ->searchable(),
                TextColumn::make('studentProfile.school.name')
                    ->label('Sekolah')
                    ->searchable()
                    ->sortable(),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', UserRole::Student->value)
            ->whereHas('studentProfile');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStudents::route('/'),
        ];
    }
}
