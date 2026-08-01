const Kakehashi = {
    csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    },

    async postJson(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': this.csrf(),
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        });

        let body = null;
        try {
            body = await response.json();
        } catch {
            // non-JSON response
        }

        return { ok: response.ok, status: response.status, body };
    },

    showAlert(element, message) {
        if (!element) return;
        element.textContent = message;
        element.classList.remove('hidden');
    },

    setBusy(form, busy) {
        const button = form?.querySelector('[type="submit"]');
        if (!button) return;
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        button.querySelector('.spinner')?.classList.toggle('hidden', !busy);
    },

    run(init) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => init());
        } else {
            init();
        }
    },
};

function initLoginForm() {
    const form = document.getElementById('login-form');
    if (!form) return;

    const alert = document.getElementById('login-error');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        Kakehashi.showAlert(alert, '');
        Kakehashi.setBusy(form, true);

        const data = Object.fromEntries(new FormData(form).entries());

        try {
            const result = await Kakehashi.postJson(form.action, data);

            if (result.ok && result.body?.message === 'LOGIN_SUCCESS') {
                window.location.href = result.body.must_change_password ? '/password/forced' : '/home';
                return;
            }

            if (result.ok && result.body?.message === 'TWOFA_REQUIRED') {
                window.location.href = '/two-factor/challenge';
                return;
            }

            if (result.status === 403 && result.body?.message === 'LOGIN_INACTIVE') {
                Kakehashi.showAlert(alert, document.getElementById('login-error-inactive')?.dataset.message);
                return;
            }

            if (result.status === 429) {
                const retry = result.body?.retry_after ?? 900;
                window.location.href = `/lockout?retry=${encodeURIComponent(retry)}`;
                return;
            }

            Kakehashi.showAlert(alert, document.getElementById('login-error-invalid')?.dataset.message);
        } catch {
            Kakehashi.showAlert(alert, document.getElementById('login-error-generic')?.dataset.message);
        } finally {
            Kakehashi.setBusy(form, false);
        }
    });
}

function initChallengeForm() {
    const form = document.getElementById('challenge-form');
    if (!form) return;

    const alert = document.getElementById('challenge-error');
    const codeField = document.getElementById('challenge-code');
    const recoveryField = document.getElementById('challenge-recovery');
    const toggle = document.getElementById('challenge-toggle');

    if (toggle) {
        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            const useRecovery = recoveryField.classList.contains('hidden');
            recoveryField.classList.toggle('hidden', !useRecovery);
            codeField.classList.toggle('hidden', useRecovery);
            toggle.textContent = useRecovery
                ? document.getElementById('challenge-toggle-use-code')?.dataset.message
                : document.getElementById('challenge-toggle-use-recovery')?.dataset.message;
        });
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        Kakehashi.showAlert(alert, '');
        Kakehashi.setBusy(form, true);

        const useRecovery = !recoveryField.classList.contains('hidden');
        const payload = {
            code: useRecovery ? null : codeField.querySelector('input').value,
            recovery_code: useRecovery ? recoveryField.querySelector('input').value : null,
        };

        try {
            const result = await Kakehashi.postJson(form.action, payload);

            if (result.ok && result.body?.message === 'LOGIN_SUCCESS') {
                window.location.href = result.body.must_change_password ? '/password/forced' : '/home';
                return;
            }

            if (result.status === 403) {
                window.location.href = '/login';
                return;
            }

            if (result.status === 429) {
                const retry = result.body?.retry_after ?? 900;
                window.location.href = `/lockout?retry=${encodeURIComponent(retry)}`;
                return;
            }

            Kakehashi.showAlert(alert, document.getElementById('challenge-error-invalid')?.dataset.message);
        } catch {
            Kakehashi.showAlert(alert, document.getElementById('challenge-error-invalid')?.dataset.message);
        } finally {
            Kakehashi.setBusy(form, false);
        }
    });
}

function initPasswordChangeForm() {
    const form = document.getElementById('password-change-form');
    if (!form) return;

    const alert = document.getElementById('password-change-error');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        Kakehashi.showAlert(alert, '');
        Kakehashi.setBusy(form, true);

        const data = Object.fromEntries(new FormData(form).entries());

        try {
            const result = await Kakehashi.postJson(form.action, data);

            if (result.ok && result.body?.message === 'PASSWORD_CHANGED') {
                window.location.href = '/home';
                return;
            }

            const code = result.body?.errors?.current_password?.[0]
                ?? result.body?.errors?.password?.[0]
                ?? result.body?.message
                ?? null;

            Kakehashi.showAlert(
                alert,
                code === 'PWD_CURRENT_INVALID'
                    ? document.getElementById('password-change-error-current')?.dataset.message
                    : document.getElementById('password-change-error-generic')?.dataset.message,
            );
        } catch {
            Kakehashi.showAlert(alert, document.getElementById('password-change-error-generic')?.dataset.message);
        } finally {
            Kakehashi.setBusy(form, false);
        }
    });
}

