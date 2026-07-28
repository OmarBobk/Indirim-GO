const COALESCE_MS = 600;
const RECONCILE_HIDDEN_MS = 30_000;
const RECONCILE_THROTTLE_MS = 5_000;

/** @type {Record<string, unknown>|null} */
let pendingInvalidation = null;
/** @type {ReturnType<typeof setTimeout>|null} */
let coalesceTimer = null;
let lastHiddenAt = 0;
let lastReconcileAt = 0;
let reconcileInFlight = false;

/**
 * @param {Record<string, unknown>|null} existing
 * @param {Record<string, unknown>} incoming
 * @returns {Record<string, unknown>}
 */
function mergePending(existing, incoming) {
    if (!existing) {
        return { ...incoming };
    }

    return {
        source: incoming.source ?? existing.source,
        notificationId: incoming.notificationId ?? existing.notificationId,
        notificationType: incoming.notificationType ?? existing.notificationType,
        isReconcile: Boolean(incoming.isReconcile || existing.isReconcile),
        reason: incoming.reason ?? existing.reason,
    };
}

/**
 * @param {Record<string, unknown>} detail
 */
function dispatchInvalidation(detail) {
    if (coalesceTimer) {
        clearTimeout(coalesceTimer);
    }

    pendingInvalidation = mergePending(pendingInvalidation, detail);

    coalesceTimer = setTimeout(() => {
        coalesceTimer = null;
        const payload = pendingInvalidation ?? {};
        pendingInvalidation = null;

        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('customer-activity-invalidate', payload);
        }

        window.dispatchEvent(new CustomEvent('customer-activity-invalidate', { detail: payload }));
    }, COALESCE_MS);
}

/**
 * @param {Record<string, unknown>|null|undefined} payload
 */
export function handleNotificationReceived(payload) {
    // Observability only — does not trigger Activity invalidation by itself.
    window.dispatchEvent(new CustomEvent('notification-received', { detail: payload ?? {} }));

    dispatchInvalidation({
        source: 'notification',
        notificationId: payload?.id ?? null,
        notificationType: payload?.type ?? null,
        isReconcile: false,
    });
}

/**
 * @param {Record<string, unknown>|null|undefined} payload
 */
export function handleDomainInvalidated(payload) {
    dispatchInvalidation({
        source: 'domain',
        reason: payload?.reason ?? null,
        isReconcile: false,
    });
}

/**
 * @param {string} source
 */
export function scheduleReconciliation(source) {
    const now = Date.now();

    if (reconcileInFlight || now - lastReconcileAt < RECONCILE_THROTTLE_MS) {
        return;
    }

    reconcileInFlight = true;
    lastReconcileAt = now;

    dispatchInvalidation({
        source,
        isReconcile: true,
    });

    window.setTimeout(() => {
        reconcileInFlight = false;
    }, RECONCILE_THROTTLE_MS);
}

export function initCustomerActivityInvalidation() {
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            lastHiddenAt = Date.now();

            return;
        }

        if (
            document.visibilityState === 'visible'
            && lastHiddenAt > 0
            && Date.now() - lastHiddenAt >= RECONCILE_HIDDEN_MS
        ) {
            scheduleReconciliation('focus');
        }
    });

    window.addEventListener('online', () => scheduleReconciliation('online'));
}

/**
 * @param {import('laravel-echo').default} echo
 */
export function bindEchoReconnect(echo) {
    const connection = echo?.connector?.pusher?.connection;

    if (!connection || typeof connection.bind !== 'function') {
        return;
    }

    connection.bind('connected', () => {
        scheduleReconciliation('reconnect');
    });
}

export function resetCoalescerForTests() {
    pendingInvalidation = null;

    if (coalesceTimer) {
        clearTimeout(coalesceTimer);
        coalesceTimer = null;
    }

    lastHiddenAt = 0;
    lastReconcileAt = 0;
    reconcileInFlight = false;
}
