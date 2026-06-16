<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Ai\Agents\OpsAssistant;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Masmerise\Toaster\Toastable;
use Throwable;

#[Layout('layouts.app')]
final class AssistantChat extends Component
{
    use Toastable;

    public string $message = '';

    /** @var list<array{role: string, content: string}> */
    public array $messages = [];

    public bool $isLoading = false;

    public string $error = '';

    public ?string $conversationId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $this->conversationId = session()->get($this->conversationSessionKey());

        if (is_string($this->conversationId) && $this->conversationId !== '') {
            $this->syncDisplayMessages();
        }

        if (! $this->openAiConfigured()) {
            $this->error = __('messages.assistant_openai_not_configured');
        }
    }

    public function sendMessage(): void
    {
        $this->validate(['message' => ['required', 'string', 'max:2000']]);

        if (! $this->openAiConfigured()) {
            $this->error = __('messages.assistant_openai_not_configured');

            return;
        }

        $user = auth()->user();
        $prompt = $this->message;
        $this->message = '';
        $this->error = '';
        $this->isLoading = true;

        try {
            $agent = OpsAssistant::make();

            if (is_string($this->conversationId) && $this->conversationId !== '') {
                $agent->continue($this->conversationId, $user);
            } else {
                $agent->forUser($user);
            }

            $response = $agent->prompt($prompt);

            if (is_string($response->conversationId) && $response->conversationId !== '') {
                $this->conversationId = $response->conversationId;
                session()->put($this->conversationSessionKey(), $this->conversationId);
            }

            $this->syncDisplayMessages();
        } catch (InsufficientCreditsException|RateLimitedException $exception) {
            $this->error = __('messages.assistant_openai_quota_exceeded');
            $this->logAiFailure($exception);
        } catch (AiException $exception) {
            $this->error = $this->resolveAiExceptionMessage($exception);
            $this->logAiFailure($exception);
        } catch (RequestException $exception) {
            $this->error = $this->resolveRequestExceptionMessage($exception);
            $this->logAiFailure($exception);
        } catch (Throwable $exception) {
            $this->error = __('messages.assistant_openai_error');
            $this->logAiFailure($exception);
        } finally {
            $this->isLoading = false;
        }
    }

    public function clearChat(): void
    {
        $this->messages = [];
        $this->message = '';
        $this->error = '';
        $this->isLoading = false;
        $this->conversationId = null;
        session()->forget($this->conversationSessionKey());
    }

    private function openAiConfigured(): bool
    {
        $key = config('ai.providers.openai.key') ?? config('services.openai.key');

        return filled($key);
    }

    private function conversationSessionKey(): string
    {
        return 'ops_assistant.conversation.'.auth()->id();
    }

    private function syncDisplayMessages(): void
    {
        if (! is_string($this->conversationId) || $this->conversationId === '') {
            $this->messages = [];

            return;
        }

        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        $this->messages = DB::table($table)
            ->where('conversation_id', $this->conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(static fn (object $record): array => [
                'role' => (string) $record->role,
                'content' => (string) $record->content,
            ])
            ->filter(static fn (array $message): bool => $message['role'] === 'user' || filled($message['content']))
            ->values()
            ->all();
    }

    private function resolveAiExceptionMessage(AiException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'invalid') && str_contains($message, 'key')) {
            return __('messages.assistant_openai_invalid_key');
        }

        if (str_contains($message, 'model')) {
            return __('messages.assistant_openai_model_not_found', [
                'model' => config('ai.models.text', 'gpt-4o-mini'),
            ]);
        }

        return __('messages.assistant_openai_error');
    }

    private function resolveRequestExceptionMessage(RequestException $exception): string
    {
        $response = $exception->response;
        $apiCode = (string) ($response?->json('error.code') ?? '');
        $apiType = (string) ($response?->json('error.type') ?? '');
        $apiMessage = (string) ($response?->json('error.message') ?? '');
        $status = $response?->status();

        if ($status === 429 || $apiCode === 'insufficient_quota' || $apiType === 'insufficient_quota') {
            return __('messages.assistant_openai_quota_exceeded');
        }

        if ($status === 401 || $apiCode === 'invalid_api_key') {
            return __('messages.assistant_openai_invalid_key');
        }

        if ($status === 404 || $apiCode === 'model_not_found') {
            return __('messages.assistant_openai_model_not_found', [
                'model' => config('ai.models.text', 'gpt-4o-mini'),
            ]);
        }

        if ($status === 403 && str_contains(strtolower($apiMessage), 'model')) {
            return __('messages.assistant_openai_model_not_found', [
                'model' => config('ai.models.text', 'gpt-4o-mini'),
            ]);
        }

        return __('messages.assistant_openai_error');
    }

    private function logAiFailure(Throwable $exception): void
    {
        Log::warning('Ops assistant AI request failed', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.admin.assistant-chat');
    }
}
