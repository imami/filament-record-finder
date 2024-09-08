<?php

namespace Statview\FilamentRecordFinder\Forms;

use Closure;
use App\Synths\Objects\ColumnsArray;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Concerns\CanGenerateUuids;
use Filament\Forms\Components\Concerns\HasContainerGridLayout;
use Filament\Forms\Components\Field;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\Types\False_;

class RecordFinderField extends Field implements HasForms
{
    use InteractsWithForms, CanGenerateUuids, HasContainerGridLayout;

    protected string $view = 'filament-record-finder::record-finder';

    public ?string $modelClass = null;

    public string|Closure|null $relationship = null;

    protected string|Closure|null $relationshipTitleAttribute = null;

    protected ?Collection $cachedExistingRecords = null;

    public ?string $randomId = null;

    public ?string $linkActionLabel = null;

    public ?Closure $linkActionLink = null;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    protected bool | \Closure | null $multiple = true;

    protected ?string $recordFinder = null;

    protected function setUp(): void
    {
        $this->default([]);

        $this->afterStateHydrated(static function (RecordFinderField $component, $state) {
            if (is_array($state)) {
                return;
            }

            $component->state([]);
        });

        $this->registerActions([
            fn(RecordFinderField $component): Action => $component->getAddAction(),
            fn(RecordFinderField $component): Action => $component->getRemoveAction(),
            fn(RecordFinderField $component): Action => $component->getLinkAction(),
        ]);

        $this->registerListeners([
            'record-finder::addToState' => [
                function (RecordFinderField $component, $statePath, array $records) {
                    $relationshipTitleAttribute = $component->getRelationshipTitleAttribute();

                    $records = $component->getRelatedModelClass()::query()
                        ->whereIn('id', $records)
                        ->get()
                        ->pluck($relationshipTitleAttribute, 'id');

                    $items = $this->isMultipleChoicesAllowed() ? $this->getState() : [];

                    foreach ($records as $id => $title) {
                        $items[$component->generateUuid()] = [
                            'id' => $id,
                            'title' => $title,
                        ];
                    }

                    $component->state($items);

                    $component->callAfterStateUpdated();
                }
            ],
        ]);

        $this->mutateDehydratedStateUsing(static function (?array $state): array {
            return array_values($state ?? []);
        });

        $this->randomId = Str::random(6);
    }

    public function getAddAction(): Action
    {
        return Action::make('add')
            ->icon('heroicon-o-magnifying-glass-plus')
            ->color('primary')
            ->iconButton()
            ->modalHeading('Record finder' . $this->label)
            ->modalContent(function (RecordFinderField $component) {
                $recordFinderComponent = $component->getRecordFinder();
                $componentName = str_replace('\\', '.', $recordFinderComponent);

                return new HtmlString(
                    Blade::render(
                        string: "@livewire('{$componentName}', ['multiple' => \$multiple, 'statePath' => \$statePath, 'ownerRecord' => \$ownerRecord, 'existingRecords' => \$existingRecords, 'recordFinderComponentId' => \$recordFinderComponentId])",
                        data: [
                            'multiple' => $this->isMultipleChoicesAllowed(),
                            'statePath' => $component->getStatePath(),
                            'ownerRecord' => $component->getRecord(),
                            'existingRecords' => collect($component->getState())->pluck('id')->toArray(),
                            'recordFinderComponentId' => $component->getLivewire()->getId(),
                        ]
                    )
                );
            })
            ->modalSubmitAction(fn() => false)
            ->modalCancelAction(fn() => false);
    }

