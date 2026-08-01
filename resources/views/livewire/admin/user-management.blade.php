@php use Modules\Auth\Rbac; @endphp

<div>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-zinc-900">{{ __('ui.admin.users.title') }}</h1>
                <p class="mt-1 text-sm text-zinc-600">{{ __('ui.admin.users.subtitle') }}</p>
            </div>
            <x-input id="user-search" name="search" wire:model.live.debounce.400ms="search" type="search"
                placeholder="{{ __('ui.common.search') }}" class="w-64" aria-label="{{ __('ui.common.search') }}" />
        </div>

        @if ($actionError)
            <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
        @endif
        @if ($actionSuccess)
            <x-alert type="success" wire:key="success">{{ $actionSuccess }}</x-alert>
        @endif

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-100 text-xs uppercase text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.users.name') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.common.email') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.users.roles') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.users.two_factor') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ __('ui.admin.users.status') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __('ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($users as $user)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-4 py-2.5 font-medium text-zinc-900">{{ $user->name }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-zinc-600">{{ $user->email }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($user->roles as $role)
                                        <x-badge type="{{ $role->name === Rbac::SUPER_ADMIN ? 'info' : 'neutral' }}" icon="dot">{{ $role->name }}</x-badge>
                                    @empty
                                        <span class="text-xs text-zinc-400">{{ __('ui.admin.users.no_roles') }}</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="$user->hasEnabledTwoFactorAuthentication() ? 'success' : 'warning'"
                                    :icon="$user->hasEnabledTwoFactorAuthentication() ? 'check-circle' : 'clock'">
                                    {{ $user->hasEnabledTwoFactorAuthentication() ? __('ui.admin.users.two_factor_enabled') : __('ui.admin.users.two_factor_disabled') }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge :type="$user->status_akun === 'Aktif' ? 'success' : 'danger'"
                                    :icon="$user->status_akun === 'Aktif' ? 'check-circle' : 'x-circle'">
                                    {{ $user->status_akun === 'Aktif' ? __('ui.admin.users.active') : __('ui.admin.users.inactive') }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($editingRolesFor === $user->id)
                                    <div class="flex flex-col items-end gap-2">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @foreach ($roles as $role)
                                                <label class="flex items-center gap-1.5 rounded-md border border-zinc-200 px-2 py-1 text-xs">
                                                    <input type="checkbox" value="{{ $role }}"
                                                        wire:model.live="roleDrafts.{{ $user->id }}"
                                                        class="h-3.5 w-3.5 rounded border-zinc-300 text-zinc-900 focus-visible:outline-2 focus-visible:outline-blue-600">
                                                    {{ $role }}
                                                </label>
                                            @endforeach
                                        </div>
                                        <div class="flex gap-2">
                                            <x-button size="sm" variant="secondary" wire:click="cancelEditRoles">{{ __('ui.common.cancel') }}</x-button>
                                            <x-button size="sm" wire:click="saveRoles({{ $user->id }})">{{ __('ui.common.save') }}</x-button>
                                        </div>
                                    </div>
                                @else
                                    <div class="flex flex-wrap justify-end gap-1.5">
                                        <x-button size="sm" variant="ghost" wire:click="startEditRoles({{ $user->id }})">{{ __('ui.admin.users.edit_roles') }}</x-button>
                                        @if ($user->status_akun === 'Aktif')
                                            <x-button size="sm" variant="destructive" wire:click="deactivate({{ $user->id }})">{{ __('ui.admin.users.deactivate') }}</x-button>
                                        @else
                                            <x-button size="sm" variant="secondary" wire:click="reactivate({{ $user->id }})">{{ __('ui.admin.users.reactivate') }}</x-button>
                                        @endif
                                        <x-button size="sm" variant="ghost" wire:click="resetPassword({{ $user->id }})">{{ __('ui.admin.users.reset_password') }}</x-button>
                                    </div>
                                @endif
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
                {{ $users->links() }}
            </div>
        </div>

        @if ($resettingPasswordFor !== null)
            <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-semibold text-zinc-900">{{ __('ui.admin.users.reset_password_for') }}</h2>
                <form wire:submit="confirmResetPassword({{ $resettingPasswordFor }})" class="mt-3 flex flex-col gap-3">
                    <x-input id="temporary-password" name="temporary_password" type="text"
                        label="{{ __('ui.admin.users.temporary_password') }}" wire:model="temporaryPassword" required
                        hint="{{ __('ui.auth.password_forced.policy') }}" />
                    <p class="text-xs text-zinc-500">{{ __('ui.admin.users.reset_password_note') }}</p>
                    <div class="flex gap-2">
                        <x-button type="button" variant="secondary" wire:click="$set('resettingPasswordFor', null)">{{ __('ui.common.cancel') }}</x-button>
                        <x-button type="submit">{{ __('ui.common.confirm') }}</x-button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    <livewire:step-up-modal />
</div>
