<?php

namespace App\Filament\Resources\MlInferenceRuns;

use App\Domain\MachineLearning\Models\MlDetection;
use App\Domain\MachineLearning\Models\MlInferenceRun;
use App\Filament\Resources\MlInferenceRuns\Pages\ManageMlInferenceRuns;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MlInferenceRunResource extends Resource
{
    protected static ?string $model = MlInferenceRun::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Machine Learning';

    protected static ?string $modelLabel = 'Proses Inferensi';

    protected static ?string $pluralModelLabel = 'Proses Inferensi';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['modelVersion.model', 'detections']))
            ->columns([
                TextColumn::make('modelVersion.model.name')
                    ->label('Model')
                    ->placeholder('Tidak ada (gagal)')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('modelVersion.version')
                    ->label('Versi Model')
                    ->placeholder('Tidak ada (gagal)')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('camera_yaw')
                    ->label('Yaw Kamera')
                    ->sortable(),
                TextColumn::make('camera_pitch')
                    ->label('Pitch Kamera')
                    ->sortable(),
                TextColumn::make('camera_fov')
                    ->label('FOV Kamera')
                    ->sortable(),
                TextColumn::make('inference_ms')
                    ->label('Latensi Inferensi (ms)')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('total_latency_ms')
                    ->label('Latensi Total (ms)')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('failure_reason')
                    ->label('Alasan Gagal')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('detections')
                    ->label('Deteksi')
                    ->state(fn (MlInferenceRun $record): array => $record->detections
                        ->map(fn (MlDetection $detection): string => sprintf(
                            '%s (keyakinan %.4f, kotak batas %s)',
                            $detection->class_key,
                            $detection->confidence,
                            json_encode($detection->bounding_box),
                        ))
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Tidak ada deteksi')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMlInferenceRuns::route('/'),
        ];
    }
}
