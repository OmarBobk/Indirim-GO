<?php

declare(strict_types=1);

namespace App\Support\AiAssistant;

final class AssistantLookupFormatter
{
    /**
     * @param  array{
     *     order_id: int,
     *     order_number: string,
     *     status: string,
     *     currency: string,
     *     subtotal: string,
     *     fee: string,
     *     total: string,
     *     paid_at: string|null,
     *     created_at: string,
     *     customer: array{id: int, username: string, name: string, email: string},
     *     items: list<array{id: int, name: string, quantity: int, unit_price: string, line_total: string, status: string}>,
     *     fulfillments: list<array{id: int, status: string, provider: string, claimed_by: int|null, completed_at: string|null}>,
     * }|null  $data
     */
    public static function order(?array $data, ?string $notFoundLabel = null): string
    {
        if ($data === null) {
            return $notFoundLabel ?? 'Order not found.';
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

        return implode("\n", $lines);
    }

    /**
     * @param  array{
     *     user: array{id: int, username: string, name: string, email: string},
     *     wallet: array{id: int, currency: string, balance: string}|null,
     *     recent_transactions: list<array{
     *         id: int,
     *         type: string,
     *         direction: string,
     *         amount: string,
     *         status: string,
     *         reference_type: string|null,
     *         reference_id: int|null,
     *         created_at: string,
     *     }>,
     * }|null  $data
     */
    public static function wallet(?array $data, ?string $notFoundLabel = null): string
    {
        if ($data === null) {
            return $notFoundLabel ?? 'User not found.';
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

        return implode("\n", $lines);
    }

    /**
     * @param  array{
     *     fulfillment_id: int,
     *     status: string,
     *     provider: string,
     *     attempts: int,
     *     order: array{id: int, order_number: string, status: string, customer_username: string|null},
     *     latest_automation_run: array{
     *         uuid: string,
     *         status: string,
     *         supplier_key: string,
     *         attempt: int,
     *         error_code: string|null,
     *         error_message: string|null,
     *         started_at: string|null,
     *         finished_at: string|null,
     *     }|null,
     *     recent_logs: list<array{id: int, level: string, message: string, created_at: string}>,
     * }|null  $data
     */
    public static function fulfillment(?array $data, ?string $notFoundLabel = null): string
    {
        if ($data === null) {
            return $notFoundLabel ?? 'Fulfillment not found.';
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

        return implode("\n", $lines);
    }
}
