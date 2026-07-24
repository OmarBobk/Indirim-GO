<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\LookupFulfillmentTool;
use App\Ai\Tools\LookupOrderTool;
use App\Ai\Tools\LookupWalletTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(5)]
final class OpsAssistant implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return __('messages.assistant_system_intro');
    }

    public function model(): string
    {
        return (string) config('ai.models.text', 'gpt-4o-mini');
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new LookupOrderTool,
            new LookupWalletTool,
            new LookupFulfillmentTool,
        ];
    }
}
