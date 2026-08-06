<div>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.company.title') }}</h1>
                <p class="mt-1 text-sm text-zinc-600">{{ __('ui.company.subtitle') }}</p>
            </div>
            <x-input id="company-search" name="search" type="search" wire:model.live.debounce.400ms="search"
                placeholder="{{ __('ui.common.search') }}" class="w-64" aria-label="{{ __('ui.common.search') }}" />
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
                    {{ $editingId === null ? __('ui.company.create') : __('ui.company.edit') }}
                </h2>
                <form wire:submit="save" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-input id="company-nama-ja" name="nama_ja" wire:model="formNamaJa" label="{{ __('ui.company.nama_ja') }}" required />
                    <x-input id="company-nama-romaji" name="nama_romaji" wire:model="formNamaRomaji" label="{{ __('ui.company.nama_romaji') }}" />
                    <x-input id="company-nama-id" name="nama_id" wire:model="formNamaId" label="{{ __('ui.company.nama_id') }}" />
                    <x-select id="company-negara" name="negara_id" wire:model="formNegaraId"
                        label="{{ __('ui.company.negara_id') }}" :options="$negaraOptions" placeholder="{{ __('ui.company.negara_default_hint') }}" />
                    <x-select id="company-industri" name="bidang_industri_id" wire:model="formBidangIndustriId"
                        label="{{ __('ui.company.bidang_industri_id') }}" :options="$industriOptions" placeholder="{{ __('ui.company.no_industri') }}" />
                    <x-textarea id="company-alamat" name="alamat" rows="3" wire:model="formAlamat"
                        label="{{ __('ui.company.alamat') }}" class="md:col-span-2" />
                    <div class="flex gap-2 md:col-span-2">
                        <x-button type="button" variant="secondary" wire:click="cancelForm">{{ __('ui.common.cancel') }}</x-button>
                        <x-button type="submit">{{ __('ui.common.save') }}</x-button>
                    </div>
                </form>
            </div>
        @endif

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.company.nama_ja') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.company.nama_romaji') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.company.nama_id') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.company.negara_id') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.lookup.status') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($companies as $company)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $company->nama_ja }}</td>
                            <td class="px-4 py-2.5 text-zinc-600">{{ $company->nama_romaji ?: '-' }}</td>
                            <td class="px-4 py-2.5 text-zinc-600">{{ $company->nama_id ?: '-' }}</td>
                            <td class="px-4 py-2.5 text-zinc-600">{{ $negaraLabels[$company->id] ?? '-' }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="$company->is_active ? 'success' : 'neutral'" :icon="$company->is_active ? 'check-circle' : 'dot'">
                                    {{ $company->is_active ? __('ui.lookup.active') : __('ui.lookup.inactive') }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1.5">
                                    <x-button size="sm" variant="ghost" wire:click="startEdit({{ $company->id }})">{{ __('ui.common.edit') }}</x-button>
                                    @if ($company->is_active)
                                        <x-button size="sm" variant="destructive" wire:click="toggleActive({{ $company->id }}, false)">{{ __('ui.lookup.disable') }}</x-button>
                                    @else
                                        <x-button size="sm" variant="secondary" wire:click="toggleActive({{ $company->id }}, true)">{{ __('ui.lookup.enable') }}</x-button>
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
                {{ $companies->links() }}
            </div>
        </div>

        <x-button size="sm" wire:click="startCreate" class="self-start">{{ __('ui.company.create') }}</x-button>
    </div>

    <livewire:step-up-modal />
</div>
