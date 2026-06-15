<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\AiAssistant\FetchFulfillmentData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LookupFulfillmentTool extends Tool
{
    protected string $description = 'Look up fulfillment by numeric ID with automation run and logs. Read-only.';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fulfillment_id' => $schema->integer()
                ->description('Numeric fulfillment primary key')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $fulfillmentId = (int) $request->get('fulfillment_id');
        $data = app(FetchFulfillmentData::class)->handle($fulfillmentId);

        if ($data === null) {
            return Response::text('Fulfillment not found: #'.$fulfillmentId);
        }

        $order = $data['order'];
        $customerUsername = $order['customer_username'] ?? 'unknown';

        $lines = [
            sprintf('Fulfillment #%d — status: %s', $data['fulfillment_id'], $data['status']),
            '',
            sprintf(
                'Order: %s (%s) | Customer: %s',
                $order['order_number'],
                $order['status'],
                $customerUsername,
            ),
            '',
            sprintf('Provider: %s | Attempts: %d', $data['provider'], $data['attempts']),
            '',
        ];

        $run = $data['latest_automation_run'];

        if ($run === null) {
            $lines[] = 'Latest automation run: none';
        } else {
            $lines[] = 'Latest automation run:';
            $lines[] = '';
            $lines[] = sprintf(
                'uuid: %s | status: %s | supplier: %s | attempt: %d',
                $run['uuid'],
                $run['status'],
                $run['supplier_key'],
                $run['attempt'],
            );

            if ($run['error_code'] !== null || $run['error_message'] !== null) {
                $lines[] = sprintf(
                    'error: %s — %s',
                    $run['error_code'] ?? '',
                    $run['error_message'] ?? '',
                );
            }

            $lines[] = sprintf(
                'started: %s | finished: %s',
                $run['started_at'] ?? '—',
                $run['finished_at'] ?? '—',
            );
        }

        $lines[] = '';
        $lines[] = 'Recent logs:';
        $lines[] = '';

        if ($data['recent_logs'] === []) {
            $lines[] = '(none)';
        } else {
            foreach ($data['recent_logs'] as $log) {
                $lines[] = sprintf('[%s] %s', $log['level'], $log['message']);
            }
        }

        return Response::text(implode("\n", $lines));
    }
}
