const COALESCE_MS = 600;

/** @type {Record<string, unknown>|null} */
let pendingInvalidation = null;
/** @type {ReturnType<typeof setTimeout>|null} */
let coalesceTimer = null;

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
        reason: incoming.reason ?? existing.reason,
        eventId: incoming.eventId ?? existing.eventId,
        occurredAt: incoming.occurredAt ?? existing.occurredAt,
        isReconcile: Boolean(incoming.isReconcile || existing.isReconcile),
    };
}

/**
 * @param {Record<string, unknown>} detail
 */
function dispatchFinancialInvalidation(detail) {
    if (coalesceTimer) {
        clearTimeout(coalesceTimer);
    }

    pendingInvalidation = mergePending(pendingInvalidation, detail);

    coalesceTimer = setTimeout(() => {
        coalesceTimer = null;
        const payload = pendingInvalidation ?? {};
        pendingInvalidation = null;

        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('customer-financial-invalidate', payload);
        }

        window.dispatchEvent(new CustomEvent('customer-financial-invalidate', { detail: payload }));
    }, COALESCE_MS);
}

/**
 * @param {Record<string, unknown>|null|undefined} payload
 */
export function handleFinancialStateChanged(payload) {
    dispatchFinancialInvalidation({
        reason: payload?.reason ?? null,
        eventId: payload?.event_id ?? payload?.eventId ?? null,
        occurredAt: payload?.occurred_at ?? payload?.occurredAt ?? null,
        isReconcile: false,
    });
}

export function resetFinancialCoalescerForTests() {
    pendingInvalidation = null;

    if (coalesceTimer) {
        clearTimeout(coalesceTimer);
        coalesceTimer = null;
    }
}
