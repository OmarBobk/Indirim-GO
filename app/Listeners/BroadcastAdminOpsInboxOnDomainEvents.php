<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AutomationRunChanged;
use App\Events\BugInboxChanged;
use App\Events\FulfillmentListChanged;
use App\Events\TopupRequestsChanged;
use App\Support\AdminOpsBroadcaster;

class BroadcastAdminOpsInboxOnDomainEvents
{
    public function handleFulfillmentListChanged(FulfillmentListChanged $event): void
    {
        AdminOpsBroadcaster::dispatch('fulfillment:'.$event->type);
    }

    public function handleTopupRequestsChanged(TopupRequestsChanged $event): void
    {
        AdminOpsBroadcaster::dispatch('topup:'.$event->reason);
    }

    public function handleBugInboxChanged(BugInboxChanged $event): void
    {
        AdminOpsBroadcaster::dispatch('bug:'.$event->reason);
    }

    public function handleAutomationRunChanged(AutomationRunChanged $event): void
    {
        AdminOpsBroadcaster::dispatch('automation:'.$event->type);
    }
}
