<?php

declare(strict_types=1);

use App\Livewire\Admin\AssistantChat;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'services.openai.key' => 'test-openai-key',
        'services.openai.base_url' => 'https://api.openai.com/v1',
        'services.openai.model' => 'gpt-4o-mini',
    ]);
});

it('appends assistant reply to messages on successful openai response', function (): void {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'The order is paid.'],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    $component = Livewire::actingAs(assistantAdminUser())
        ->test(AssistantChat::class)
        ->set('message', 'What is the status of ORD-TEST?')
        ->call('sendMessage')
        ->assertSet('error', '')
        ->assertSet('isLoading', false);

    $messages = $component->get('messages');
    $assistantMessages = array_values(array_filter(
        $messages,
        fn (array $message): bool => ($message['role'] ?? '') === 'assistant' && ($message['content'] ?? null) !== null
    ));

    expect($assistantMessages)->not->toBeEmpty();
    expect($assistantMessages[0]['content'])->toBe('The order is paid.');
});

it('executes tool call and makes second openai request', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => [
                                'name' => 'lookup_order',
                                'arguments' => '{"order_number":"ORD-TEST-001"}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Here is the order info.'],
                    'finish_reason' => 'stop',
                ]],
            ]),
    ]);

    $component = Livewire::actingAs(assistantAdminUser())
        ->test(AssistantChat::class)
        ->set('message', 'Look up ORD-TEST-001')
        ->call('sendMessage')
        ->assertSet('error', '')
        ->assertSet('isLoading', false);

    Http::assertSentCount(2);

    $messages = $component->get('messages');
    $contents = collect($messages)
        ->filter(fn (array $message): bool => ($message['role'] ?? '') === 'assistant' && ($message['content'] ?? null) !== null)
        ->pluck('content')
        ->all();

    expect($contents)->toContain('Here is the order info.');
});

it('shows quota exceeded message when openai returns insufficient quota', function (): void {
    Http::fake([
        '*/chat/completions' => Http::response([
            'error' => [
                'message' => 'You exceeded your current quota, please check your plan and billing details.',
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
            ],
        ], 429),
    ]);

    Livewire::actingAs(assistantAdminUser())
        ->test(AssistantChat::class)
        ->set('message', 'Hello')
        ->call('sendMessage')
        ->assertSet('error', __('messages.assistant_openai_quota_exceeded'))
        ->assertSet('isLoading', false);
});

it('restores chat history from session after remount', function (): void {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Order is paid.'],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    $admin = assistantAdminUser();

    Livewire::actingAs($admin)
        ->test(AssistantChat::class)
        ->set('message', 'Status of ORD-TEST?')
        ->call('sendMessage');

    Livewire::actingAs($admin)
        ->test(AssistantChat::class)
        ->assertSee('Status of ORD-TEST?')
        ->assertSee('Order is paid.');
});
