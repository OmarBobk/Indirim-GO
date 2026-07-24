<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\AiAssistant\FetchOrderData;
use App\Support\AiAssistant\AssistantLookupFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LookupOrderTool extends Tool
{
    protected string $description = 'Look up a single order by exact order number. Read-only.';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_number' => $schema->string()
                ->description('Exact order number, e.g. ORD-2026-000114')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $orderNumber = (string) $request->get('order_number');

        return Response::text(AssistantLookupFormatter::order(
            app(FetchOrderData::class)->handle($orderNumber),
            'Order not found: '.$orderNumber,
        ));
    }
}
