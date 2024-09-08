<x-dynamic-component
        :component="$getFieldWrapperView()"
        :field="$field"
>
    @php
        $removeAction = $getAction('remove');
        $linkAction = $getAction('link');
        $items = $getState();
        $multipleChoicesAllowed = $isMultipleChoicesAllowed();
        $editable = false;
        $suffixActions = [
            $getAction('add'),
        ];
    @endphp

    <x-filament::input.wrapper
        :suffix-actions="$suffixActions"
    >
    <div
            x-data="{
                statePath: '{{ $getStatePath() }}',
                state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$getStatePath()}')") }},
                handleAttachRecords($event) {
                    if ($event.detail.statePath != this.statePath) {
                        return;
                    }

                    $wire.dispatchFormEvent('record-finder::addToState', '{{ $getStatePath() }}', $event.detail.recordIds)
                },
            }"
            x-on:record-finder-attach-records.window="($event) => handleAttachRecords($event)"
    >
        <div class="fi-rf-items p-1">
                @forelse($items as $uuid => $item)
                    <x-filament::input.wrapper
                        :prefix-actions="[$removeAction(['item' => $uuid])]"
                    >
                        <div class="flex px-1 py-2.5">{{ $item['title'] }}</div>
                    </x-filament::input.wrapper>

                @empty
                    <div class="inline-block m-2"></div>
                @endforelse
        </div>
    </div>
    </x-filament::input.wrapper>
</x-dynamic-component>
