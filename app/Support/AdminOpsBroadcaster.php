<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\AdminOpsInboxChanged;
use Illuminate\Support\Facades\DB;

class AdminOpsBroadcaster
{
    public static function dispatch(?string $reason = null): void
    {
        DB::afterCommit(static function () use ($reason): void {
            event(new AdminOpsInboxChanged($reason));
        });
    }
}
