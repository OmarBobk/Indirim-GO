import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
import {
    bindEchoReconnect,
    handleDomainInvalidated,
    handleNotificationReceived,
    initCustomerActivityInvalidation,
} from './customer-activity-invalidation';
import {
    bindFinancialEchoReconnect,
    handleFinancialStateChanged,
    initCustomerFinancialInvalidation,
} from './customer-financial-invalidation';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

initCustomerActivityInvalidation();
initCustomerFinancialInvalidation();

if (import.meta.env.VITE_REVERB_APP_KEY && window.Laravel?.userId) {
    bindEchoReconnect(window.Echo);
    bindFinancialEchoReconnect(window.Echo);

    window.Echo.private('App.Models.User.' + window.Laravel.userId)
        .notification((payload) => {
            handleNotificationReceived(payload ?? {});
        })
        .listen('.CustomerActivityInvalidated', (payload) => {
            handleDomainInvalidated(payload ?? {});
        })
        .listen('.CustomerFinancialStateChanged', (payload) => {
            handleFinancialStateChanged(payload ?? {});
        });
}

if (import.meta.env.VITE_REVERB_APP_KEY && window.Laravel?.canViewDashboard) {
    window.Echo.private('admin.ops-dashboard').listen('.AdminOpsInboxChanged', (payload) => {
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('admin-ops-inbox-updated', payload ?? {});
            return;
        }

        window.dispatchEvent(new CustomEvent('admin-ops-inbox-updated', { detail: payload || {} }));
    });
}

if (import.meta.env.VITE_REVERB_APP_KEY && window.Laravel?.canViewFulfillments) {
    window.Echo.private('admin.fulfillments').listen('.FulfillmentListChanged', (payload) => {
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('fulfillment-list-updated', payload ?? {});
            return;
        }

        window.dispatchEvent(new CustomEvent('fulfillment-list-updated', { detail: payload || {} }));
    });
}

if (import.meta.env.VITE_REVERB_APP_KEY && window.Laravel?.isAdmin) {
    window.Echo.private('admin.automation').listen('.AutomationRunChanged', (payload) => {
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('automation-run-updated', payload ?? {});
            return;
        }

        window.dispatchEvent(new CustomEvent('automation-run-updated', { detail: payload || {} }));
    });

    window.Echo.private('admin.topups').listen('.TopupRequestsChanged', (payload) => {
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('topup-list-updated', payload ?? {});
            return;
        }

        window.dispatchEvent(new CustomEvent('topup-list-updated', { detail: payload || {} }));
    });

    window.Echo.private('admin.activities').listen('.ActivityLogChanged', (payload) => {
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('activity-list-updated', payload ?? {});
            return;
        }

        window.dispatchEvent(new CustomEvent('activity-list-updated', { detail: payload || {} }));
    });

    window.Echo.private('admin.system-events').listen('.SystemEventCreated', (payload) => {
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('system-event-created', payload ?? {});
            return;
        }

        window.dispatchEvent(new CustomEvent('system-event-created', { detail: payload || {} }));
    });
}

if (import.meta.env.VITE_REVERB_APP_KEY && window.Laravel?.canManageBugs) {
    window.Echo.private('admin.bugs').listen('.BugInboxChanged', (payload) => {
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('bug-inbox-updated', payload ?? {});
            return;
        }

        window.dispatchEvent(new CustomEvent('bug-inbox-updated', { detail: payload || {} }));
    });
}
