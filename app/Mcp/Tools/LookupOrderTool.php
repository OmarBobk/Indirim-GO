<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\AiAssistant\FetchOrderData;
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
        $data = app(FetchOrderData::class)->handle($orderNumber);

        if ($data === null) {
            return Response::text('Order not found: '.$orderNumber);
        }

        $customer = $data['customer'];
        $paidAt = $data['paid_at'] ?? '—';

        $lines = [
            sprintf('Order: %s (#%d)', $data['order_number'], $data['order_id']),
            '',
            sprintf('Status: %s', $data['status']),
            '',
            sprintf(
                'Customer: %s (%s) [user_id=%d]',
                $customer['username'],
                $customer['email'],
                $customer['id'],
            ),
            '',
            sprintf('Created: %s | Paid: %s', $data['created_at'], $paidAt),
            '',
            sprintf(
                'Totals (%s): subtotal=%s fee=%s total=%s',
                $data['currency'],
                $data['subtotal'],
                $data['fee'],
                $data['total'],
            ),
            '',
            'Items:',
            '',
        ];

        foreach ($data['items'] as $item) {
            $lines[] = sprintf(
                '#%d %s x%d @ %s = %s [%s]',
                $item['id'],
                $item['name'],
                $item['quantity'],
                $item['unit_price'],
                $item['line_total'],
                $item['status'],
            );
        }

        $lines[] = '';
        $lines[] = 'Fulfillments:';

        foreach ($data['fulfillments'] as $fulfillment) {
            $lines[] = sprintf(
                '#%d %s (provider: %s)',
                $fulfillment['id'],
                $fulfillment['status'],
                $fulfillment['provider'],
            );
        }

        return Response::text(implode("\n", $lines));
    }
}
