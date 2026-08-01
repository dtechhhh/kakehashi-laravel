<div>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.admin.audit.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('ui.admin.audit.subtitle') }}</p>
        </div>

        <form wire:submit.prevent="resetFilters" class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm md:grid-cols-5">
            <x-select id="audit-action" name="action_type" wire:model.live="actionType"
                label="{{ __('ui.admin.audit.action_type') }}" :options="collect($actionTypes)->pluck('value', 'value')" placeholder="{{ __('ui.admin.audit.all') }}" />
            <x-input id="audit-entity" name="entity_type" wire:model.live.debounce.400ms="entityType"
                label="{{ __('ui.admin.audit.entity_type') }}" placeholder="{{ __('ui.admin.audit.entity_placeholder') }}" />
            <x-select id="audit-actor" name="actor_id" wire:model.live="actorId"
                label="{{ __('ui.admin.audit.actor') }}" :options="$actors->pluck('name', 'id')" placeholder="{{ __('ui.admin.audit.all') }}" />
            <x-input id="audit-from" name="date_from" type="date" wire:model.live="dateFrom" label="{{ __('ui.admin.audit.date_from') }}" />
            <x-input id="audit-to" name="date_to" type="date" wire:model.live="dateTo" label="{{ __('ui.admin.audit.date_to') }}" />
        </form>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.audit.time') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.audit.action_type') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.audit.entity') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.audit.actor') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.audit.detail') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($logs as $log)
                        <tr class="align-top hover:bg-zinc-50">
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-zinc-600">{{ $log->created_at?->format(__('ui.date_time_format')) }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge type="info" icon="dot">{{ $log->action_type?->value ?? $log->action_type }}</x-badge>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-zinc-600">{{ $log->entity_type }}#{{ $log->entity_id ?? '-' }}</td>
                            <td class="px-4 py-2.5 text-zinc-700">
                                <span class="font-medium text-zinc-900">{{ $log->actor?->name ?? '#' . $log->actor_id }}</span>
                                @if ($log->actor_role_snapshot)
                                    <span class="block text-xs text-zinc-500">{{ $log->actor_role_snapshot }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($log->detail)
                                    <pre class="max-w-md overflow-x-auto rounded-md bg-zinc-50 px-2 py-1 font-mono text-xs text-zinc-700">{{ json_encode($log->detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                @else
                                    <span class="text-xs text-zinc-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10">
                                <x-state type="empty" class="!border-0 !shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $logs->links() }}
            </div>
        </div>
    </div>
</div>
