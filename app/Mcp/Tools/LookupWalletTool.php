<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\AiAssistant\FetchWalletData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LookupWalletTool extends Tool
{
    protected string $description = 'Look up customer wallet by username or numeric user ID. Read-only.';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'username_or_id' => $schema->string()
                ->description('Customer username or numeric user ID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $usernameOrId = (string) $request->get('username_or_id');
        $data = app(FetchWalletData::class)->handle($usernameOrId);

        if ($data === null) {
            return Response::text('User not found: '.$usernameOrId);
        }

        $user = $data['user'];
        $wallet = $data['wallet'];

        $lines = [
            sprintf(
                'User: %s (#%d) — %s %s',
                $user['username'],
                $user['id'],
                $user['name'],
                $user['email'],
            ),
            '',
        ];

        if ($wallet === null) {
            $lines[] = 'Balance: 0.00 USD (no wallet record)';
        } else {
            $lines[] = sprintf('Wallet #%d (%s)', $wallet['id'], $wallet['currency']);
            $lines[] = '';
            $lines[] = sprintf('Balance: %s %s', $wallet['balance'], $wallet['currency']);
        }

        $lines[] = '';
        $lines[] = 'Recent posted transactions (newest first):';
        $lines[] = '';

        if ($data['recent_transactions'] === []) {
            $lines[] = 'No posted transactions on record.';
        } else {
            foreach ($data['recent_transactions'] as $transaction) {
                $currency = $wallet['currency'] ?? 'USD';
                $lines[] = sprintf(
                    '#%d %s %s %s %s (%s)',
                    $transaction['id'],
                    $transaction['type'],
                    $transaction['direction'],
                    $transaction['amount'],
                    $currency,
                    $transaction['created_at'],
                );
            }
        }

        return Response::text(implode("\n", $lines));
    }
}
