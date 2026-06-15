<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\AiAssistant\FetchFulfillmentData;
use App\Actions\AiAssistant\FetchOrderData;
use App\Actions\AiAssistant\FetchWalletData;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

#[Layout('layouts.app')]
final class AssistantChat extends Component
{
    use Toastable;

    public string $message = '';

    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public bool $isLoading = false;

    public string $error = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $stored = session()->get($this->sessionStorageKey());

        if (is_array($stored) && $stored !== []) {
            $this->messages = $stored;
        } else {
            $this->messages = $this->defaultMessages();
        }

        if (empty(config('services.openai.key'))) {
            $this->error = __('messages.assistant_openai_not_configured');
        }
    }

    public function sendMessage(): void
    {
        $this->validate(['message' => ['required', 'string', 'max:2000']]);

        if (empty(config('services.openai.key'))) {
            $this->error = __('messages.assistant_openai_not_configured');

            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $this->message];
        $this->message = '';
        $this->error = '';
        $this->isLoading = true;

        try {
            $endpoint = rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/')
                .'/chat/completions';

            $tools = $this->toolDefinitions();

            $response = Http::timeout(60)
                ->withToken(config('services.openai.key'))
                ->post($endpoint, [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => $this->messages,
                    'tools' => $tools,
                ]);

            if ($response->failed()) {
                $this->error = $this->resolveOpenAiError($response);

                return;
            }

            $responseMessage = $response->json('choices.0.message');

            if (! empty($responseMessage['tool_calls'])) {
                $this->messages[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => $responseMessage['tool_calls'],
                ];

                foreach ($responseMessage['tool_calls'] as $toolCall) {
                    $args = json_decode($toolCall['function']['arguments'], true) ?? [];
                    $result = $this->executeToolCall($toolCall['function']['name'], $args);

                    $this->messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => $result,
                    ];
                }

                $second = Http::timeout(60)
                    ->withToken(config('services.openai.key'))
                    ->post($endpoint, [
                        'model' => config('services.openai.model', 'gpt-4o-mini'),
                        'messages' => $this->messages,
                        'tools' => $tools,
                    ]);

                if ($second->failed()) {
                    $this->error = $this->resolveOpenAiError($second);

                    return;
                }

                $finalContent = $second->json('choices.0.message.content', '');
            } else {
                $finalContent = $responseMessage['content'] ?? '';
            }

            $this->messages[] = ['role' => 'assistant', 'content' => $finalContent];
        } finally {
            $this->isLoading = false;
            $this->persistMessages();
        }
    }

    public function clearChat(): void
    {
        $this->messages = $this->defaultMessages();
        $this->message = '';
        $this->error = '';
        $this->isLoading = false;
        $this->persistMessages();
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function defaultMessages(): array
    {
        return [
            ['role' => 'system', 'content' => __('messages.assistant_system_intro')],
        ];
    }

    private function sessionStorageKey(): string
    {
        return 'ops_assistant.messages.'.auth()->id();
    }

    private function persistMessages(): void
    {
        session()->put($this->sessionStorageKey(), $this->messages);
    }

    private function resolveOpenAiError(Response $response): string
    {
        $apiCode = (string) $response->json('error.code', '');
        $apiType = (string) $response->json('error.type', '');
        $apiMessage = (string) $response->json('error.message', '');

        Log::warning('Ops assistant OpenAI request failed', [
            'status' => $response->status(),
            'code' => $apiCode !== '' ? $apiCode : $apiType,
            'message' => $apiMessage,
        ]);

        if ($response->status() === 429 || $apiCode === 'insufficient_quota' || $apiType === 'insufficient_quota') {
            return __('messages.assistant_openai_quota_exceeded');
        }

        if ($response->status() === 401 || $apiCode === 'invalid_api_key') {
            return __('messages.assistant_openai_invalid_key');
        }

        if ($response->status() === 404 || $apiCode === 'model_not_found') {
            return __('messages.assistant_openai_model_not_found', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
            ]);
        }

        if ($response->status() === 403 && str_contains(strtolower($apiMessage), 'model')) {
            return __('messages.assistant_openai_model_not_found', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
            ]);
        }

        return __('messages.assistant_openai_error');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function executeToolCall(string $name, array $args): string
    {
        return match ($name) {
            'lookup_order' => $this->formatOrder(
                app(FetchOrderData::class)->handle((string) ($args['order_number'] ?? ''))
            ),
            'lookup_wallet' => $this->formatWallet(
                app(FetchWalletData::class)->handle((string) ($args['username_or_id'] ?? ''))
            ),
            'lookup_fulfillment' => $this->formatFulfillment(
                app(FetchFulfillmentData::class)->handle((int) ($args['fulfillment_id'] ?? 0))
            ),
            default => 'Unknown tool: '.$name,
        };
    }

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
    private function formatOrder(?array $data): string
    {
        if ($data === null) {
            return 'Order not found.';
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
    private function formatWallet(?array $data): string
    {
        if ($data === null) {
            return 'User not found.';
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
    private function formatFulfillment(?array $data): string
    {
        if ($data === null) {
            return 'Fulfillment not found.';
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

    /**
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_order',
                    'description' => 'Look up a single order by exact order number. Read-only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_number' => [
                                'type' => 'string',
                                'description' => 'Exact order number, e.g. ORD-2026-000114',
                            ],
                        ],
                        'required' => ['order_number'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_wallet',
                    'description' => 'Look up customer wallet by username or numeric user ID. Read-only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'username_or_id' => [
                                'type' => 'string',
                                'description' => 'Customer username or numeric user ID',
                            ],
                        ],
                        'required' => ['username_or_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_fulfillment',
                    'description' => 'Look up fulfillment by numeric ID with automation run and logs. Read-only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'fulfillment_id' => [
                                'type' => 'integer',
                                'description' => 'Numeric fulfillment primary key',
                            ],
                        ],
                        'required' => ['fulfillment_id'],
                    ],
                ],
            ],
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.admin.assistant-chat');
    }
}
