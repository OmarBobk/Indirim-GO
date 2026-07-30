const COALESCE_MS = 600;
const RECONCILE_HIDDEN_MS = 30_000;
const RECONCILE_THROTTLE_MS = 5_000;
const ALLOWED_REASONS = new Set([
    'balance_changed',
    'transaction_posted',
    'credit_facility_changed',
    'topup_state_changed',
    'refund_state_changed',
    'commission_state_changed',
    'payout_request_state_changed',
]);

/** @type {Set<string>} */
let pendingReasons = new Set();
/** @type {ReturnType<typeof setTimeout>|null} */
let coalesceTimer = null;
let pendingReconcile = false;
let lastHiddenAt = 0;
let lastReconcileAt = 0;
let initialized = false;

/**
 * @param {unknown} reasons
 * @returns {string[]}
 */
function allowlistedReasons(reasons) {
    if (!Array.isArray(reasons)) {
        return [];
    }

    return [...new Set(reasons.filter((reason) => (
        typeof reason === 'string' && ALLOWED_REASONS.has(reason)
    )))];
}

/**
 * @param {{ reasons: string[], isReconcile: boolean }} payload
 */
function announceFinancialUpdate(payload) {
    if (document.visibilityState === 'hidden' || !document.querySelector('[data-financial-surface]')) {
        return;
    }

    const region = document.getElementById('financial-realtime-live-region');
    const messages = window.Laravel?.financialRealtimeMessages ?? {};

    if (!region) {
        return;
    }

    let message = messages.updated ?? '';

    if (payload.isReconcile) {
        message = messages.reconnected ?? message;
    } else if (payload.reasons.includes('topup_state_changed')) {
        message = messages.topup ?? message;
    } else if (payload.reasons.includes('refund_state_changed')) {
        message = messages.refund ?? message;
    } else if (
        payload.reasons.includes('commission_state_changed')
        || payload.reasons.includes('payout_request_state_changed')
    ) {
        message = messages.earnings ?? message;
    } else if (payload.reasons.includes('transaction_posted')) {
        message = messages.transactions ?? message;
    }

    region.textContent = '';
    window.requestAnimationFrame(() => {
        region.textContent = message;
    });
}

/**
 * @param {{ reasons?: unknown, isReconcile?: boolean }} detail
 */
function dispatchFinancialInvalidation(detail) {
    for (const reason of allowlistedReasons(detail.reasons)) {
        pendingReasons.add(reason);
    }

    pendingReconcile = pendingReconcile || detail.isReconcile === true;

    if (pendingReasons.size === 0 && !pendingReconcile) {
        return;
    }

    if (coalesceTimer) {
        clearTimeout(coalesceTimer);
    }

    coalesceTimer = setTimeout(() => {
        coalesceTimer = null;

        if (document.visibilityState === 'hidden') {
            return;
        }

        const payload = {
            reasons: [...pendingReasons],
            isReconcile: pendingReconcile,
        };
        pendingReasons = new Set();
        pendingReconcile = false;

        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('customer-financial-invalidate', payload);
        }

        window.dispatchEvent(new CustomEvent('customer-financial-invalidate', { detail: payload }));
        announceFinancialUpdate(payload);
    }, COALESCE_MS);
}

/**
 * @param {Record<string, unknown>|null|undefined} payload
 */
export function handleFinancialStateChanged(payload) {
    dispatchFinancialInvalidation({
        reasons: payload?.reasons ?? (payload?.reason ? [payload.reason] : []),
        isReconcile: false,
    });
}

/**
 * Lifecycle reconciliation is bounded and carries no financial values.
 *
 * @param {string} source
 */
export function scheduleFinancialReconciliation(source) {
    const now = Date.now();

    if (now - lastReconcileAt < RECONCILE_THROTTLE_MS) {
        return;
    }

    lastReconcileAt = now;
    dispatchFinancialInvalidation({
        reasons: [],
        isReconcile: true,
        source,
    });
}

export function initCustomerFinancialInvalidation() {
    if (initialized) {
        return;
    }

    initialized = true;

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            lastHiddenAt = Date.now();

            return;
        }

        const wasHiddenLongEnough = lastHiddenAt > 0
            && Date.now() - lastHiddenAt >= RECONCILE_HIDDEN_MS;

        if (pendingReasons.size > 0 || pendingReconcile) {
            dispatchFinancialInvalidation({
                reasons: [],
                isReconcile: wasHiddenLongEnough,
            });

            return;
        }

        if (wasHiddenLongEnough) {
            scheduleFinancialReconciliation('visibility');
        }
    });

    window.addEventListener('focus', () => scheduleFinancialReconciliation('focus'));
    window.addEventListener('offline', () => {
        if (!document.querySelector('[data-financial-surface]')) {
            return;
        }

        const region = document.getElementById('financial-realtime-live-region');
        if (region) {
            region.textContent = window.Laravel?.financialRealtimeMessages?.offline ?? '';
        }
    });
    window.addEventListener('online', () => scheduleFinancialReconciliation('online'));
}

/**
 * @param {import('laravel-echo').default} echo
 */
export function bindFinancialEchoReconnect(echo) {
    const connection = echo?.connector?.pusher?.connection;

    if (!connection || typeof connection.bind !== 'function' || connection.__financialReconnectBound) {
        return;
    }

    connection.__financialReconnectBound = true;
    connection.bind('connected', () => scheduleFinancialReconciliation('reconnect'));
}

export function resetFinancialCoalescerForTests() {
    pendingReasons = new Set();
    pendingReconcile = false;
    lastHiddenAt = 0;
    lastReconcileAt = 0;

    if (coalesceTimer) {
        clearTimeout(coalesceTimer);
        coalesceTimer = null;
    }
}
