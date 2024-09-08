<?php

namespace Statview\FilamentRecordFinder;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Livewire\Component;

class RecordFinderTable extends Component implements HasTable, HasForms
{
    use InteractsWithForms;
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    public string $statePath;

    public ?Model $ownerRecord;

    public mixed $existingRecords;

    public ?string $recordFinderComponentId = null;

    public bool $multiple = true;

    public function table(Table $table): Table
    {
        return $table;
    }

    public function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(fn(Builder $query) => $query->whereNotIn('id', $this->existingRecords))
            ->when(! $this->multiple, fn ($table) => $table
                ->pushActions([
                    Action::make('select')
                        ->label(__('filament-record-finder::default.select'))
                        ->action(function (Component $livewire, $record) {
                            $livewire
                                ->dispatch('record-finder-attach-records', recordIds: [ $record->getKey() ], statePath: $this->statePath);

                            $livewire
                                ->dispatch('close-modal', id: $this->recordFinderComponentId . '-form-component-action');
                        })
                ])
            )
            ->when($this->multiple, fn ($table) => $table
                ->bulkActions([
                    BulkAction::make('attach')
                        ->label('Link')
                        ->action(function (Component $livewire, $records) {
                            $livewire
                                ->dispatch('record-finder-attach-records', recordIds: $records->pluck('id'), statePath: $this->statePath);

                            $livewire
                                ->dispatch('close-modal', id: $this->recordFinderComponentId . '-form-component-action');
                        }),
                ])
            );
    }

    public function render()
    {
        return <<<'HTML'
        <div>
            {{ $this->table }}
        </div>
        HTML;
    }
}