    public function getRemoveAction(): Action
    {
        return Action::make('remove')
            ->tooltip(__('filament-record-finder::default.remove'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->iconButton()
            ->size('sm')
            ->action(function (array $arguments, RecordFinderField $component) {
                $items = $component->getState();
                unset($items[$arguments['item']]);

                $component->state($items);

                $component->callAfterStateUpdated();
            });
    }

    public function getLinkAction()
    {
        return Action::make('link')
            ->label($this->linkActionLabel ?? 'Edit')
            ->size('sm')
            ->link();
    }

    public function relationship(string $name, string|Closure|null $titleAttribute, ?Closure $modifyQueryUsing = null): static
    {
        $this->relationship = $name ?? $this->getName();
        $this->relationshipTitleAttribute = $titleAttribute;
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;

        $this->loadStateFromRelationshipsUsing(static function (RecordFinderField $component) {
            $component->clearCachedExistingRecords();

            $component->fillFromRelationship();
        });

        $this->saveRelationshipsUsing(static function (RecordFinderField $component, Model $record, $state) {
            if (! is_array($state)) {
                $state = [];
            }

            $state = array_values(array_map(fn($item) => $item['id'], $state));

            $relationship = $component->getRelationship();

            if ($relationship instanceof HasMany) {
                $relationship->update([
                    $relationship->getForeignKeyName() => null,
                ]);

                $relationship->getModel()::whereIn($relationship->getLocalKeyName(), $state)
                    ->update([
                        $relationship->getForeignKeyName() => $record->id,
                    ]);
            } else if ($relationship instanceof BelongsTo) {
                $record->update([
                    $relationship->getForeignKeyName() => $state[0] ?? null
                ]);
            } else {
                $relationship->sync($state);
            }
        });

        $this->dehydrated(false);

        return $this;
    }

    public function fillFromRelationship(): void
    {
        $this->state(
            $this->getStateFromRelatedRecords($this->getCachedExistingRecords()),
        );
    }

    protected function getStateFromRelatedRecords(Collection $records): array
    {
        if (!$records->count()) {
            return [];
        }

        return $records
            ->pluck($this->getRelationshipTitleAttribute(), 'id')
            ->mapWithKeys(function ($title, $id) {
                return [
                    $this->generateUuid() => [
                        'id' => $id,
                        'title' => $title,
                    ],
                ];
            })->toArray();
    }

    public function getCachedExistingRecords(): Collection
    {
        if ($this->cachedExistingRecords) {
            return $this->cachedExistingRecords;
        }

        $relationship = $this->getRelationship();
        $relationshipQuery = $relationship->getQuery();

        $relatedKeyName = $relationship->getRelated()->getKeyName();

        return $this->cachedExistingRecords = $relationshipQuery->get()->mapWithKeys(
            fn(Model $item): array => ["record-{$item[$relatedKeyName]}" => $item],
        );
    }

    public function clearCachedExistingRecords(): void
    {
        $this->cachedExistingRecords = null;
    }

    public function getRelationshipTitleAttribute(): string
    {
        return $this->evaluate($this->relationshipTitleAttribute);
    }

    protected function getExistingRecordIds(): array
    {
        $items = $this->getState();

        return collect($items)
            ->map(function ($item) {
                return $item['id'];
            })
            ->toArray();
    }

    public function getRelatedModelClass(): string
    {
        $ownerModelClass = $this->getModel();

        return (new $ownerModelClass)->{$this->relationship}()->getQuery()->getModel()::class;
    }

    public function getRelationshipName(): ?string
    {
        return $this->evaluate($this->relationship);
    }

    public function getRelationship(): HasOneOrMany|BelongsToMany|BelongsTo|null
    {
        return $this->getModelInstance()->{$this->getRelationshipName()}();
    }

    public function linkActionLabel(string|null $linkActionLabel): static
    {
        $this->linkActionLabel = $linkActionLabel;

        return $this;
    }

    public function linkActionLink(Closure $link): static
    {
        $this->linkActionLink = $link;

        return $this;
    }

    public function recordFinder(string $recordFinder): static
    {
        $this->recordFinder = $recordFinder;

        return $this;
    }

    public function getRecordFinder(): string
    {
        return $this->recordFinder;
    }

    public function isMultipleChoicesAllowed()
    {
        if ($this->getRelationship() instanceof BelongsTo)
            return false;

        return $this->getMultiple();
    }

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }
    // Get the value of the multiple property in the view
    public function getMultiple(): bool
    {
        return $this->evaluate($this->multiple);
    }
}
