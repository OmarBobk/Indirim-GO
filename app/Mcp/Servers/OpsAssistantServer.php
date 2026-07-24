<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class OpsAssistantServer extends Server
{
    protected string $name = 'Ops Assistant';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
# karman.store Ops Assistant (read-only)
You help authenticated **admin** staff look up operational data for İndirimGo / karman.store.
## You CAN
- Look up a single order by exact `order_number`.
- Look up a customer wallet by `username` or numeric `user_id`, including recent posted ledger rows.
- Look up a fulfillment by numeric `fulfillment_id`, including automation run and logs.
## You CANNOT
- Create, update, or delete any records.
- Recompute balances or totals — use stored DB values only.
- Access data without authentication.
## Financial warning
Wallet balance comes from `wallets.balance` only. Never infer from `system_events` or transaction sums.
## Tools
1. `lookup_order` — requires `order_number` (string).
2. `lookup_wallet` — requires `username_or_id` (string).
3. `lookup_fulfillment` — requires `fulfillment_id` (integer).
MARKDOWN;

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        \App\Mcp\Tools\LookupOrderTool::class,
        \App\Mcp\Tools\LookupWalletTool::class,
        \App\Mcp\Tools\LookupFulfillmentTool::class,
    ];

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [];

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [];
}
