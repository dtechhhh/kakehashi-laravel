<div>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.lookup.title') }}</h1>
                <p class="mt-1 text-sm text-zinc-600">{{ __('ui.lookup.subtitle') }}</p>
            </div>
            <x-select id="lookup-table" name="table" wire:model.live="table"
                :options="collect($tables)->mapWithKeys(fn ($t) => [$t => $t])" class="w-72" aria-label="{{ __('ui.lookup.table') }}" />
        </div>

        @if ($actionError)
            <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
        @endif
        @if ($actionSuccess)
            <x-alert type="success" wire:key="success">{{ $actionSuccess }}</x-alert>
        @endif

        @if ($showForm)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-zinc-900">
                    {{ $editingId === null ? __('ui.lookup.create') : __('ui.lookup.edit') }}
                    <span class="ml-1 font-mono text-xs font-normal text-zinc-500">{{ $table }}</span>
                </h2>
                <form wire:submit="save" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-input id="lookup-code" name="code" wire:model="formCode" label="{{ __('ui.lookup.code') }}"
                        :required="$editingId === null" :readonly="$editingId !== null"
                        :hint="$editingId !== null ? __('ui.lookup.code_immutable') : null" />
                    <x-input id="lookup-label-id" name="label_id" wire:model="formLabelId" label="{{ __('ui.lookup.label_id') }}" required />
                    <x-input id="lookup-label-ja" name="label_ja" wire:model="formLabelJa" label="{{ __('ui.lookup.label_ja') }}" required />
                    <x-input id="lookup-sort" name="sort_order" type="number" wire:model="formSortOrder" label="{{ __('ui.lookup.sort_order') }}" />

                    @foreach ($extraColumns as $column)
                        @if ($column['type'] === 'text')
                            <x-input :id="'lookup-'.$column['name']" :name="$column['name']" wire:model="{{ 'formExtras.'.$column['name'] }}"
                                :label="__('ui.lookup.columns.'.$column['name'])" />
                        @elseif ($column['type'] === 'bool')
                            <label class="flex items-center gap-2 pt-6 text-sm text-zinc-700">
                                <input type="checkbox" :name="$column['name']" wire:model="{{ 'formExtras.'.$column['name'] }}" value="1"
                                    class="h-4 w-4 rounded border-zinc-300 text-zinc-900 focus-visible:outline-2 focus-visible:outline-blue-600">
                                {{ __('ui.lookup.columns.'.$column['name']) }}
                            </label>
                        @else
                            <x-select :id="'lookup-'.$column['name']" :name="$column['name']" wire:model="{{ 'formExtras.'.$column['name'] }}"
                                :label="__('ui.lookup.columns.'.$column['name'])"
                                :options="($parentOptions[$column['name']] ?? [])" placeholder="{{ __('ui.lookup.no_parent') }}" />
                        @endif
                    @endforeach

                    <div class="flex gap-2 md:col-span-2">
                        <x-button type="button" variant="secondary" wire:click="cancelForm">{{ __('ui.common.cancel') }}</x-button>
                        <x-button type="submit">{{ __('ui.common.save') }}</x-button>
                    </div>
                </form>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            <x-input id="lookup-search" name="search" type="search" wire:model.live.debounce.400ms="search"
                placeholder="{{ __('ui.common.search') }}" class="w-64" aria-label="{{ __('ui.common.search') }}" />
            <x-select id="lookup-active" name="active" wire:model.live="active"
                :options="['' => __('ui.lookup.active_all'), '1' => __('ui.lookup.active_only'), '0' => __('ui.lookup.active_disabled')]" class="w-48" />
            <x-button size="sm" wire:click="startCreate" class="ml-auto">{{ __('ui.lookup.create') }}</x-button>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        @foreach ([['code', 'ui.lookup.code'], ['label_id', 'ui.lookup.label_id'], ['label_ja', 'ui.lookup.label_ja'], ['sort_order', 'ui.lookup.sort_order']] as [$column, $label])
                            <th scope="col" class="px-4 py-2.5 font-semibold">
                                <button type="button" wire:click="sortBy('{{ $column }}')"
                                    class="inline-flex items-center gap-1 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                    {{ __($label) }}
                                    @if ($sort === $column)
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            @if ($direction === 'asc')
                                                <path d="m6 9 6 6 6-6" />
                                            @else
                                                <path d="m6 15 6-6 6 6" />
                                            @endif
                                        </svg>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.lookup.status') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-4 py-2.5 font-mono text-xs text-zinc-700">{{ $row->code }}</td>
                            <td class="px-4 py-2.5 text-zinc-900">{{ $row->label_id }}</td>
                            <td class="px-4 py-2.5 text-zinc-900">{{ $row->label_ja }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ $row->sort_order }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="$row->is_active ? 'success' : 'neutral'" :icon="$row->is_active ? 'check-circle' : 'dot'">
                                    {{ $row->is_active ? __('ui.lookup.active') : __('ui.lookup.inactive') }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1.5">
                                    <x-button size="sm" variant="ghost" wire:click="startEdit({{ $row->id }})">{{ __('ui.common.edit') }}</x-button>
                                    @if ($row->is_active)
                                        <x-button size="sm" variant="destructive" wire:click="toggleActive({{ $row->id }}, false)">{{ __('ui.lookup.disable') }}</x-button>
                                    @else
                                        <x-button size="sm" variant="secondary" wire:click="toggleActive({{ $row->id }}, true)">{{ __('ui.lookup.enable') }}</x-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10">
                                <x-state type="empty" class="!border-0 !shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $rows->links() }}
            </div>
        </div>
    </div>

    <livewire:step-up-modal />
</div>
