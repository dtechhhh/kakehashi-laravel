<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A6 — shared step-up re-auth modal (only the five final triggers).
 *
 * The modal itself only collects password + TOTP and posts to the existing
 * `step-up.store` JSON endpoint via JS. On success it dispatches
 * `stepup.success` so the hosting page can execute its staged mutation;
 * authorization and token consumption remain server-side (StepUpService).
 */
final class StepUpModal extends Component
{
    public bool $open = false;

    public string $action = '';

    public string $entityType = '';

    public int $entityId = 0;

    #[On('stepup.open')]
    public function open(string $action, string $entityType, int $entityId): void
    {
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->open = true;
    }

    #[On('stepup.close')]
    public function close(): void
    {
        $this->open = false;
    }

    public function render()
    {
        return view('livewire.step-up-modal');
    }
}