function initEnrollmentPage() {
    const page = document.getElementById('enroll-page');
    if (!page) return;

    const alert = document.getElementById('enroll-error');
    const loading = document.getElementById('enroll-loading');
    const stage = document.getElementById('enroll-stage');
    const confirmForm = document.getElementById('enroll-confirm-form');
    const recoveryBlock = document.getElementById('enroll-recovery');
    const alreadyBlock = document.getElementById('enroll-already');
    const genericMessage = document.getElementById('enroll-error-generic')?.dataset.message;

    const setLoading = (busy) => {
        loading?.classList.toggle('hidden', !busy);
        stage?.classList.toggle('hidden', busy);
    };

    const showError = (message) => Kakehashi.showAlert(alert, message);

    const load = async () => {
        setLoading(true);

        try {
            const enabled = await Kakehashi.postJson('/user/two-factor-authentication', {});

            if (enabled.status === 403 || enabled.status === 422) {
                if (enabled.body?.message === 'TWOFA_DISABLE_FORBIDDEN' || enabled.body?.message === 'TWOFA_ALREADY_ENABLED') {
                    alreadyBlock?.classList.remove('hidden');
                    stage?.classList.add('hidden');
                    setLoading(false);
                    return;
                }
            }

            const qr = await fetch('/user/two-factor-qr-code', {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const qrBody = await qr.json();

            if (qr.status === 422 && qrBody?.message === 'TWOFA_NOT_PENDING') {
                alreadyBlock?.classList.remove('hidden');
                stage?.classList.add('hidden');
                setLoading(false);
                return;
            }

            const secret = await fetch('/user/two-factor-secret-key', {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const secretBody = await secret.json();

            const qrContainer = document.getElementById('enroll-qr');
            if (qrContainer && qrBody?.svg) {
                qrContainer.innerHTML = qrBody.svg;
            }

            const secretField = document.getElementById('enroll-secret');
            if (secretField && secretBody?.secret) {
                secretField.textContent = secretBody.secret;
            }
        } catch {
            showError(genericMessage);
        } finally {
            setLoading(false);
        }
    };

    confirmForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        Kakehashi.showAlert(alert, '');
        Kakehashi.setBusy(confirmForm, true);

        const data = Object.fromEntries(new FormData(confirmForm).entries());

        try {
            const result = await Kakehashi.postJson('/user/confirmed-two-factor-authentication', data);

            if (result.ok && result.body?.message === 'TWOFA_CONFIRMED') {
                const codes = result.body?.recovery_codes ?? [];
                const list = document.getElementById('enroll-recovery-codes');
                if (list) {
                    list.innerHTML = '';
                    codes.forEach((code) => {
                        const li = document.createElement('li');
                        li.className = 'rounded-md bg-zinc-100 px-2 py-1 font-mono text-xs tabular-nums';
                        li.textContent = code;
                        list.appendChild(li);
                    });
                }
                confirmForm.classList.add('hidden');
                recoveryBlock?.classList.remove('hidden');
                return;
            }

            showError(document.getElementById('enroll-error-invalid')?.dataset.message);
        } catch {
            showError(genericMessage);
        } finally {
            Kakehashi.setBusy(confirmForm, false);
        }
    });

    load();
}

function initStepUpModal() {
    const form = document.getElementById('stepup-form');
    if (!form) return;

    const alert = document.getElementById('stepup-error');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        Kakehashi.showAlert(alert, '');
        Kakehashi.setBusy(form, true);

        const data = Object.fromEntries(new FormData(form).entries());
        const payload = {
            password: data.password,
            code: data.code,
            action: form.dataset.action,
            entity_type: form.dataset.entityType,
            entity_id: Number(form.dataset.entityId),
        };

        try {
            const result = await Kakehashi.postJson('/user/step-up', payload);

            if (result.ok && result.body?.message === 'STEPUP_OK') {
                Livewire.dispatch('stepup.success', {
                    action: payload.action,
                    entityType: payload.entity_type,
                    entityId: payload.entity_id,
                });
                Livewire.dispatch('stepup.close');
                return;
            }

            if (result.status === 403 || result.status === 422) {
                Kakehashi.showAlert(alert, document.getElementById('stepup-error-failed')?.dataset.message);
                return;
            }

            if (result.status === 429) {
                Kakehashi.showAlert(alert, document.getElementById('stepup-error-locked')?.dataset.message);
                return;
            }

            Kakehashi.showAlert(alert, document.getElementById('stepup-error-generic')?.dataset.message);
        } catch {
            Kakehashi.showAlert(alert, document.getElementById('stepup-error-generic')?.dataset.message);
        } finally {
            Kakehashi.setBusy(form, false);
        }
    });
}

function initLockoutCountdown() {
    const el = document.getElementById('lockout-countdown');
    if (!el) return;

    const retry = Math.max(0, Number(new URLSearchParams(window.location.search).get('retry')) || 0);
    if (retry === 0) {
        document.getElementById('lockout-back')?.classList.remove('hidden');
        return;
    }

    const endAt = Date.now() + retry * 1000;
    const message = el.dataset.template;

    const tick = () => {
        const remaining = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        el.textContent = message.replace(':time', `${minutes} menit ${seconds} detik`);
        if (remaining <= 0) {
            clearInterval(timer);
            document.getElementById('lockout-back')?.classList.remove('hidden');
        }
    };

    const timer = setInterval(tick, 1000);
    tick();
}

Kakehashi.run(initLoginForm);
Kakehashi.run(initChallengeForm);
Kakehashi.run(initPasswordChangeForm);
Kakehashi.run(initEnrollmentPage);
Kakehashi.run(initStepUpModal);
Kakehashi.run(initLockoutCountdown);
