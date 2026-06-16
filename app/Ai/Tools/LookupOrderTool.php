<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\AiAssistant\FetchOrderData;
use App\Support\AiAssistant\AssistantLookupFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class LookupOrderTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up a single order by exact order number. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $orderNumber = (string) $request->string('order_number');

        return AssistantLookupFormatter::order(
            app(FetchOrderData::class)->handle($orderNumber),
            'Order not found: '.$orderNumber,
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_number' => $schema->string()
                ->description('Exact order number, e.g. ORD-2026-000114')
                ->required(),
        ];
    }
}
