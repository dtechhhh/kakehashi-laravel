<div wire:keydown.esc="close">
    @if ($open)
        <div class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-labelledby="stepup-title">
            <div class="fixed inset-0 bg-zinc-900/50" wire:click="close" aria-hidden="true"></div>
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-md rounded-lg border border-zinc-200 bg-white shadow-md">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3">
                        <h2 id="stepup-title" class="text-base font-semibold text-zinc-900">{{ __('ui.stepup.title') }}</h2>
                        <button type="button" wire:click="close"
                            class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                            aria-label="{{ __('ui.common.close') }}">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                    <div class="px-4 py-4">
                        <p class="text-sm text-zinc-600">{{ __('ui.stepup.subtitle') }}</p>
                        <form id="stepup-form"
                            data-action="{{ $action }}"
                            data-entity-type="{{ $entityType }}"
                            data-entity-id="{{ $entityId }}"
                            class="mt-4 flex flex-col gap-4" novalidate>
                            <x-input id="stepup-password" name="password" type="password" label="{{ __('ui.common.password') }}" required autocomplete="current-password" />
                            <x-input id="stepup-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                                label="{{ __('ui.stepup.code_label') }}" required autocomplete="one-time-code" />
                            <p id="stepup-error" role="alert" class="hidden rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-text"></p>
                            <p class="text-xs text-zinc-500">{{ __('ui.stepup.ttl_note') }}</p>
                            <div class="flex justify-end gap-2">
                                <x-button type="button" variant="secondary" wire:click="close">{{ __('ui.common.cancel') }}</x-button>
                                <x-button type="submit">
                                    <svg class="spinner hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                    {{ __('ui.stepup.confirm') }}
                                </x-button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
    <p id="stepup-error-failed" class="hidden" data-message="{{ __('ui.stepup.error_failed') }}"></p>
    <p id="stepup-error-locked" class="hidden" data-message="{{ __('ui.stepup.error_locked') }}"></p>
    <p id="stepup-error-generic" class="hidden" data-message="{{ __('ui.stepup.error_generic') }}"></p>
</div>
