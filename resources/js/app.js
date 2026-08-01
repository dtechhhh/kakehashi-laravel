function closeModal(name) {
    const modal = document.querySelector(`[data-modal="${name}"]`);
    if (!modal) return;
    modal.classList.add('hidden');
    document.querySelector(`[data-modal-open="${name}"]`)?.focus();
}

function openModal(name) {
    const modal = document.querySelector(`[data-modal="${name}"]`);
    if (!modal) return;
    modal.classList.remove('hidden');
    const firstFocusable = modal.querySelector('input, select, textarea, button, [href], [tabindex]:not([tabindex="-1"])');
    firstFocusable?.focus();
}

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-modal-open]');
    if (opener) {
        openModal(opener.dataset.modalOpen);
        return;
    }

    const closer = event.target.closest('[data-modal-close]');
    if (closer) {
        closeModal(closer.dataset.modalClose);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('[data-modal]:not(.hidden)').forEach((modal) => {
            closeModal(modal.dataset.modal);
        });
    }

    const openModal = document.querySelector('[data-modal]:not(.hidden)');
    if (!openModal || event.key !== 'Tab') return;

    const focusables = openModal.querySelectorAll('input, select, textarea, button, [href], [tabindex]:not([tabindex="-1"])');
    if (focusables.length === 0) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
});

document.addEventListener('livewire:navigated', () => {});
