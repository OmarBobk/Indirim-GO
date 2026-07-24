<?php

declare(strict_types=1);

use App\Ai\Agents\OpsAssistant;
use App\Livewire\Admin\AssistantChat;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Responses\Data\ToolCall;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'ai.providers.openai.key' => 'test-openai-key',
        'ai.models.text' => 'gpt-4o-mini',
        'ai.conversations.generate_title' => false,
    ]);
});

it('appends assistant reply to messages on successful agent response', function (): void {
    OpsAssistant::fake(['The order is paid.']);

    $component = Livewire::actingAs(assistantAdminUser())
        ->test(AssistantChat::class)
        ->set('message', 'What is the status of ORD-TEST?')
        ->call('sendMessage')
        ->assertSet('error', '')
        ->assertSet('isLoading', false);

    $messages = $component->get('messages');
    $assistantMessages = array_values(array_filter(
        $messages,
        fn (array $message): bool => ($message['role'] ?? '') === 'assistant' && filled($message['content'] ?? null)
    ));

    expect($assistantMessages)->not->toBeEmpty();
    expect($assistantMessages[0]['content'])->toBe('The order is paid.');

    OpsAssistant::assertPrompted('What is the status of ORD-TEST?');
});

it('executes tool call through the agent', function (): void {
    OpsAssistant::fake([
        new ToolCall('call_123', 'lookup_order', ['order_number' => 'ORD-TEST-001']),
        'Here is the order info.',
    ]);

    $component = Livewire::actingAs(assistantAdminUser())
        ->test(AssistantChat::class)
        ->set('message', 'Look up ORD-TEST-001')
        ->call('sendMessage')
        ->assertSet('error', '')
        ->assertSet('isLoading', false);

    $messages = $component->get('messages');
    $contents = collect($messages)
        ->filter(fn (array $message): bool => ($message['role'] ?? '') === 'assistant' && filled($message['content'] ?? null))
        ->pluck('content')
        ->all();

    expect($contents)->toContain('Here is the order info.');

    OpsAssistant::assertPrompted('Look up ORD-TEST-001');
});

it('shows quota exceeded message when agent reports insufficient credits', function (): void {
    OpsAssistant::fake([
        fn () => throw InsufficientCreditsException::forProvider('openai'),
    ]);

    Livewire::actingAs(assistantAdminUser())
        ->test(AssistantChat::class)
        ->set('message', 'Hello')
        ->call('sendMessage')
        ->assertSet('error', __('messages.assistant_openai_quota_exceeded'))
        ->assertSet('isLoading', false);
});

it('restores chat history from conversation store after remount', function (): void {
    OpsAssistant::fake(['Order is paid.']);

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
